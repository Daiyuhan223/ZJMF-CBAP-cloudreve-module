<?php

namespace server\cloudreve_group;

use server\cloudreve_group\logic\CloudreveApi;
use server\cloudreve_group\model\CustomCycleModel;
use server\cloudreve_group\model\PlusUsersModel;
use think\facade\Db;

/**
 * Cloudreve 用户组管理模块
 *
 * 用户在魔方购买不同等级云盘套餐后，自动调用 Cloudreve 管理 API 切换用户组。
 * 到期未续费自动降级；同一用户可持有多个套餐，始终保持生效最高等级。
 *
 * 接口配置使用魔方 server 表标准字段：
 *   url      → Cloudreve 站点地址
 *   username → 管理员邮箱
 *   password → 管理员密码
 */
class CloudreveGroup
{
    /**
     * 降级时使用的默认用户组数字 ID（Cloudreve 初始默认用户组通常为 2）
     * 注意：Cloudreve 数字组ID 中 1 为初始管理员组，请勿把普通用户降级到管理员组
     */
    const BASIC_GROUP_ID = 2;

    /**
     * 商品ID → 用户组配置兜底映射
     * 优先从 idcsmart_module_cloudreve_group_map 表读取，未配置时回退到此处
     * 格式：商品ID => ['group_id' => 目标组ID, 'level' => 等级权重]
     */
    const DEFAULT_GROUP_MAP = [
        24 => ['group_id' => 2, 'level' => 10],   // 淮壹网盘月度会员Pro
    ];

    /**
     * 返回模块元数据
     */
    public function metaData()
    {
        return [
            'display_name' => lang_plugins('cloudreve_group.display_name'),
            'version'      => lang_plugins('cloudreve_group.version'),
            'type'         => 'server',
        ];
    }

    /**
     * 接口配置项定义
     */
    public function ConfigOptions()
    {
        return [];
    }

    /**
     * 测试连接
     */
    public function testConnect($param)
    {
        $server   = $param['server'];
        $url      = $server['url'] ?? '';
        $username = $server['username'] ?? '';
        $password = $server['password'] ?? '';

        if (empty($url) || empty($username) || empty($password)) {
            return ['status' => 400, 'msg' => '接口配置不完整（URL/用户名/密码）'];
        }

        try {
            $api = new CloudreveApi($url, $username, $password);
            $api->getToken();
            return ['status' => 200, 'msg' => lang_plugins('cloudreve_group.connect_success')];
        } catch (\Exception $e) {
            return ['status' => 400, 'msg' => lang_plugins('cloudreve_group.connect_failed') . ': ' . $e->getMessage()];
        }
    }

    /**
     * 商品ID → 用户组配置映射
     * 数据库（后台 serverConfigOption 维护）优先，未配置的商品回退到 DEFAULT_GROUP_MAP
     */
    private function getGroupMap()
    {
        $map = self::DEFAULT_GROUP_MAP;

        $rows = Db::name('module_cloudreve_group_map')->select();
        foreach ($rows as $row) {
            $map[(int)$row['product_id']] = [
                'group_id' => (int)$row['group_id'],
                'level'    => (int)$row['group_level'],
            ];
        }

        return $map;
    }

    /**
     * 从 host 中读取 Cloudreve 邮箱
     * custom_fields 可能是数组（框架解码后）也可能是 JSON 字符串（原始存储），此处兼容
     *
     * @param array $host host 数据
     * @return string
     */
    private function getCloudreveEmail($host)
    {
        $customFields = $host['custom_fields'] ?? '';
        if (is_string($customFields)) {
            $customFields = json_decode($customFields, true) ?: [];
        }
        if (!is_array($customFields)) {
            $customFields = [];
        }

        return $customFields['cloudreve_email'] ?? '';
    }

    /**
     * 保存产品邮箱到模块自己的表（host 表无 custom_fields 列）
     *
     * @param int    $hostId 产品ID
     * @param string $email  Cloudreve邮箱
     */
    private function saveHostEmail($hostId, $email)
    {
        $hostId = (int)$hostId;
        if ($hostId <= 0 || empty($email)) {
            return;
        }

        try {
            $now = time();
            $exists = Db::name('module_cloudreve_group_host_email')
                ->where('host_id', $hostId)
                ->find();

            if ($exists) {
                Db::name('module_cloudreve_group_host_email')
                    ->where('id', $exists['id'])
                    ->update(['email' => $email, 'update_time' => $now]);
            } else {
                Db::name('module_cloudreve_group_host_email')
                    ->insert(['host_id' => $hostId, 'email' => $email, 'create_time' => $now, 'update_time' => $now]);
            }
        } catch (\Exception $e) {
            // 表不存在等异常不影响主流程
        }
    }

    /**
     * 按产品ID读取邮箱（先查模块表，再回退 host.custom_fields）
     *
     * @param int $hostId 产品ID
     * @return string
     */
    private function getHostEmail($hostId)
    {
        $hostId = (int)$hostId;
        if ($hostId <= 0) {
            return '';
        }

        try {
            $row = Db::name('module_cloudreve_group_host_email')
                ->where('host_id', $hostId)
                ->find();
            if (!empty($row['email'])) {
                return $row['email'];
            }
        } catch (\Exception $e) {
        }

        return '';
    }

    /**
     * 从框架"商品自定义字段（self_defined_field）"中读取邮箱
     * 字段命名约定：后台给商品配置的自定义字段，名称需包含 cloudreve / 邮箱 / email
     *
     * @param int $productId 商品ID
     * @param int $hostId    产品ID
     * @return string
     */
    private function getEmailFromSelfDefinedField($productId, $hostId)
    {
        $productId = (int)$productId;
        $hostId    = (int)$hostId;
        if ($productId <= 0 || $hostId <= 0) {
            return '';
        }

        try {
            $model = new \app\common\model\SelfDefinedFieldModel();
            $data  = $model->getHostListSelfDefinedFieldValue([
                'product_id' => [$productId],
                'host_id'    => [$hostId],
            ]);

            $fields = $data['self_defined_field'] ?? [];
            $values = $data['self_defined_field_value'][$hostId] ?? [];

            foreach ($fields as $field) {
                $fieldId = (int)($field['id'] ?? 0);
                $name    = mb_strtolower((string)($field['field_name'] ?? $field['name'] ?? ''));
                if ($fieldId <= 0 || !isset($values[$fieldId])) {
                    continue;
                }
                if ($name === 'cloudreve_email'
                    || mb_strpos($name, 'cloudreve') !== false
                    || mb_strpos($name, '邮箱') !== false
                    || mb_strpos($name, 'email') !== false) {
                    return (string)$values[$fieldId];
                }
            }
        } catch (\Exception $e) {
        }

        return '';
    }

    /**
     * 综合解析 host 的 Cloudreve 邮箱
     * 来源优先级：模块邮箱表 → 框架商品自定义字段 → host.custom_fields
     *
     * @param array $host host 数据（至少含 id / product_id）
     * @return string
     */
    private function resolveEmail($host)
    {
        $hostId    = (int)($host['id'] ?? 0);
        $productId = (int)($host['product_id'] ?? 0);

        $email = $this->getHostEmail($hostId);
        if (empty($email)) {
            $email = $this->getEmailFromSelfDefinedField($productId, $hostId);
        }
        if (empty($email)) {
            $email = $this->getCloudreveEmail($host);
        }

        return $email;
    }

    /**
     * 产品开通
     */
    public function createAccount($param)
    {
        try {
            $host    = $param['host'];
            $product = $param['product'];
            $server  = $param['server'];

            // 获取用户邮箱（优先模块表，其次 host.custom_fields）
            $hostId = isset($host['id']) ? (int)$host['id'] : 0;
            $email = $this->resolveEmail($host);
            if (empty($email)) {
                return ['status' => 400, 'msg' => lang_plugins('cloudreve_group.email_required')];
            }

            // 从映射表获取用户组配置
            $productId = $product['id'] ?? 0;
            $groupMap = $this->getGroupMap();
            if (!isset($groupMap[$productId])) {
                return ['status' => 400, 'msg' => '该商品未配置用户组映射，请联系管理员'];
            }
            $targetGroupId = $groupMap[$productId]['group_id'];
            $groupLevel    = $groupMap[$productId]['level'];

            $expireDate = 0;
            if (!empty($host['nextduedate'])) {
                $expireDate = is_numeric($host['nextduedate'])
                    ? (int)$host['nextduedate']
                    : strtotime($host['nextduedate']);
            }

            $api = new CloudreveApi(
                $server['url'] ?? '',
                $server['username'] ?? '',
                $server['password'] ?? ''
            );

            $model = new PlusUsersModel();

            // 最高等级判断：仅当新等级 >= 当前最高等级时才切换用户组
            $currentMaxLevel = $model->getMaxLevelByEmail($email);

            if ($groupLevel >= $currentMaxLevel) {
                $api->setUserGroup($email, $targetGroupId);
            }

            // 删除该 host_id 已有记录
            $model->deleteByHostId($hostId);

            // 查找用户 uid
            $user = $api->getUserByEmail($email);
            $uid  = $user ? ($user['id'] ?? 0) : 0;

            // 插入新记录
            $model->addRecord([
                'uid'         => $uid,
                'email'       => $email,
                'group_id'    => $targetGroupId,
                'group_level' => $groupLevel,
                'expire_date' => $expireDate,
                'host_id'     => $hostId,
                'product_id'  => $host['product_id'] ?? 0,
            ]);

            return ['status' => 200, 'msg' => lang_plugins('cloudreve_group.open_success')];
        } catch (\Exception $e) {
            return ['status' => 400, 'msg' => lang_plugins('cloudreve_group.api_error') . ': ' . $e->getMessage()];
        }
    }

    /**
     * 产品暂停
     */
    public function suspendAccount($param)
    {
        try {
            $host   = $param['host'];
            $server = $param['server'];

            $hostId = isset($host['id']) ? (int)$host['id'] : 0;
            $email = $this->resolveEmail($host);

            $api = new CloudreveApi(
                $server['url'] ?? '',
                $server['username'] ?? '',
                $server['password'] ?? ''
            );

            $model = new PlusUsersModel();

            // 删除该 host_id 记录
            $model->deleteByHostId($hostId);

            // 降级处理
            $this->downgradeUserGroup($email, $api, $model);

            return ['status' => 200, 'msg' => lang_plugins('cloudreve_group.suspend_success')];
        } catch (\Exception $e) {
            return ['status' => 400, 'msg' => lang_plugins('cloudreve_group.api_error') . ': ' . $e->getMessage()];
        }
    }

    /**
     * 产品解除暂停
     */
    public function unsuspendAccount($param)
    {
        $result = $this->createAccount($param);

        if ($result['status'] === 200) {
            $result['msg'] = lang_plugins('cloudreve_group.unsuspend_success');
        }

        return $result;
    }

    /**
     * 产品删除
     */
    public function terminateAccount($param)
    {
        $result = $this->suspendAccount($param);

        if ($result['status'] === 200) {
            $result['msg'] = lang_plugins('cloudreve_group.terminate_success');
        }

        return $result;
    }

    /**
     * 产品续费
     */
    public function renew($param)
    {
        try {
            $host   = $param['host'];
            $hostId = isset($host['id']) ? (int)$host['id'] : 0;

            $expireDate = 0;
            if (!empty($host['nextduedate'])) {
                $expireDate = is_numeric($host['nextduedate'])
                    ? (int)$host['nextduedate']
                    : strtotime($host['nextduedate']);
            }

            if ($hostId > 0 && $expireDate > 0) {
                Db::name('module_cloudreve_group_plus_users')
                    ->where('host_id', $hostId)
                    ->update([
                        'expire_date' => $expireDate,
                        'update_time' => time(),
                    ]);
            }

            return ['status' => 200, 'msg' => lang_plugins('cloudreve_group.renew_success')];
        } catch (\Exception $e) {
            return ['status' => 400, 'msg' => lang_plugins('cloudreve_group.api_error') . ': ' . $e->getMessage()];
        }
    }

    /**
     * 升降级商品后调用：按新商品重新应用用户组映射
     */
    public function changeProduct($param)
    {
        return $this->createAccount($param);
    }

    /**
     * 升降级配置项后调用
     */
    public function changePackage($param)
    {
        return ['status' => 200, 'msg' => 'success'];
    }

    /**
     * 结算之后调用：保存模块自定义参数（Cloudreve 邮箱）到产品
     */
    public function afterSettle($param)
    {
        $hostId       = (int)($param['host_id'] ?? 0);
        $custom       = $param['custom'] ?? [];
        $customfields = $param['customfields'] ?? [];
        $customfield  = $param['customfield'] ?? [];

        // 兼容多种来源：custom / customfields / customfield / 嵌套 custom.customfield
        $email = $custom['cloudreve_email']
            ?? $customfields['cloudreve_email']
            ?? $customfield['cloudreve_email']
            ?? $custom['customfield']['cloudreve_email']
            ?? $custom['custom_fields']['cloudreve_email']
            ?? '';

        if ($hostId <= 0 || empty($email)) {
            return;
        }

        // host 表无 custom_fields 列，邮箱存入模块自己的表
        $this->saveHostEmail($hostId, $email);
    }

    /**
     * 前台产品列表
     */
    public function hostList($param)
    {
        $productIds = $param['product_id'] ?? [];
        $clientId   = get_client_id();
        $hosts      = [];

        if ($clientId > 0 && !empty($productIds)) {
            $hosts = Db::name('host')->alias('h')
                ->leftJoin('__PREFIX__product p', 'p.id = h.product_id')
                ->where('h.client_id', $clientId)
                ->whereIn('h.product_id', $productIds)
                ->field('h.*, p.name as product_name')
                ->order('h.id', 'desc')
                ->select()
                ->toArray();
        }

        return [
            'template' => 'template/clientarea/product_list.html',
            'vars'     => ['hosts' => $hosts],
        ];
    }

    /**
     * 前台购买页面
     */
    public function clientProductConfigOption($params)
    {
        $PluginModel = new \app\admin\model\PluginModel();
        $addons      = $PluginModel->plugins('addon');

        if (use_mobile()) { // 手机端
            $mobileTheme = configuration('cart_theme_mobile');
            if (!file_exists(__DIR__ . "/template/cart/mobile/{$mobileTheme}/goods.html")) {
                $mobileTheme = "default";
            }
            $res = [
                'vars' => [
                    'template_catalog' => 'clientarea',
                    'themes'           => 'mobile/' . configuration('clientarea_theme'),
                    'addons'           => $addons['list'],
                ],
                'template' => "template/cart/mobile/{$mobileTheme}/goods.html",
            ];
        } else { // pc 端
            $cartTheme = configuration('cart_theme');
            if (!file_exists(__DIR__ . "/template/cart/pc/{$cartTheme}/goods.html")) {
                $cartTheme = "default";
            }
            $res = [
                'vars' => [
                    'template_catalog' => 'clientarea',
                    'themes'           => 'pc/' . configuration('clientarea_theme'),
                    'addons'           => $addons['list'],
                ],
                'template' => "template/cart/pc/{$cartTheme}/goods.html",
            ];
        }

        return $res;
    }

    /**
     * 前台产品详情页
     */
    public function clientArea($param)
    {
        $host  = $param['host'] ?? [];
        $email = $this->resolveEmail($host);

        $groupInfo = null;
        if ($email) {
            $groupInfo = Db::name('module_cloudreve_group_plus_users')
                ->where('email', $email)
                ->order('group_level', 'desc')
                ->find();
        }

        return [
            'template' => 'template/clientarea/product_detail.html',
            'vars'     => ['host' => $host, 'cloudreve_email' => $email, 'group_info' => $groupInfo],
        ];
    }

    /**
     * 后台产品内页
     */
    public function adminArea($param)
    {
        $host  = $param['host'] ?? [];
        $email = $this->resolveEmail($host);

        $groupInfo = null;
        if ($email) {
            $groupInfo = Db::name('module_cloudreve_group_plus_users')
                ->where('email', $email)
                ->order('group_level', 'desc')
                ->find();
        }

        return [
            'template' => 'template/admin/product_detail.html',
            'vars'     => ['host' => $host, 'cloudreve_email' => $email, 'group_info' => $groupInfo],
        ];
    }

    /**
     * 前台登录 Cloudreve（自定义方法，走 custom/provision，func=login）
     */
    public function login($param)
    {
        try {
            $host   = $param['host'] ?? [];
            $server = $param['server'] ?? [];

            $email = $this->resolveEmail($host);
            if (empty($email)) {
                return ['status' => 400, 'msg' => lang_plugins('cloudreve_group.email_required')];
            }

            $api = new CloudreveApi(
                $server['url'] ?? '',
                $server['username'] ?? '',
                $server['password'] ?? ''
            );

            $user = $api->getUserByEmail($email);
            if (!$user) {
                return ['status' => 400, 'msg' => lang_plugins('cloudreve_group.login_user_not_found')];
            }

            $loginUrl = $api->generateLoginToken($user['id']);
            if (empty($loginUrl)) {
                return ['status' => 400, 'msg' => lang_plugins('cloudreve_group.login_generate_failed')];
            }

            return ['status' => 200, 'msg' => lang_plugins('cloudreve_group.login_success'), 'data' => ['login_url' => $loginUrl]];
        } catch (\Exception $e) {
            return ['status' => 400, 'msg' => lang_plugins('cloudreve_group.api_error') . ': ' . $e->getMessage()];
        }
    }

    /**
     * 购物车价格计算
     * 组装 config_options 调系统价格引擎 ProductModel::productCalculatePrice
     */
    public function cartCalculatePrice($param)
    {
        $product   = $param['product'] ?? [];
        $productId = (int)($product['id'] ?? 0);

        if ($productId <= 0) {
            return ['status' => 400, 'msg' => '商品信息不完整'];
        }

        $custom = $param['custom'] ?? [];
        $qty    = max(1, (int)($param['qty'] ?? 1));
        $cycleId= (int)($custom['cycle'] ?? 0);

        // 按自定义周期算价
        $model = new CustomCycleModel();
        $cycle = $model->findCycle($productId, $cycleId);

        // 兼容未配置周期时的兜底（月付，价格取商品起售价）
        if (empty($cycle)) {
            $cycle = [
                'id'         => 0,
                'name'       => '月付',
                'cycle_time' => 1,
                'cycle_unit' => 'month',
                'amount'     => (float)($product['price'] ?? 0),
            ];
        }

        $price    = (float)($cycle['amount'] ?? 0);
        $duration = $model->cycleTimeToSeconds($cycle['cycle_time'] ?? 1, $cycle['cycle_unit'] ?? 'month');
        $cycleName = $cycle['name'] ?? '';

        $total      = round($price * $qty, 2);
        $name       = $product['name'] ?? 'Cloudreve 套餐';

        $data = [
            'price'                    => $total,
            'renew_price'              => $price,
            'billing_cycle'            => $cycleName,
            'host_billing_cycle'       => $cycleName,
            'duration'                 => $duration,
            'description'              => $name . ' - ' . $cycleName,
            'base_price'               => $price,
            'price_total'              => $total,
            'renew_price_total'        => $total,
            'preview'                  => [[
                'name'  => $name,
                'value' => $cycleName,
                'price' => $total,
            ]],
            'due_time'                 => $duration > 0 ? time() + $duration : 0,
        ];

        return ['status' => 200, 'msg' => '请求成功', 'data' => $data];
    }

    /**
     * 获取商品起售周期价格
     * 从系统商品 pricing（周期价格 JSON）取最低有效价格
     */
    public function getPriceCycle($param)
    {
        $product   = $param['product'] ?? [];
        $productId = (int)($product['id'] ?? 0);

        $model = new CustomCycleModel();
        $cycles = $model->lists($productId);

        if (empty($cycles)) {
            return ['price' => (float)($product['price'] ?? 0), 'cycle' => '月付'];
        }

        $bestPrice = 0;
        $bestCycle = '';
        foreach ($cycles as $cycle) {
            $price = (float)($cycle['amount'] ?? 0);
            if ($price > 0 && ($bestPrice == 0 || $price < $bestPrice)) {
                $bestPrice = $price;
                $bestCycle = $cycle['name'] ?? '';
            }
        }

        return ['price' => $bestPrice, 'cycle' => $bestCycle];
    }

    /**
     * 获取当前产品所有周期价格（续费时使用）
     * 从自定义周期表读取
     */
    public function durationPrice($param)
    {
        $host      = $param['host'] ?? [];
        $productId = (int)($host['product_id'] ?? 0);

        $model  = new CustomCycleModel();
        $cycles = $model->lists($productId);

        $data = [];
        foreach ($cycles as $cycle) {
            $duration = $model->cycleTimeToSeconds($cycle['cycle_time'] ?? 1, $cycle['cycle_unit'] ?? 'month');
            if ($duration <= 0) {
                continue; // 无限期周期不参与续费
            }
            $data[] = [
                'duration'      => $duration,
                'billing_cycle' => $cycle['name'] ?? '',
                'price'         => (float)($cycle['amount'] ?? 0),
            ];
        }

        return [
            'status' => 200,
            'msg'    => '请求成功',
            'data'   => $data,
        ];
    }

    /**
     * 后台接口配置输出（位置：后台-商品管理-商品内页-接口管理）
     * 用于维护当前商品的用户组映射
     */
    public function serverConfigOption($param)
    {
        $product   = $param['product'] ?? [];
        $productId = (int)($product['id'] ?? 0);

        try {
            $groupMap = Db::name('module_cloudreve_group_map')
                ->where('product_id', $productId)
                ->order('id', 'asc')
                ->select()
                ->toArray();
        } catch (\Exception $e) {
            $groupMap = [];
        }

        $cycleModel = new CustomCycleModel();
        $cycles     = $cycleModel->lists($productId);

        return [
            'template' => 'template/admin/group_map.html',
            'vars'     => ['product' => $product, 'group_map' => $groupMap, 'cycles' => $cycles],
        ];
    }

    /**
     * 第一次创建本模块接口时调用，用于建表
     */
    public function afterCreateFirstServer()
    {
        $sqls = [
            // 用户记录表
            "
            CREATE TABLE IF NOT EXISTS `idcsmart_module_cloudreve_group_plus_users` (
                `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
                `uid` int(11) NOT NULL DEFAULT 0 COMMENT 'Cloudreve用户ID',
                `email` varchar(128) NOT NULL DEFAULT '' COMMENT 'Cloudreve邮箱',
                `group_id` int(11) NOT NULL DEFAULT 0 COMMENT '用户组ID',
                `group_level` int(11) NOT NULL DEFAULT 0 COMMENT '优先级权重',
                `expire_date` int(11) NOT NULL DEFAULT 0 COMMENT '到期时间',
                `host_id` int(11) NOT NULL DEFAULT 0 COMMENT '产品实例ID',
                `product_id` int(11) NOT NULL DEFAULT 0 COMMENT '商品ID',
                `create_time` int(11) NOT NULL DEFAULT 0 COMMENT '创建时间',
                `update_time` int(11) NOT NULL DEFAULT 0 COMMENT '更新时间',
                PRIMARY KEY (`id`),
                KEY `idx_email` (`email`),
                KEY `idx_host_id` (`host_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Cloudreve套餐用户记录';
            ",
            // 商品用户组映射表
            "
            CREATE TABLE IF NOT EXISTS `idcsmart_module_cloudreve_group_map` (
                `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
                `product_id` int(11) unsigned NOT NULL COMMENT '商品ID',
                `group_id` int(11) NOT NULL DEFAULT 0 COMMENT '目标用户组ID',
                `group_level` int(11) NOT NULL DEFAULT 0 COMMENT '优先级权重',
                `create_time` int(11) NOT NULL DEFAULT 0,
                `update_time` int(11) NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                KEY `idx_product_id` (`product_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='cloudreve_group商品用户组映射';
            ",
            // 自定义周期表
            "
            CREATE TABLE IF NOT EXISTS `idcsmart_module_cloudreve_group_custom_cycle` (
                `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
                `product_id` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '商品ID',
                `name` varchar(50) NOT NULL DEFAULT '' COMMENT '周期名称',
                `cycle_time` int(11) NOT NULL DEFAULT 1 COMMENT '周期时长',
                `cycle_unit` varchar(10) NOT NULL DEFAULT 'month' COMMENT '单位：hour/day/month/year',
                `cycle_type` tinyint(1) NOT NULL DEFAULT 0 COMMENT '周期类型(0=普通,1=自然月)',
                `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态(0=禁用,1=启用)',
                `create_time` int(11) NOT NULL DEFAULT 0,
                `update_time` int(11) NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                KEY `idx_product_id` (`product_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='cloudreve_group自定义周期';
            ",
            // 自定义周期价格表
            "
            CREATE TABLE IF NOT EXISTS `idcsmart_module_cloudreve_group_custom_cycle_pricing` (
                `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
                `custom_cycle_id` int(11) NOT NULL DEFAULT 0 COMMENT '周期ID',
                `rel_id` int(11) NOT NULL DEFAULT 0 COMMENT '关联ID(商品ID)',
                `type` varchar(20) NOT NULL DEFAULT 'product' COMMENT '类型：product/configoption',
                `amount` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '周期金额',
                `create_time` int(11) NOT NULL DEFAULT 0,
                `update_time` int(11) NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                KEY `idx_custom_cycle_id` (`custom_cycle_id`),
                KEY `idx_rel_id` (`rel_id`, `type`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='cloudreve_group自定义周期价格';
            ",
            // 产品邮箱记录表（host 表无 custom_fields 列，邮箱单独存）
            "
            CREATE TABLE IF NOT EXISTS `idcsmart_module_cloudreve_group_host_email` (
                `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
                `host_id` int(11) NOT NULL DEFAULT 0 COMMENT '产品ID',
                `email` varchar(128) NOT NULL DEFAULT '' COMMENT 'Cloudreve邮箱',
                `create_time` int(11) NOT NULL DEFAULT 0,
                `update_time` int(11) NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                UNIQUE KEY `idx_host_id` (`host_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='cloudreve_group产品邮箱记录';
            ",
        ];

        foreach ($sqls as $sql) {
            Db::execute($sql);
        }
    }

    /**
     * 删除最后一个本模块接口时调用，用于删表
     */
    public function afterDeleteLastServer()
    {
        $sqls = [
            "DROP TABLE IF EXISTS `idcsmart_module_cloudreve_group_plus_users`",
            "DROP TABLE IF EXISTS `idcsmart_module_cloudreve_group_map`",
            "DROP TABLE IF EXISTS `idcsmart_module_cloudreve_group_custom_cycle`",
            "DROP TABLE IF EXISTS `idcsmart_module_cloudreve_group_custom_cycle_pricing`",
            "DROP TABLE IF EXISTS `idcsmart_module_cloudreve_group_host_email`",
        ];

        foreach ($sqls as $sql) {
            Db::execute($sql);
        }
    }

    // -------------------------------------------------------------------------
    // 私有辅助方法
    // -------------------------------------------------------------------------

    /**
     * 降级用户组
     * 查询该邮箱剩余有效记录，取最高等级 group_id 设定；若无有效记录则设为基础组
     */
    private function downgradeUserGroup($email, $api, $model)
    {
        if (empty($email)) {
            return;
        }

        $activeRecords = $model->getActiveRecordsByEmail($email);

        if (!empty($activeRecords)) {
            $highestGroupId = (int)$activeRecords[0]['group_id'];
            if ($highestGroupId > 0) {
                $api->setUserGroup($email, $highestGroupId);
            }
        } else {
            $basicGroupId = self::BASIC_GROUP_ID;
            if ($basicGroupId > 0) {
                $api->setUserGroup($email, $basicGroupId);
            }
        }
    }
}


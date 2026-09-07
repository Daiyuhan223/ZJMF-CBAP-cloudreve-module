<?php

namespace server\cloudreve_group\controller\home;

use app\event\controller\BaseController;
use server\cloudreve_group\logic\CloudreveApi;
use server\cloudreve_group\model\PlusUsersModel;
use think\facade\Db;

/**
 * 定时任务控制器
 * 每日检查过期套餐记录，自动降级用户组
 */
class CronController extends BaseController
{
    public function initialize()
    {
        parent::initialize();
        app('http')->name('home');
    }
    /**
     * 执行过期套餐检查与降级
     * 1. 从接口配置表中读取 Cloudreve 连接参数
     * 2. 查询所有过期记录，按邮箱分组
     * 3. 逐邮箱：删除过期记录 → 查最高有效等级 → 设置用户组（或降为基础组）
     *
     * @return \think\response\Json
     */
    public function check()
    {
        try {
            $serverConfig = $this->loadServerConfig();

            if (empty($serverConfig)) {
                return json([
                    'status' => 400,
                    'msg'    => lang_plugins('cloudreve_group.cron_no_config'),
                ]);
            }

            $baseUrl       = $serverConfig['cloudreve_url'] ?? '';
            $adminEmail    = $serverConfig['admin_email'] ?? '';
            $adminPassword = $serverConfig['admin_password'] ?? '';
            $basicGroupId  = isset($serverConfig['basic_group_id']) ? (int)$serverConfig['basic_group_id'] : 0;

            if (empty($baseUrl)) {
                return json([
                    'status' => 400,
                    'msg'    => lang_plugins('cloudreve_group.cron_no_config'),
                ]);
            }

            $api   = new CloudreveApi($baseUrl, $adminEmail, $adminPassword);
            $model = new PlusUsersModel();

            $expiredRecords = $model->getExpiredRecords();

            if (empty($expiredRecords)) {
                return json([
                    'status' => 200,
                    'msg'    => lang_plugins('cloudreve_group.cron_no_expired'),
                ]);
            }

            // 按邮箱分组
            $grouped = [];
            foreach ($expiredRecords as $record) {
                $email = $record['email'];
                if (!isset($grouped[$email])) {
                    $grouped[$email] = [];
                }
                $grouped[$email][] = $record;
            }

            $processedCount = 0;
            $downgradeLog   = [];

            foreach ($grouped as $email => $records) {
                foreach ($records as $record) {
                    $model->deleteByEmailAndHostId($email, $record['host_id']);
                    $processedCount++;
                }

                $activeRecords = $model->getActiveRecordsByEmail($email);

                if (!empty($activeRecords)) {
                    $highestGroupId = (int)$activeRecords[0]['group_id'];
                    $api->setUserGroup($email, $highestGroupId);
                    $downgradeLog[] = [
                        'email'    => $email,
                        'group_id' => $highestGroupId,
                        'action'   => 'remain_active',
                    ];
                } else {
                    if ($basicGroupId > 0) {
                        $api->setUserGroup($email, $basicGroupId);
                    }
                    $downgradeLog[] = [
                        'email'    => $email,
                        'group_id' => $basicGroupId,
                        'action'   => 'fallback_basic',
                    ];
                }
            }

            return json([
                'status' => 200,
                'msg'    => str_replace('{count}', $processedCount, lang_plugins('cloudreve_group.cron_processed')),
                'data'   => [
                    'processed' => $processedCount,
                    'details'   => $downgradeLog,
                ],
            ]);
        } catch (\Exception $e) {
            return json([
                'status' => 400,
                'msg'    => lang_plugins('cloudreve_group.cron_task_error') . ': ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * 从数据库读取本模块的接口配置
     * 优先通过 module 字段匹配，其次尝试 type 字段
     *
     * @return array|null 配置数组，未找到返回 null
     */
    private function loadServerConfig()
    {
        $server = Db::name('server')
            ->where('module', 'cloudreve_group')
            ->find();

        if (empty($server)) {
            $server = Db::name('server')
                ->where('type', 'cloudreve_group')
                ->find();
        }

        if (empty($server)) {
            return null;
        }

        // 使用 server 表标准字段，映射为模块内部键名
        return [
            'cloudreve_url'  => $server['url'] ?? '',
            'admin_email'    => $server['username'] ?? '',
            'admin_password' => $server['password'] ?? '',
            'basic_group_id' => 2,
        ];
    }
}

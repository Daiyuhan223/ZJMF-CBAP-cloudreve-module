<?php

namespace server\cloudreve_group\controller\home;

use app\event\controller\BaseController;
use server\cloudreve_group\CloudreveGroup;
use think\facade\Db;

/**
 * 前台：产品接口
 * 路由：GET  /console/v1/cloudreve_group/host
 *      POST /console/v1/cloudreve_group/host/:host_id/custom/provision
 */
class HostController extends BaseController
{
    public function initialize()
    {
        parent::initialize();
        app('http')->name('home');
    }
    /**
     * 当前用户的本模块产品列表
     */
    public function lists()
    {
        $clientId = get_client_id();
        if ($clientId <= 0) {
            return json(['status' => 400, 'msg' => '请先登录']);
        }

        $page     = max(1, (int)request()->param('page', 1));
        $limit    = max(1, min(100, (int)request()->param('limit', 20)));
        $status   = (string)request()->param('status', '');
        $keywords = (string)request()->param('keywords', '');
        $orderby  = (string)request()->param('orderby', 'id');
        $sort     = (string)request()->param('sort', 'desc');

        $query = Db::name('host')->alias('h')
            ->leftJoin('__PREFIX__product p', 'p.id = h.product_id')
            ->field('h.*, p.name as product_name')
            ->where('h.client_id', $clientId)
            ->where('p.module', 'cloudreve_group');

        if ($status !== '') {
            $query->where('h.status', $status);
        }
        if ($keywords !== '') {
            $query->whereLike('p.name|h.name', '%' . $keywords . '%');
        }

        $count = $query->count();
        $list  = $query->order($orderby, $sort)->page($page, $limit)->select()->toArray();

        return json([
            'status' => 200,
            'data'   => [
                'list'  => $list,
                'count' => (int)$count,
                'limit' => $limit,
                'page'  => $page,
            ],
        ]);
    }

    /**
     * 执行模块自定义方法（func=login 一键登录 Cloudreve）
     */
    public function customProvision()
    {
        $hostId = (int)request()->param('host_id');
        $func   = (string)request()->param('func', '');

        if ($hostId <= 0 || $func === '') {
            return json(['status' => 400, 'msg' => '参数错误']);
        }

        $host = Db::name('host')->where('id', $hostId)->find();
        if (empty($host) || (int)$host['client_id'] !== get_client_id()) {
            return json(['status' => 400, 'msg' => '产品不存在']);
        }
        if ($host['status'] !== 'Active') {
            return json(['status' => 400, 'msg' => '产品未开通']);
        }

        // custom_fields 为 JSON 字符串，解码为数组供模块方法读取
        $host['custom_fields'] = json_decode($host['custom_fields'] ?? '{}', true) ?: [];

        $product = Db::name('product')->where('id', $host['product_id'])->find() ?: [];
        $server  = Db::name('server')->where('id', $host['server_id'])->find() ?: [];

        $module = new CloudreveGroup();
        if (!method_exists($module, $func)) {
            return json(['status' => 400, 'msg' => '方法不存在']);
        }

        $result = $module->$func([
            'host'    => $host,
            'product' => $product,
            'server'  => $server,
        ]);

        return json($result);
    }
}

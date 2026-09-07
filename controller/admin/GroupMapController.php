<?php

namespace server\cloudreve_group\controller\admin;

use app\event\controller\BaseController;
use think\facade\Db;

/**
 * 后台：商品用户组映射管理
 * 路由：DIR_ADMIN/v1/cloudreve_group/product/:product_id/group_map
 */
class GroupMapController extends BaseController
{
    public function initialize()
    {
        parent::initialize();
    }
    /**
     * 新增/更新商品用户组映射（每商品一条）
     *
     * @return \think\response\Json
     */
    public function create()
    {
        $productId  = (int)request()->param('product_id');
        $groupId    = (int)request()->param('group_id');
        $groupLevel = (int)request()->param('group_level');

        if ($productId <= 0 || $groupId <= 0) {
            return json(['status' => 400, 'msg' => '商品ID与用户组ID必填']);
        }

        try {
            $now    = time();
            $exists = Db::name('module_cloudreve_group_map')
                ->where('product_id', $productId)
                ->find();

            if ($exists) {
                Db::name('module_cloudreve_group_map')
                    ->where('id', $exists['id'])
                    ->update([
                        'group_id'    => $groupId,
                        'group_level' => $groupLevel,
                        'update_time' => $now,
                    ]);
            } else {
                Db::name('module_cloudreve_group_map')
                    ->insert([
                        'product_id'  => $productId,
                        'group_id'    => $groupId,
                        'group_level' => $groupLevel,
                        'create_time' => $now,
                        'update_time' => $now,
                    ]);
            }
        } catch (\Exception $e) {
            return json(['status' => 400, 'msg' => '保存失败：' . $e->getMessage()]);
        }

        return json(['status' => 200, 'msg' => '保存成功']);
    }

    /**
     * 删除商品用户组映射
     *
     * @return \think\response\Json
     */
    public function delete()
    {
        $id = (int)request()->param('id');

        if ($id <= 0) {
            return json(['status' => 400, 'msg' => '参数错误']);
        }

        try {
            Db::name('module_cloudreve_group_map')
                ->where('id', $id)
                ->delete();
        } catch (\Exception $e) {
            return json(['status' => 400, 'msg' => '删除失败：' . $e->getMessage()]);
        }

        return json(['status' => 200, 'msg' => '删除成功']);
    }
}

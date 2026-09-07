<?php

namespace server\cloudreve_group\controller\admin;

use app\event\controller\BaseController;
use server\cloudreve_group\model\CustomCycleModel;

/**
 * 后台：商品自定义周期管理
 * 路由：DIR_ADMIN/v1/cloudreve_group/product/:product_id/custom_cycle
 */
class CustomCycleController extends BaseController
{
    public function initialize()
    {
        parent::initialize();
    }
    /**
     * 周期列表
     */
    public function lists()
    {
        $productId = (int)request()->param('product_id');

        $model = new CustomCycleModel();

        return json([
            'status' => 200,
            'msg'    => '请求成功',
            'data'   => [
                'list' => $model->lists($productId),
            ],
        ]);
    }

    /**
     * 新增周期
     */
    public function create()
    {
        $productId = (int)request()->param('product_id');
        $name      = (string)request()->param('name', '');
        $num       = (int)request()->param('num', 1);
        $unit      = (string)request()->param('unit', 'month');
        $price     = (float)request()->param('price', 0);

        if ($productId <= 0 || $name === '') {
            return json(['status' => 400, 'msg' => '周期名称必填']);
        }
        if ($num <= 0) {
            return json(['status' => 400, 'msg' => '周期时长必须大于0']);
        }
        if (!in_array($unit, ['hour', 'day', 'month', 'year'], true)) {
            return json(['status' => 400, 'msg' => '周期单位不合法']);
        }

        try {
            $model = new CustomCycleModel();
            $id    = $model->create($productId, ['name' => $name, 'num' => $num, 'unit' => $unit, 'price' => $price]);
        } catch (\Exception $e) {
            return json(['status' => 400, 'msg' => '添加失败：' . $e->getMessage()]);
        }

        return json(['status' => 200, 'msg' => '添加成功', 'data' => ['id' => $id]]);
    }

    /**
     * 更新周期
     */
    public function update()
    {
        $productId = (int)request()->param('product_id');
        $id        = (int)request()->param('id');
        $name      = (string)request()->param('name', '');
        $num       = (int)request()->param('num', 1);
        $unit      = (string)request()->param('unit', 'month');
        $price     = (float)request()->param('price', 0);

        if ($id <= 0 || $productId <= 0 || $name === '') {
            return json(['status' => 400, 'msg' => '参数错误']);
        }

        try {
            $model = new CustomCycleModel();

            $cycle = $model->findCycle($productId, $id);
            if (empty($cycle)) {
                return json(['status' => 400, 'msg' => '周期不存在']);
            }

            $model->update($id, ['name' => $name, 'num' => $num, 'unit' => $unit, 'price' => $price]);
        } catch (\Exception $e) {
            return json(['status' => 400, 'msg' => '更新失败：' . $e->getMessage()]);
        }

        return json(['status' => 200, 'msg' => '更新成功']);
    }

    /**
     * 删除周期
     */
    public function delete()
    {
        $productId = (int)request()->param('product_id');
        $id        = (int)request()->param('id');

        if ($id <= 0 || $productId <= 0) {
            return json(['status' => 400, 'msg' => '参数错误']);
        }

        try {
            $model = new CustomCycleModel();

            $cycle = $model->findCycle($productId, $id);
            if (empty($cycle)) {
                return json(['status' => 400, 'msg' => '周期不存在']);
            }

            $model->delete($id);
        } catch (\Exception $e) {
            return json(['status' => 400, 'msg' => '删除失败：' . $e->getMessage()]);
        }

        return json(['status' => 200, 'msg' => '删除成功']);
    }
}

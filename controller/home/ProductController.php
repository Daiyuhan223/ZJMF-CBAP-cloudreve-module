<?php

namespace server\cloudreve_group\controller\home;

use app\event\controller\BaseController;
use server\cloudreve_group\model\CustomCycleModel;
use think\facade\Db;

/**
 * 前台：商品购买页数据接口
 * 路由：GET  /console/v1/cloudreve_group/product/:id/configoption
 *      POST /console/v1/cloudreve_group/product/:id/configoption/calculate
 */
class ProductController extends BaseController
{
    public function initialize()
    {
        parent::initialize();
        app('http')->name('home');
    }
    /**
     * 购买页数据
     */
    public function configoption()
    {
        $productId = (int)request()->param('product_id');

        $product = Db::name('product')->where('id', $productId)->find();
        if (empty($product)) {
            return json(['status' => 400, 'msg' => '商品不存在']);
        }

        $model = new CustomCycleModel();
        $customCycles = $model->lists($productId);

        // 商品未配置周期时，兜底一个月付周期（价格取商品起售价），保证购买页不报错
        if (empty($customCycles)) {
            $customCycles[] = [
                'id'           => 0,
                'name'         => '月付',
                'cycle_time'   => 1,
                'cycle_unit'   => 'month',
                'amount'       => (float)($product['price'] ?? 0),
                'cycle_amount' => (float)($product['price'] ?? 0),
            ];
        }

        $data = [
            'common_product' => [
                'id'                    => $product['id'],
                'name'                  => $product['name'] ?? '',
                'pay_type'              => $product['pay_type'] ?? 'recurring_prepayment',
                'allow_qty'             => (int)($product['allow_qty'] ?? 0),
                'order_page_description'=> $product['order_page_description'] ?? '',
                'price'                 => (float)($product['price'] ?? 0),
            ],
            'configoptions' => [],
            'cycles'        => ['onetime' => '-1.00'],
            'custom_cycles' => $customCycles,
        ];

        return json(['status' => 200, 'msg' => '请求成功', 'data' => $data]);
    }

    /**
     * 配置变化后重新计算周期（本模块无配置项，周期价格恒定）
     */
    public function calculate()
    {
        $productId = (int)request()->param('product_id');

        $model = new CustomCycleModel();
        $customCycles = $model->lists($productId);

        if (empty($customCycles)) {
            $product = Db::name('product')->where('id', $productId)->find();
            $customCycles[] = [
                'id'           => 0,
                'name'         => '月付',
                'cycle_time'   => 1,
                'cycle_unit'   => 'month',
                'amount'       => (float)($product['price'] ?? 0),
                'cycle_amount' => (float)($product['price'] ?? 0),
            ];
        }

        return json([
            'status' => 200,
            'msg'    => '请求成功',
            'data'   => [
                'custom_cycles' => $customCycles,
                'cycles'        => ['onetime' => '-1.00'],
            ],
        ]);
    }
}

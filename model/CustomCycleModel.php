<?php

namespace server\cloudreve_group\model;

use think\facade\Db;

/**
 * 自定义周期模型
 * 操作 idcsmart_module_cloudreve_group_custom_cycle / _custom_cycle_pricing 表
 * 周期价格在后台 serverConfigOption（商品-接口管理）中维护
 */
class CustomCycleModel
{
    /**
     * @var string 周期表（不含前缀）
     */
    protected $cycleTable = 'module_cloudreve_group_custom_cycle';

    /**
     * @var string 周期价格表（不含前缀）
     */
    protected $pricingTable = 'module_cloudreve_group_custom_cycle_pricing';

    /**
     * 获取商品启用的周期列表（含价格）
     *
     * @param int $productId 商品ID
     * @return array [{id,name,cycle_time,cycle_unit,amount,cycle_amount}]
     */
    public function lists($productId)
    {
        try {
            $cycles = Db::name($this->cycleTable)->alias('cc')
                ->field('cc.id,cc.name,cc.cycle_time,cc.cycle_unit,ccp.amount')
                ->leftJoin('__PREFIX__' . $this->pricingTable . ' ccp', "ccp.custom_cycle_id=cc.id AND ccp.type='product' AND ccp.rel_id=cc.product_id")
                ->where('cc.product_id', $productId)
                ->where('cc.status', 1)
                ->where('ccp.amount', '>=', 0)
                ->order('cc.cycle_time', 'asc')
                ->order('cc.id', 'asc')
                ->select()
                ->toArray();
        } catch (\Exception $e) {
            return [];
        }

        foreach ($cycles as $key => $cycle) {
            $cycles[$key]['amount']       = (float)($cycle['amount'] ?? 0);
            $cycles[$key]['cycle_amount'] = (float)($cycle['amount'] ?? 0);
        }

        return $cycles;
    }

    /**
     * 获取单个周期（含价格）
     *
     * @param int $productId 商品ID
     * @param int $cycleId   周期ID
     * @return array|null
     */
    public function findCycle($productId, $cycleId)
    {
        try {
            return Db::name($this->cycleTable)->alias('cc')
                ->field('cc.id,cc.name,cc.cycle_time,cc.cycle_unit,ccp.amount')
                ->leftJoin('__PREFIX__' . $this->pricingTable . ' ccp', "ccp.custom_cycle_id=cc.id AND ccp.type='product' AND ccp.rel_id=cc.product_id")
                ->where('cc.product_id', $productId)
                ->where('cc.id', (int)$cycleId)
                ->where('cc.status', 1)
                ->find();
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * 新增周期
     *
     * @param int   $productId 商品ID
     * @param array $data      含 name/num/unit/price
     * @return int 周期ID
     */
    public function create($productId, $data)
    {
        $now = time();

        $cycleId = Db::name($this->cycleTable)->insertGetId([
            'product_id'  => (int)$productId,
            'name'        => $data['name'] ?? '',
            'cycle_time'  => (int)($data['num'] ?? 1),
            'cycle_unit'  => $data['unit'] ?? 'month',
            'cycle_type'  => 0,
            'status'      => 1,
            'create_time' => $now,
            'update_time' => $now,
        ]);

        Db::name($this->pricingTable)->insert([
            'custom_cycle_id' => $cycleId,
            'rel_id'          => (int)$productId,
            'type'            => 'product',
            'amount'          => (float)($data['price'] ?? 0),
            'create_time'     => $now,
            'update_time'     => $now,
        ]);

        return $cycleId;
    }

    /**
     * 更新周期
     *
     * @param int   $cycleId 周期ID
     * @param array $data    含 name/num/unit/price
     * @return bool
     */
    public function update($cycleId, $data)
    {
        $now = time();

        Db::name($this->cycleTable)->where('id', (int)$cycleId)->update([
            'name'        => $data['name'] ?? '',
            'cycle_time'  => (int)($data['num'] ?? 1),
            'cycle_unit'  => $data['unit'] ?? 'month',
            'update_time' => $now,
        ]);

        Db::name($this->pricingTable)
            ->where('custom_cycle_id', (int)$cycleId)
            ->where('type', 'product')
            ->update([
                'amount'      => (float)($data['price'] ?? 0),
                'update_time' => $now,
            ]);

        return true;
    }

    /**
     * 删除周期
     *
     * @param int $cycleId 周期ID
     * @return bool
     */
    public function delete($cycleId)
    {
        Db::name($this->cycleTable)->where('id', (int)$cycleId)->delete();
        Db::name($this->pricingTable)->where('custom_cycle_id', (int)$cycleId)->delete();

        return true;
    }

    /**
     * 周期时长转秒
     *
     * @param int    $num  时长
     * @param string $unit hour/day/month/year/infinite
     * @return int 秒数
     */
    public function cycleTimeToSeconds($num, $unit)
    {
        switch ($unit) {
            case 'hour':
                return $num * 3600;
            case 'day':
                return $num * 86400;
            case 'year':
                return $num * 365 * 86400;
            case 'infinite':
                return 0;
            case 'month':
            default:
                return $num * 30 * 86400;
        }
    }
}

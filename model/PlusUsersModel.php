<?php

namespace server\cloudreve_group\model;

use think\facade\Db;

/**
 * Cloudreve 套餐用户记录模型
 * 操作 idcsmart_module_cloudreve_group_plus_users 表
 */
class PlusUsersModel
{
    /**
     * @var string 数据表名（不含数据库前缀）
     */
    protected $table = 'module_cloudreve_group_plus_users';

    /**
     * 获取指定邮箱未过期记录中的最高等级
     *
     * @param string $email Cloudreve 用户邮箱
     * @return int 最高 group_level 值，无记录返回 0
     */
    public function getMaxLevelByEmail($email)
    {
        try {
            $result = Db::name($this->table)
                ->where('email', $email)
                ->where('expire_date', '>', time())
                ->max('group_level');
        } catch (\Exception $e) {
            return 0;
        }

        return (int)$result;
    }

    /**
     * 获取指定邮箱所有未过期记录，按等级降序排列
     *
     * @param string $email Cloudreve 用户邮箱
     * @return array 记录列表
     */
    public function getActiveRecordsByEmail($email)
    {
        try {
            return Db::name($this->table)
                ->where('email', $email)
                ->where('expire_date', '>', time())
                ->order('group_level', 'DESC')
                ->select();
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * 插入一条套餐用户记录
     *
     * @param array $data 关联数组，含 uid, email, group_id, group_level, expire_date, host_id, product_id
     * @return int|string 新插入的记录 ID
     */
    public function addRecord($data)
    {
        $now = time();

        $insert = [
            'uid'         => isset($data['uid']) ? (int)$data['uid'] : 0,
            'email'       => $data['email'] ?? '',
            'group_id'    => isset($data['group_id']) ? (int)$data['group_id'] : 0,
            'group_level' => isset($data['group_level']) ? (int)$data['group_level'] : 0,
            'expire_date' => isset($data['expire_date']) ? (int)$data['expire_date'] : 0,
            'host_id'     => isset($data['host_id']) ? (int)$data['host_id'] : 0,
            'product_id'  => isset($data['product_id']) ? (int)$data['product_id'] : 0,
            'create_time' => $now,
            'update_time' => $now,
        ];

        return Db::name($this->table)->insertGetId($insert);
    }

    /**
     * 按产品实例 ID 删除记录
     *
     * @param int $hostId 魔方产品实例 ID
     * @return int 受影响行数
     */
    public function deleteByHostId($hostId)
    {
        try {
            return Db::name($this->table)->where('host_id', (int)$hostId)->delete();
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * 获取所有已到期的记录
     *
     * @return array 过期记录列表
     */
    public function getExpiredRecords()
    {
        try {
            return Db::name($this->table)
                ->where('expire_date', '<=', time())
                ->where('expire_date', '>', 0)
                ->select();
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * 按邮箱和产品实例 ID 删除记录
     *
     * @param string $email  Cloudreve 用户邮箱
     * @param int    $hostId 魔方产品实例 ID
     * @return int 受影响行数
     */
    public function deleteByEmailAndHostId($email, $hostId)
    {
        try {
            return Db::name($this->table)
                ->where('email', $email)
                ->where('host_id', (int)$hostId)
                ->delete();
        } catch (\Exception $e) {
            return 0;
        }
    }
}

<?php

namespace server\cloudreve_group\logic;

/**
 * Cloudreve API 通信类
 * 封装与 Cloudreve V4 管理后台的 API 通信，包括登录、用户查询、用户组设置
 *
 * Cloudreve V4 约定：
 *  - 所有接口前缀 /api/v4/
 *  - HTTP 状态码恒为 200，成功/失败通过响应体 code 字段判断（0=成功）
 *  - 用户ID/用户组ID 在数据库中为数字，界面展示的是 hash 字符串
 *  - 改用户组：PUT /api/v4/admin/user/:id  body {user:{id, group_users}}
 */
class CloudreveApi
{
    /**
     * @var string Cloudreve 站点 URL
     */
    private $baseUrl;

    /**
     * @var string 管理员邮箱
     */
    private $adminEmail;

    /**
     * @var string 管理员密码
     */
    private $adminPassword;

    /**
     * @var string 管理员认证 access_token
     */
    private $token;

    /**
     * @var int Token 过期时间（时间戳）
     */
    private $tokenExpire;

    /**
     * 初始化 Cloudreve V4 API 配置
     *
     * @param string $baseUrl Cloudreve 站点 URL
     * @param string $adminEmail 管理员邮箱
     * @param string $adminPassword 管理员密码
     */
    public function __construct($baseUrl, $adminEmail, $adminPassword)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->adminEmail = $adminEmail;
        $this->adminPassword = $adminPassword;
    }

    /**
     * 获取管理员认证 access_token
     *
     * @return string access_token 字符串
     * @throws \Exception 登录失败时抛出异常
     */
    public function getToken()
    {
        if ($this->token && time() < $this->tokenExpire) {
            return $this->token;
        }

        $result = $this->request('POST', '/api/v4/session/token', [
            'email'    => $this->adminEmail,
            'password' => $this->adminPassword,
        ]);

        $this->token = $result['data']['token']['access_token'] ?? '';
        if (empty($this->token)) {
            throw new \Exception('Token 获取失败，返回格式异常');
        }

        // 默认缓存 1 小时，V4 token 有效期较短
        $expiresStr = $result['data']['token']['access_expires'] ?? null;
        if ($expiresStr) {
            $this->tokenExpire = strtotime($expiresStr) - 60;
        } else {
            $this->tokenExpire = time() + 3600;
        }

        return $this->token;
    }

    /**
     * 通过邮箱精确查找 Cloudreve 用户
     * V4：POST /api/v4/admin/user，conditions.user_email 过滤
     *
     * @param string $email Cloudreve 用户邮箱
     * @return array|false 成功返回用户数组（含 id / group_users），未找到返回 false
     * @throws \Exception 请求失败时抛出异常
     */
    public function getUserByEmail($email)
    {
        $result = $this->request('POST', '/api/v4/admin/user', [
            'page'            => 1,
            'page_size'       => 20,
            'order_by'        => 'id',
            'order_direction' => 'desc',
            'conditions'      => ['user_email' => $email],
        ]);

        $users = $result['data']['users'] ?? [];

        foreach ($users as $user) {
            if (isset($user['email']) && $user['email'] === $email) {
                return $user;
            }
        }

        return false;
    }

    /**
     * 设置用户组
     * V4：PUT /api/v4/admin/user/:id，body {user:{id, group_users}}
     *
     * @param string $email   Cloudreve 用户邮箱
     * @param int    $groupId 目标用户组数字 ID
     * @return bool 成功返回 true
     * @throws \Exception 用户不存在或 API 报错时抛出异常
     */
    public function setUserGroup($email, $groupId)
    {
        $user = $this->getUserByEmail($email);

        if (!$user) {
            throw new \Exception("User not found: {$email}");
        }

        $uid = (int)($user['id'] ?? 0);
        if ($uid <= 0) {
            throw new \Exception("Invalid user id: {$email}");
        }

        // V4 的 Upsert 更新会重写 email/nick/avatar/status/group，必须回传完整字段，否则入库报错
        $updateUser = [
            'id'          => $uid,
            'email'       => $user['email'] ?? '',
            'nick'        => $user['nick'] ?? '',
            'avatar'      => $user['avatar'] ?? '',
            'status'      => $user['status'] ?? 'active',
            'group_users' => (int)$groupId,
        ];

        $this->request('PUT', '/api/v4/admin/user/' . $uid, [
            'user' => $updateUser,
        ]);

        return true;
    }

    /**
     * 获取用户组列表（数字ID + 名称），用于后台配置映射
     *
     * @return array 用户组列表
     * @throws \Exception 请求失败时抛出异常
     */
    public function listGroups()
    {
        $result = $this->request('POST', '/api/v4/admin/group', [
            'page'            => 1,
            'page_size'       => 100,
            'order_by'        => 'id',
            'order_direction' => 'asc',
        ]);

        return $result['data']['groups'] ?? [];
    }

    /**
     * 生成用户一键登录票据
     * 注意：Cloudreve V4 暂未发现管理员生成一次性登录链接的接口，返回空字符串
     *
     * @param int $uid Cloudreve 用户数字 ID
     * @return string 登录 URL，生成失败返回空字符串
     */
    public function generateLoginToken($uid)
    {
        return '';
    }

    /**
     * 发送 HTTP 请求到 Cloudreve V4 API
     *
     * @param string $method 请求方法（GET/POST/PUT/DELETE）
     * @param string $path   API 路径，如 /api/v4/admin/user
     * @param array  $data   请求体数据（GET 时拼接到 URL）
     * @return array 解析后的 JSON 响应
     * @throws \Exception 网络错误或 code 非 0 时抛出异常
     */
    private function request($method, $path, $data = [])
    {
        $url = $this->baseUrl . $path;
        $ch  = curl_init();

        $headers = [
            'Content-Type: application/json',
        ];

        // /api/v4/session/token 是登录接口，不需要鉴权
        if (strpos($path, '/api/v4/session/token') === false) {
            $token = $this->getToken();
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        if ($method === 'GET' && !empty($data)) {
            $url .= '?' . http_build_query($data);
        }

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        if (!empty($data) && $method !== 'GET') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);

        curl_close($ch);

        if ($error) {
            throw new \Exception("cURL Error: {$error}");
        }

        $body = json_decode($response, true);
        if (!is_array($body)) {
            throw new \Exception('Invalid JSON response: ' . substr($response, 0, 200));
        }

        // V4：code 非 0 即业务失败
        if (isset($body['code']) && $body['code'] != 0) {
            $msg = $body['msg'] ?? ('Code ' . $body['code']);
            throw new \Exception($msg);
        }

        // 兜底：非 2xx 状态码
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new \Exception('HTTP ' . $httpCode . ': ' . substr($response, 0, 200));
        }

        return $body;
    }
}

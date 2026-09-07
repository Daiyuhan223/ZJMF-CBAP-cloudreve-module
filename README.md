# Cloudreve 用户组管理模块（ZJMF-CBAP-cloudreve-module）

智简魔方业务系统 V10（ZJMF-CBAP）的 server 类型模块插件。用户在魔方购买不同等级的云盘套餐后，自动调用 **Cloudreve v4** 管理 API 切换用户组；到期未续费自动降级。

## 功能特性

- **买套餐自动切用户组**：下单开通后，按「商品 → 用户组」映射把用户的 Cloudreve 账号切到目标用户组
- **多套餐取最高等级**：同一邮箱可持有多个套餐，始终生效等级权重最高的用户组；降级/删除一个套餐后自动回落到剩余最高等级，全无则降回基础组
- **到期自动降级**：内置每日定时任务（`cron/check`），扫描过期记录并降级
- **周期价格后台可配**：月付/季付/年付等计费周期与价格在「商品-接口管理」页面维护，前台购买页、续费自动读取
- **续费顺延**：产品续费后自动更新到期时间
- **PC + 移动端**：购买页/产品列表/产品详情前台模板均已适配（基于 idcsmart_common 主题）

## 目录结构

```
public/plugins/server/cloudreve_group/
├── CloudreveGroup.php              # 模块主文件（生命周期/价格/模板入口）
├── hooks.php                       # 插件安装/卸载建表
├── route.php                       # 前台 console/v1 与后台 DIR_ADMIN/v1 路由
├── install.sql                     # 手动建表脚本
├── controller/
│   ├── admin/
│   │   ├── GroupMapController.php    # 后台：商品→用户组映射
│   │   └── CustomCycleController.php # 后台：自定义周期价格
│   └── home/
│       ├── ProductController.php     # 前台：购买页数据 / 周期
│       ├── HostController.php        # 前台：产品列表 / 一键登录
│       └── CronController.php        # 定时降级任务
├── logic/CloudreveApi.php          # Cloudreve V4 API 封装
├── model/
│   ├── PlusUsersModel.php          # 套餐用户记录
│   └── CustomCycleModel.php        # 自定义周期
├── template/
│   ├── cart/                       # 购物车/购买页主题（pc、mobile）
│   ├── clientarea/                 # 会员中心（pc、mobile）
│   └── admin/                      # 后台配置页（用户组映射 + 周期价格）
└── lang/                           # 多语言（zh-cn / zh-hk / en-us）
```

## 安装

1. 将整个 `cloudreve_group` 目录放到魔方服务器：`public/plugins/server/cloudreve_group/`
2. 创建数据表（二选一）：
   - 后台 → 接口管理 → 新建接口，模块选 `cloudreve_group`（首次创建会自动建表）；或
   - 直接在数据库执行 `install.sql`（注意把前缀 `idcsmart_` 换成你库的实际前缀）
3. 数据表：
   - `idcsmart_module_cloudreve_group_plus_users` —— 套餐用户记录
   - `idcsmart_module_cloudreve_group_map` —— 商品→用户组映射
   - `idcsmart_module_cloudreve_group_custom_cycle` / `_pricing` —— 自定义周期与价格
   - `idcsmart_module_cloudreve_group_host_email` —— 产品邮箱记录（host 表无 custom_fields 列，邮箱单独存）
4. 若修改过 `route.php`，需清理框架 `runtime/` 缓存（ThinkPHP 路由缓存）。

## 配置

### 1. 创建接口

后台 → 接口管理 → 新建接口，模块 `cloudreve_group`：

| 字段 | 说明 |
| ---- | ---- |
| url | Cloudreve 站点地址，如 `https://pan.example.com` |
| username | Cloudreve 管理员邮箱 |
| password | Cloudreve 管理员密码 |

（Cloudreve v4 约定：所有 API 前缀 `/api/v4/`，响应体 `code=0` 表示成功，HTTP 恒为 200。）

### 2. 商品关联接口并配置

后台 → 商品管理 → 编辑商品 → 接口管理，关联上述接口后下方出现两个配置区块：

**① Cloudreve 用户组映射**
- 把「商品ID → Cloudreve 用户组」关联起来，填等级权重（越大优先级越高）
- ⚠️ 用户组 ID 填 Cloudreve 的**数字 ID**（不是界面展示的字母 ID，如 `3DhE`）。可在 Cloudreve 数据库用户组表的 `id` 列查看。**数字组 ID 中 `1` 是初始管理员组**，请勿把用户降级/切到管理员组
- 未配置映射时，代码里有 `DEFAULT_GROUP_MAP` 兜底（商品 24 → 组 2），上线前建议在后台显式配置

**② 周期价格配置**
- 添加「月付 / 季付 / 年付」等周期及价格，前台购买页与续费均按此展示与计算
- 未配置周期时兜底用商品起售价按月付展示

### 3. 收集用户 Cloudreve 邮箱（重要）

魔方后台给该商品添加**商品自定义字段**（框架原生「自定义字段」功能）：

- 字段名称填：`cloudreve_email`（**必须包含 `cloudreve` / `邮箱` / `email`**，模块按此识别）
- 类型：文本
- 勾选「订单页可见」（show_order_page）

配置后前台购买页会自动出现邮箱输入框，下单后该值随产品保存；开通时模块通过 `SelfDefinedFieldModel` 读取该邮箱对应的 Cloudreve 账号并切换用户组。

### 4. 到期降级定时任务

在服务器 crontab 添加（按需调整频率，建议每日）：

```cron
0 3 * * * curl -s "https://你的魔方域名/cron/check" >/dev/null 2>&1
```

也可手动访问该 URL 触发一次降级扫描。

## 工作原理

```
用户下单付款
   ↓
结算后（afterSettle）→ 记录产品邮箱（模块表）
   ↓
开通（createAccount）
   ├─ 读取邮箱（模块表 / 商品自定义字段）
   ├─ 读取该商品的用户组映射 + 等级
   ├─ 若新等级 ≥ 当前最高等级 → 调 Cloudreve V4 API 切换用户组
   └─ 写入套餐记录（plus_users）
   ↓
到期（每日 cron）
   ├─ 删除过期记录
   ├─ 还有有效套餐 → 切到剩余最高等级组
   └─ 无有效套餐 → 降回基础组
```

### Cloudreve V4 对接要点

| 操作 | 接口 |
| ---- | ---- |
| 管理员登录 | `POST /api/v4/session/token` |
| 按邮箱查用户 | `POST /api/v4/admin/user`（`conditions.user_email`） |
| 切换用户组 | `PUT /api/v4/admin/user/{数字ID}`（body `user.group_users`，需回传完整用户字段） |
| 用户组列表 | `POST /api/v4/admin/group` |

- 用户/用户组在数据库为**数字 ID**，界面展示的是 hash 字符串
- 用户组数字 ID `1` = 初始管理员组（勿用于降级）
- Cloudreve V4 目前没有「管理员为用户生成一次性登录链接」的接口，「登录 Cloudreve」按钮暂不可用

## 已知限制

- 前台「登录 Cloudreve」一键登录暂不可用（V4 无对应管理接口）
- 自然月周期（cycle_type=1）尚未启用
- 优惠码/客户等级/活动折扣暂未在前台结算联动（价格按周期原价计算）

## 开发说明

- 模块主文件 `CloudreveGroup.php` 实现 `metaData / createAccount / suspendAccount / renew / cartCalculatePrice / afterSettle / clientProductConfigOption / hostList / clientArea / adminArea / serverConfigOption` 等 V10 标准方法
- 前后台模板基于官方 `idcsmart_common` 主题结构改造（`cart/`、`clientarea/`、`admin/`）
- 路由按 V10 规范挂在 `console/v1` 与 `DIR_ADMIN/v1` 分组并附带中间件

## License

仅供学习交流。对接智简魔方与 Cloudreve 请遵守双方相关协议与版权。


2026.9.7 Daiyuhan223 谨上

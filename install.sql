-- Cloudreve 用户组管理模块建表脚本
-- 用途：手动建表（模块升级/接口已存在时 afterCreateFirstServer 不会再次触发，需手动执行本脚本）
-- 注意：把前缀 idcsmart_ 换成你自己的数据库前缀

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

CREATE TABLE IF NOT EXISTS `idcsmart_module_cloudreve_group_host_email` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `host_id` int(11) NOT NULL DEFAULT 0 COMMENT '产品ID',
  `email` varchar(128) NOT NULL DEFAULT '' COMMENT 'Cloudreve邮箱',
  `create_time` int(11) NOT NULL DEFAULT 0,
  `update_time` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_host_id` (`host_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='cloudreve_group产品邮箱记录';

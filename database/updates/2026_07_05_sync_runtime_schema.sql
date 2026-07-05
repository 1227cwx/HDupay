-- HDupay database structure sync
-- Target: MySQL 5.7+
-- Safe to run repeatedly.

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS `hdupay_add_column_if_missing`;
DROP PROCEDURE IF EXISTS `hdupay_drop_column_if_exists`;
DROP PROCEDURE IF EXISTS `hdupay_add_index_if_missing`;

DELIMITER $$

CREATE PROCEDURE `hdupay_add_column_if_missing`(
    IN p_table_name varchar(64),
    IN p_column_name varchar(64),
    IN p_column_definition text
)
BEGIN
    IF EXISTS (
        SELECT 1
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = p_table_name
    ) AND NOT EXISTS (
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = p_table_name
          AND COLUMN_NAME = p_column_name
    ) THEN
        SET @hdupay_sql = CONCAT(
            'ALTER TABLE `',
            REPLACE(p_table_name, '`', '``'),
            '` ADD COLUMN ',
            p_column_definition
        );
        PREPARE hdupay_stmt FROM @hdupay_sql;
        EXECUTE hdupay_stmt;
        DEALLOCATE PREPARE hdupay_stmt;
    END IF;
END$$

CREATE PROCEDURE `hdupay_drop_column_if_exists`(
    IN p_table_name varchar(64),
    IN p_column_name varchar(64)
)
BEGIN
    IF EXISTS (
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = p_table_name
          AND COLUMN_NAME = p_column_name
    ) THEN
        SET @hdupay_sql = CONCAT(
            'ALTER TABLE `',
            REPLACE(p_table_name, '`', '``'),
            '` DROP COLUMN `',
            REPLACE(p_column_name, '`', '``'),
            '`'
        );
        PREPARE hdupay_stmt FROM @hdupay_sql;
        EXECUTE hdupay_stmt;
        DEALLOCATE PREPARE hdupay_stmt;
    END IF;
END$$

CREATE PROCEDURE `hdupay_add_index_if_missing`(
    IN p_table_name varchar(64),
    IN p_index_name varchar(64),
    IN p_index_definition text
)
BEGIN
    IF EXISTS (
        SELECT 1
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = p_table_name
    ) AND NOT EXISTS (
        SELECT 1
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = p_table_name
          AND INDEX_NAME = p_index_name
    ) THEN
        SET @hdupay_sql = CONCAT(
            'ALTER TABLE `',
            REPLACE(p_table_name, '`', '``'),
            '` ADD ',
            p_index_definition
        );
        PREPARE hdupay_stmt FROM @hdupay_sql;
        EXECUTE hdupay_stmt;
        DEALLOCATE PREPARE hdupay_stmt;
    END IF;
END$$

DELIMITER ;

CALL `hdupay_add_column_if_missing`(
    'open_api_clients',
    'api_secret_encrypted',
    '`api_secret_encrypted` varchar(500) NOT NULL DEFAULT '''' AFTER `api_secret_hash`'
);

CREATE TABLE IF NOT EXISTS `easypay_orders` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `api_client_id` bigint unsigned NOT NULL DEFAULT 0,
  `api_secret_encrypted` varchar(500) NOT NULL DEFAULT '',
  `epay_order_no` varchar(64) NOT NULL,
  `out_trade_no` varchar(128) NOT NULL,
  `deposit_order_no` varchar(64) NOT NULL DEFAULT '',
  `name` varchar(255) NOT NULL DEFAULT '',
  `money` varchar(80) NOT NULL DEFAULT '',
  `notify_url` varchar(1000) NOT NULL DEFAULT '',
  `return_url` varchar(1000) NOT NULL DEFAULT '',
  `request_params` text NULL,
  `status` varchar(32) NOT NULL DEFAULT 'pending',
  `notify_status` varchar(32) NOT NULL DEFAULT 'pending',
  `notify_count` int unsigned NOT NULL DEFAULT 0,
  `notify_response` text NULL,
  `notify_error` varchar(1000) NOT NULL DEFAULT '',
  `last_notified_at` datetime NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_epay_order_no` (`epay_order_no`),
  UNIQUE KEY `uniq_client_out_trade_no` (`api_client_id`,`out_trade_no`),
  KEY `idx_deposit_order_no` (`deposit_order_no`),
  KEY `idx_status` (`status`),
  KEY `idx_notify_status` (`notify_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CALL `hdupay_add_column_if_missing`(
    'easypay_orders',
    'api_secret_encrypted',
    '`api_secret_encrypted` varchar(500) NOT NULL DEFAULT '''' AFTER `api_client_id`'
);
CALL `hdupay_add_column_if_missing`(
    'easypay_orders',
    'notify_error',
    '`notify_error` varchar(1000) NOT NULL DEFAULT '''' AFTER `notify_response`'
);
CALL `hdupay_add_column_if_missing`(
    'easypay_orders',
    'last_notified_at',
    '`last_notified_at` datetime NULL AFTER `notify_error`'
);
CALL `hdupay_drop_column_if_exists`('easypay_orders', 'type');

CALL `hdupay_add_index_if_missing`(
    'easypay_orders',
    'uniq_epay_order_no',
    'UNIQUE KEY `uniq_epay_order_no` (`epay_order_no`)'
);
CALL `hdupay_add_index_if_missing`(
    'easypay_orders',
    'uniq_client_out_trade_no',
    'UNIQUE KEY `uniq_client_out_trade_no` (`api_client_id`,`out_trade_no`)'
);
CALL `hdupay_add_index_if_missing`(
    'easypay_orders',
    'idx_deposit_order_no',
    'KEY `idx_deposit_order_no` (`deposit_order_no`)'
);
CALL `hdupay_add_index_if_missing`(
    'easypay_orders',
    'idx_status',
    'KEY `idx_status` (`status`)'
);
CALL `hdupay_add_index_if_missing`(
    'easypay_orders',
    'idx_notify_status',
    'KEY `idx_notify_status` (`notify_status`)'
);

CREATE TABLE IF NOT EXISTS `admin_login_attempts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(64) NOT NULL,
  `ip` varchar(64) NOT NULL,
  `failed_count` int unsigned NOT NULL DEFAULT 0,
  `locked_until` datetime NULL,
  `last_failed_at` datetime NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_username_ip` (`username`,`ip`),
  KEY `idx_locked_until` (`locked_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CALL `hdupay_add_index_if_missing`(
    'admin_login_attempts',
    'uniq_username_ip',
    'UNIQUE KEY `uniq_username_ip` (`username`,`ip`)'
);
CALL `hdupay_add_index_if_missing`(
    'admin_login_attempts',
    'idx_locked_until',
    'KEY `idx_locked_until` (`locked_until`)'
);

DROP PROCEDURE IF EXISTS `hdupay_add_column_if_missing`;
DROP PROCEDURE IF EXISTS `hdupay_drop_column_if_exists`;
DROP PROCEDURE IF EXISTS `hdupay_add_index_if_missing`;

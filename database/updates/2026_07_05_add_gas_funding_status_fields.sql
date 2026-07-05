-- HDupay gas funding transaction status fields
-- Target: MySQL 5.7+
-- Safe to run repeatedly.

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS `hdupay_gas_add_column_if_missing`;
DROP PROCEDURE IF EXISTS `hdupay_gas_add_index_if_missing`;

DELIMITER $$

CREATE PROCEDURE `hdupay_gas_add_column_if_missing`(
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

CREATE PROCEDURE `hdupay_gas_add_index_if_missing`(
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

CALL `hdupay_gas_add_column_if_missing`(
    'gas_funding_transactions',
    'business_type',
    '`business_type` varchar(32) NOT NULL DEFAULT '''' AFTER `network_code`'
);
CALL `hdupay_gas_add_column_if_missing`(
    'gas_funding_transactions',
    'business_id',
    '`business_id` bigint unsigned NOT NULL DEFAULT 0 AFTER `business_type`'
);
CALL `hdupay_gas_add_column_if_missing`(
    'gas_funding_transactions',
    'tx_block_number',
    '`tx_block_number` bigint unsigned NOT NULL DEFAULT 0 AFTER `status`'
);
CALL `hdupay_gas_add_column_if_missing`(
    'gas_funding_transactions',
    'current_confirmations',
    '`current_confirmations` int unsigned NOT NULL DEFAULT 0 AFTER `tx_block_number`'
);
CALL `hdupay_gas_add_column_if_missing`(
    'gas_funding_transactions',
    'required_confirmations',
    '`required_confirmations` int unsigned NOT NULL DEFAULT 0 AFTER `current_confirmations`'
);
CALL `hdupay_gas_add_column_if_missing`(
    'gas_funding_transactions',
    'error_message',
    '`error_message` text NULL AFTER `required_confirmations`'
);
CALL `hdupay_gas_add_column_if_missing`(
    'gas_funding_transactions',
    'confirmed_at',
    '`confirmed_at` datetime NULL AFTER `error_message`'
);

CALL `hdupay_gas_add_index_if_missing`(
    'gas_funding_transactions',
    'idx_business',
    'KEY `idx_business` (`business_type`,`business_id`)'
);
CALL `hdupay_gas_add_index_if_missing`(
    'gas_funding_transactions',
    'idx_status',
    'KEY `idx_status` (`status`)'
);

DROP PROCEDURE IF EXISTS `hdupay_gas_add_column_if_missing`;
DROP PROCEDURE IF EXISTS `hdupay_gas_add_index_if_missing`;

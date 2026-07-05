-- Add per-order scanned block progress for deposit order monitoring.
-- Target: MySQL 5.7+
-- Safe to run repeatedly.

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS `hdupay_add_column_if_missing`;
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
    'deposit_orders',
    'listen_scanned_block',
    '`listen_scanned_block` bigint unsigned NOT NULL DEFAULT 0 AFTER `listen_from_block`'
);

CALL `hdupay_add_index_if_missing`(
    'deposit_orders',
    'idx_waiting_token_scan',
    'KEY `idx_waiting_token_scan` (`network_code`,`token_code`,`status`,`listen_scanned_block`)'
);

DROP PROCEDURE IF EXISTS `hdupay_add_column_if_missing`;
DROP PROCEDURE IF EXISTS `hdupay_add_index_if_missing`;

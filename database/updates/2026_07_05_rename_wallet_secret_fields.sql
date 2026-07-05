-- HDupay wallet secret field rename
-- Target: MySQL 5.7+
-- Safe to run repeatedly.

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS `hdupay_rename_column_if_needed`;
DROP PROCEDURE IF EXISTS `hdupay_copy_column_if_both_exist`;
DROP PROCEDURE IF EXISTS `hdupay_drop_column_if_exists`;

DELIMITER $$

CREATE PROCEDURE `hdupay_rename_column_if_needed`(
    IN p_table_name varchar(64),
    IN p_old_column_name varchar(64),
    IN p_new_column_name varchar(64),
    IN p_new_column_definition text
)
BEGIN
    IF EXISTS (
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = p_table_name
          AND COLUMN_NAME = p_old_column_name
    ) AND NOT EXISTS (
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = p_table_name
          AND COLUMN_NAME = p_new_column_name
    ) THEN
        SET @hdupay_sql = CONCAT(
            'ALTER TABLE `',
            REPLACE(p_table_name, '`', '``'),
            '` CHANGE COLUMN `',
            REPLACE(p_old_column_name, '`', '``'),
            '` ',
            p_new_column_definition
        );
        PREPARE hdupay_stmt FROM @hdupay_sql;
        EXECUTE hdupay_stmt;
        DEALLOCATE PREPARE hdupay_stmt;
    END IF;
END$$

CREATE PROCEDURE `hdupay_copy_column_if_both_exist`(
    IN p_table_name varchar(64),
    IN p_old_column_name varchar(64),
    IN p_new_column_name varchar(64)
)
BEGIN
    IF EXISTS (
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = p_table_name
          AND COLUMN_NAME = p_old_column_name
    ) AND EXISTS (
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = p_table_name
          AND COLUMN_NAME = p_new_column_name
    ) THEN
        SET @hdupay_sql = CONCAT(
            'UPDATE `',
            REPLACE(p_table_name, '`', '``'),
            '` SET `',
            REPLACE(p_new_column_name, '`', '``'),
            '` = `',
            REPLACE(p_old_column_name, '`', '``'),
            '` WHERE (`',
            REPLACE(p_new_column_name, '`', '``'),
            '` IS NULL OR `',
            REPLACE(p_new_column_name, '`', '``'),
            '` = '''') AND `',
            REPLACE(p_old_column_name, '`', '``'),
            '` IS NOT NULL'
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

DELIMITER ;

CALL `hdupay_rename_column_if_needed`(
    'wallet_masters',
    'encrypted_seed_or_xprv',
    'encrypted_seed',
    '`encrypted_seed` text NOT NULL'
);

CALL `hdupay_rename_column_if_needed`(
    'wallet_accounts',
    'encrypted_xprv',
    'encrypted_account_xprv',
    '`encrypted_account_xprv` text NULL'
);

CALL `hdupay_copy_column_if_both_exist`('wallet_masters', 'encrypted_seed_or_xprv', 'encrypted_seed');
CALL `hdupay_copy_column_if_both_exist`('wallet_accounts', 'encrypted_xprv', 'encrypted_account_xprv');

CALL `hdupay_drop_column_if_exists`('wallet_masters', 'encrypted_seed_or_xprv');
CALL `hdupay_drop_column_if_exists`('wallet_accounts', 'encrypted_xprv');

DROP PROCEDURE IF EXISTS `hdupay_rename_column_if_needed`;
DROP PROCEDURE IF EXISTS `hdupay_copy_column_if_both_exist`;
DROP PROCEDURE IF EXISTS `hdupay_drop_column_if_exists`;

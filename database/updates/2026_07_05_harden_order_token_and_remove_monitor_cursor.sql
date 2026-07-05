-- Harden public deposit order token storage and remove unused global monitor cursor table.
-- Target: MySQL 5.7+
-- Safe to run repeatedly.

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS `hdupay_migrate_order_token_hash`;

DELIMITER $$

CREATE PROCEDURE `hdupay_migrate_order_token_hash`()
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'deposit_orders'
    ) THEN
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'deposit_orders'
              AND COLUMN_NAME = 'order_token_hash'
        ) THEN
            IF EXISTS (
                SELECT 1 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'deposit_orders'
                  AND COLUMN_NAME = 'order_token'
            ) THEN
                ALTER TABLE `deposit_orders`
                    CHANGE COLUMN `order_token` `order_token_hash` varchar(64) NOT NULL DEFAULT '';
                UPDATE `deposit_orders`
                SET `order_token_hash` = SHA2(`order_token_hash`, 256)
                WHERE `order_token_hash` <> ''
                  AND CHAR_LENGTH(`order_token_hash`) <> 64;
            ELSE
                ALTER TABLE `deposit_orders`
                    ADD COLUMN `order_token_hash` varchar(64) NOT NULL DEFAULT '' AFTER `order_no`;
            END IF;
        ELSEIF EXISTS (
            SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'deposit_orders'
              AND COLUMN_NAME = 'order_token'
        ) THEN
            UPDATE `deposit_orders`
            SET `order_token_hash` = SHA2(`order_token`, 256)
            WHERE `order_token_hash` = ''
              AND `order_token` <> '';
            ALTER TABLE `deposit_orders` DROP COLUMN `order_token`;
        END IF;
    END IF;
END$$

DELIMITER ;

CALL `hdupay_migrate_order_token_hash`();

DROP PROCEDURE IF EXISTS `hdupay_migrate_order_token_hash`;

DROP TABLE IF EXISTS `monitor_cursors`;

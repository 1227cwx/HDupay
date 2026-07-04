<?php

namespace app\service;

use support\Db;
use Throwable;

class DepositOrderSchemaService
{
    private static bool $ensured = false;

    public function ensure(): void
    {
        if (self::$ensured) {
            return;
        }

        $this->ensureColumns();
        $this->migrateLegacyTransactions();
        $this->migrateLegacyStatuses();

        self::$ensured = true;
    }

    private function ensureColumns(): void
    {
        $columns = [
            'source' => "ALTER TABLE `deposit_orders` ADD COLUMN `source` varchar(32) NOT NULL DEFAULT 'frontend' AFTER `user_id`",
            'source_ip' => "ALTER TABLE `deposit_orders` ADD COLUMN `source_ip` varchar(64) NOT NULL DEFAULT '' AFTER `source`",
            'fiat_currency' => "ALTER TABLE `deposit_orders` ADD COLUMN `fiat_currency` varchar(16) NOT NULL DEFAULT 'USD' AFTER `token_code`",
            'fiat_amount' => "ALTER TABLE `deposit_orders` ADD COLUMN `fiat_amount` varchar(80) NOT NULL DEFAULT '' AFTER `fiat_currency`",
            'token_amount' => "ALTER TABLE `deposit_orders` ADD COLUMN `token_amount` varchar(80) NOT NULL DEFAULT '' AFTER `fiat_amount`",
            'exchange_rate' => "ALTER TABLE `deposit_orders` ADD COLUMN `exchange_rate` varchar(80) NOT NULL DEFAULT '' AFTER `token_amount`",
            'rate_provider' => "ALTER TABLE `deposit_orders` ADD COLUMN `rate_provider` varchar(64) NOT NULL DEFAULT '' AFTER `exchange_rate`",
            'rate_fetched_at' => "ALTER TABLE `deposit_orders` ADD COLUMN `rate_fetched_at` datetime NULL AFTER `rate_provider`",
            'return_url' => "ALTER TABLE `deposit_orders` ADD COLUMN `return_url` varchar(1000) NOT NULL DEFAULT '' AFTER `rate_fetched_at`",
            'tx_hash' => "ALTER TABLE `deposit_orders` ADD COLUMN `tx_hash` varchar(128) NOT NULL DEFAULT '' AFTER `confirmed_at`",
            'tx_log_index' => "ALTER TABLE `deposit_orders` ADD COLUMN `tx_log_index` bigint unsigned NOT NULL DEFAULT 0 AFTER `tx_hash`",
            'tx_block_number' => "ALTER TABLE `deposit_orders` ADD COLUMN `tx_block_number` bigint unsigned NOT NULL DEFAULT 0 AFTER `tx_log_index`",
            'from_address' => "ALTER TABLE `deposit_orders` ADD COLUMN `from_address` varchar(128) NOT NULL DEFAULT '' AFTER `tx_block_number`",
            'to_address' => "ALTER TABLE `deposit_orders` ADD COLUMN `to_address` varchar(128) NOT NULL DEFAULT '' AFTER `from_address`",
            'required_confirmations' => "ALTER TABLE `deposit_orders` ADD COLUMN `required_confirmations` int unsigned NOT NULL DEFAULT 0 AFTER `to_address`",
            'current_confirmations' => "ALTER TABLE `deposit_orders` ADD COLUMN `current_confirmations` int unsigned NOT NULL DEFAULT 0 AFTER `required_confirmations`",
        ];

        foreach ($columns as $column => $sql) {
            if (!$this->hasColumn('deposit_orders', $column)) {
                $this->statementQuietly($sql);
            }
        }

        $this->statementQuietly("ALTER TABLE `deposit_orders` ADD KEY `idx_source` (`source`)");
        $this->statementQuietly("ALTER TABLE `deposit_orders` ADD KEY `idx_tx` (`network_code`,`tx_hash`,`tx_log_index`)");
    }

    private function migrateLegacyTransactions(): void
    {
        if (!$this->tableExists('deposit_transactions')) {
            return;
        }

        $this->statementQuietly(<<<SQL
UPDATE `deposit_orders` o
JOIN (
    SELECT t.*
    FROM `deposit_transactions` t
    JOIN (
        SELECT `order_no`, MAX(`id`) AS `max_id`
        FROM `deposit_transactions`
        GROUP BY `order_no`
    ) x ON x.`max_id` = t.`id`
) tx ON tx.`order_no` = o.`order_no`
SET
    o.`tx_hash` = LOWER(tx.`tx_hash`),
    o.`tx_log_index` = tx.`log_index`,
    o.`tx_block_number` = tx.`block_number`,
    o.`from_address` = LOWER(tx.`from_address`),
    o.`to_address` = LOWER(tx.`to_address`),
    o.`paid_amount_int` = tx.`amount_int`,
    o.`required_confirmations` = tx.`required_confirmations`,
    o.`current_confirmations` = tx.`current_confirmations`,
    o.`confirmed_at` = COALESCE(o.`confirmed_at`, tx.`confirmed_at`)
WHERE o.`tx_hash` = ''
SQL);
    }

    private function migrateLegacyStatuses(): void
    {
        $this->statementQuietly("UPDATE `deposit_orders` SET `token_amount` = `amount_display` WHERE `token_amount` = ''");
        $this->statementQuietly("UPDATE `deposit_orders` SET `source` = 'api' WHERE `api_client_id` > 0 AND (`source` = '' OR `source` = 'frontend')");
        $this->statementQuietly("UPDATE `deposit_orders` SET `source` = 'frontend' WHERE `source` = ''");
        $this->statementQuietly("UPDATE `deposit_orders` SET `status` = 'waiting' WHERE `status` = 'assigned'");
        $this->statementQuietly("UPDATE `deposit_orders` SET `status` = 'confirming' WHERE `status` IN ('paid_detected', 'confirming')");
        $this->statementQuietly("UPDATE `deposit_orders` SET `status` = 'success' WHERE `status` = 'confirmed'");
        $this->statementQuietly("UPDATE `deposit_orders` SET `to_address` = `address` WHERE `to_address` = ''");
    }

    private function hasColumn(string $table, string $column): bool
    {
        try {
            return (bool)Db::select("SHOW COLUMNS FROM `{$table}` LIKE ?", [$column]);
        } catch (Throwable) {
            return false;
        }
    }

    private function tableExists(string $table): bool
    {
        try {
            return (bool)Db::select('SHOW TABLES LIKE ?', [$table]);
        } catch (Throwable) {
            return false;
        }
    }

    private function statementQuietly(string $sql): void
    {
        try {
            Db::statement($sql);
        } catch (Throwable) {
            // 字段或索引已经存在时忽略，保证旧库可重复执行升级。
        }
    }
}

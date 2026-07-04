<?php

namespace app\service;

use support\Db;
use Throwable;

class WalletAssetSchemaService
{
    private static bool $ensured = false;

    public function ensure(): void
    {
        if (self::$ensured) {
            return;
        }

        $this->createTables();
        $this->ensureWalletAccountColumns();
        $this->ensureWithdrawSettingColumns();
        $this->ensureWithdrawalTaskColumns();
        $this->migrateCollectionAddresses();
        $this->ensureDefaultSettings();
        self::$ensured = true;
    }

    private function createTables(): void
    {
        Db::statement(<<<SQL
CREATE TABLE IF NOT EXISTS `wallet_collection_addresses` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `wallet_account_id` bigint unsigned NOT NULL,
  `network_code` varchar(64) NOT NULL,
  `address` varchar(128) NOT NULL,
  `address_lower` varchar(128) NOT NULL,
  `address_type` varchar(32) NOT NULL DEFAULT 'system',
  `is_active` tinyint unsigned NOT NULL DEFAULT 0,
  `sync_enabled` tinyint unsigned NOT NULL DEFAULT 1,
  `usdc_balance_int` varchar(80) NOT NULL DEFAULT '0',
  `usdt_balance_int` varchar(80) NOT NULL DEFAULT '0',
  `native_balance_wei` varchar(100) NOT NULL DEFAULT '0',
  `sync_status` varchar(32) NOT NULL DEFAULT 'pending',
  `sync_error` text NULL,
  `last_balance_sync_at` datetime NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_account_address` (`wallet_account_id`,`address_lower`),
  KEY `idx_network_account` (`network_code`,`wallet_account_id`),
  KEY `idx_sync_enabled` (`sync_enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL);

        Db::statement(<<<SQL
CREATE TABLE IF NOT EXISTS `withdraw_settings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `wallet_account_id` bigint unsigned NOT NULL,
  `network_code` varchar(64) NOT NULL,
  `token_code` varchar(64) NOT NULL,
  `enabled` tinyint unsigned NOT NULL DEFAULT 0,
  `target_address` varchar(128) NOT NULL DEFAULT '',
  `min_amount_int` varchar(80) NOT NULL DEFAULT '0',
  `max_retry_count` int unsigned NOT NULL DEFAULT 3,
  `last_run_at` datetime NULL,
  `status` varchar(32) NOT NULL DEFAULT 'disabled',
  `error_message` text NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_account_token` (`wallet_account_id`,`token_code`),
  KEY `idx_network_token` (`network_code`,`token_code`),
  KEY `idx_enabled` (`enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL);
    }

    private function ensureWalletAccountColumns(): void
    {
        $columns = [
            'gas_sync_enabled' => "ALTER TABLE `wallet_accounts` ADD COLUMN `gas_sync_enabled` tinyint unsigned NOT NULL DEFAULT 1 AFTER `encrypted_gas_funder_private_key`",
            'gas_native_balance_wei' => "ALTER TABLE `wallet_accounts` ADD COLUMN `gas_native_balance_wei` varchar(100) NOT NULL DEFAULT '0' AFTER `gas_sync_enabled`",
            'gas_sync_status' => "ALTER TABLE `wallet_accounts` ADD COLUMN `gas_sync_status` varchar(32) NOT NULL DEFAULT 'pending' AFTER `gas_native_balance_wei`",
            'gas_sync_error' => "ALTER TABLE `wallet_accounts` ADD COLUMN `gas_sync_error` text NULL AFTER `gas_sync_status`",
            'gas_last_balance_sync_at' => "ALTER TABLE `wallet_accounts` ADD COLUMN `gas_last_balance_sync_at` datetime NULL AFTER `gas_sync_error`",
        ];

        foreach ($columns as $column => $sql) {
            if (!$this->hasColumn('wallet_accounts', $column)) {
                $this->statementQuietly($sql);
            }
        }
    }

    private function ensureWithdrawSettingColumns(): void
    {
        $columns = [
            'max_retry_count' => "ALTER TABLE `withdraw_settings` ADD COLUMN `max_retry_count` int unsigned NOT NULL DEFAULT 3 AFTER `min_amount_int`",
        ];

        foreach ($columns as $column => $sql) {
            if (!$this->hasColumn('withdraw_settings', $column)) {
                $this->statementQuietly($sql);
            }
        }
    }

    private function ensureWithdrawalTaskColumns(): void
    {
        $columns = [
            'max_retry_count' => "ALTER TABLE `withdrawal_tasks` ADD COLUMN `max_retry_count` int unsigned NOT NULL DEFAULT 3 AFTER `retry_count`",
        ];

        foreach ($columns as $column => $sql) {
            if (!$this->hasColumn('withdrawal_tasks', $column)) {
                $this->statementQuietly($sql);
            }
        }
    }

    private function migrateCollectionAddresses(): void
    {
        $now = date('Y-m-d H:i:s');
        $rows = Db::table('wallet_accounts')->get()->toArray();
        foreach ($rows as $row) {
            $account = (array)$row;
            $accountId = (int)($account['id'] ?? 0);
            $address = strtolower((string)($account['collection_address'] ?? ''));
            if ($accountId <= 0 || $address === '') {
                continue;
            }

            $exists = Db::table('wallet_collection_addresses')
                ->where('wallet_account_id', $accountId)
                ->where('address_lower', $address)
                ->first();
            if ($exists) {
                continue;
            }

            Db::table('wallet_collection_addresses')->insert([
                'wallet_account_id' => $accountId,
                'network_code' => (string)$account['network_code'],
                'address' => $address,
                'address_lower' => $address,
                'address_type' => ($account['collection_type'] ?? 'local') === 'exchange' ? 'third_party' : 'system',
                'is_active' => 1,
                'sync_enabled' => 1,
                'usdc_balance_int' => '0',
                'usdt_balance_int' => '0',
                'native_balance_wei' => '0',
                'sync_status' => 'pending',
                'sync_error' => '',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function ensureDefaultSettings(): void
    {
        $this->saveSettingIfMissing('wallet.collection_balance_sync_enabled', '1');
        $this->saveSettingIfMissing('wallet.collection_balance_sync_interval_minutes', '60');
        $this->saveSettingIfMissing('wallet.gas_balance_sync_enabled', '1');
        $this->saveSettingIfMissing('wallet.gas_balance_sync_interval_minutes', '60');
    }

    private function saveSettingIfMissing(string $key, string $value): void
    {
        $exists = Db::table('system_settings')->where('key_name', $key)->first();
        if ($exists) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        Db::table('system_settings')->insert([
            'key_name' => $key,
            'value' => $value,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function hasColumn(string $table, string $column): bool
    {
        try {
            return (bool)Db::select("SHOW COLUMNS FROM `{$table}` LIKE ?", [$column]);
        } catch (Throwable) {
            return false;
        }
    }

    private function statementQuietly(string $sql): void
    {
        try {
            Db::statement($sql);
        } catch (Throwable) {
            // 兼容旧库重复升级，字段或索引已存在时忽略。
        }
    }
}

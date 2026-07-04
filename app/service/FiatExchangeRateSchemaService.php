<?php

namespace app\service;

use support\Db;
use Throwable;

class FiatExchangeRateSchemaService
{
    private static bool $ensured = false;

    public function ensure(): void
    {
        if (self::$ensured) {
            return;
        }

        Db::statement(<<<SQL
CREATE TABLE IF NOT EXISTS `fiat_exchange_rates` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `token_code` varchar(16) NOT NULL,
  `coingecko_id` varchar(64) NOT NULL,
  `fiat_currency` varchar(16) NOT NULL,
  `rate` decimal(36,20) NOT NULL DEFAULT 0.00000000000000000000,
  `auto_update` tinyint unsigned NOT NULL DEFAULT 1,
  `provider` varchar(64) NOT NULL DEFAULT 'coingecko',
  `source_date` date NULL,
  `status` varchar(32) NOT NULL DEFAULT 'pending',
  `error_message` text NULL,
  `last_refresh_at` datetime NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_token_fiat` (`token_code`,`fiat_currency`),
  KEY `idx_token_code` (`token_code`),
  KEY `idx_auto_update` (`auto_update`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL);

        $this->ensureColumns();
        self::$ensured = true;
    }

    private function ensureColumns(): void
    {
        $columns = [
            'token_code' => "ALTER TABLE `fiat_exchange_rates` ADD COLUMN `token_code` varchar(16) NOT NULL DEFAULT 'USDC' AFTER `id`",
            'coingecko_id' => "ALTER TABLE `fiat_exchange_rates` ADD COLUMN `coingecko_id` varchar(64) NOT NULL DEFAULT 'usd-coin' AFTER `token_code`",
            'fiat_currency' => "ALTER TABLE `fiat_exchange_rates` ADD COLUMN `fiat_currency` varchar(16) NOT NULL AFTER `coingecko_id`",
            'rate' => "ALTER TABLE `fiat_exchange_rates` ADD COLUMN `rate` decimal(36,20) NOT NULL DEFAULT 0.00000000000000000000 AFTER `fiat_currency`",
            'auto_update' => "ALTER TABLE `fiat_exchange_rates` ADD COLUMN `auto_update` tinyint unsigned NOT NULL DEFAULT 1 AFTER `rate`",
            'provider' => "ALTER TABLE `fiat_exchange_rates` ADD COLUMN `provider` varchar(64) NOT NULL DEFAULT 'coingecko' AFTER `auto_update`",
            'source_date' => "ALTER TABLE `fiat_exchange_rates` ADD COLUMN `source_date` date NULL AFTER `provider`",
            'status' => "ALTER TABLE `fiat_exchange_rates` ADD COLUMN `status` varchar(32) NOT NULL DEFAULT 'pending' AFTER `source_date`",
            'error_message' => "ALTER TABLE `fiat_exchange_rates` ADD COLUMN `error_message` text NULL AFTER `status`",
            'last_refresh_at' => "ALTER TABLE `fiat_exchange_rates` ADD COLUMN `last_refresh_at` datetime NULL AFTER `error_message`",
            'created_at' => "ALTER TABLE `fiat_exchange_rates` ADD COLUMN `created_at` datetime NOT NULL AFTER `last_refresh_at`",
            'updated_at' => "ALTER TABLE `fiat_exchange_rates` ADD COLUMN `updated_at` datetime NOT NULL AFTER `created_at`",
        ];

        foreach ($columns as $column => $sql) {
            if (!$this->hasColumn('fiat_exchange_rates', $column)) {
                $this->statementQuietly($sql);
            }
        }
        $this->statementQuietly('ALTER TABLE `fiat_exchange_rates` MODIFY COLUMN `rate` decimal(36,20) NOT NULL DEFAULT 0.00000000000000000000');
        $this->statementQuietly('ALTER TABLE `fiat_exchange_rates` DROP INDEX `uniq_base_fiat`');
        $this->statementQuietly('ALTER TABLE `fiat_exchange_rates` ADD UNIQUE KEY `uniq_token_fiat` (`token_code`,`fiat_currency`)');
        $this->statementQuietly('ALTER TABLE `fiat_exchange_rates` ADD KEY `idx_token_code` (`token_code`)');
        $this->statementQuietly('ALTER TABLE `fiat_exchange_rates` ADD KEY `idx_auto_update` (`auto_update`)');
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
            // 字段或索引已存在时忽略，保证旧库可以重复执行升级。
        }
    }
}

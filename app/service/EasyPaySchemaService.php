<?php

namespace app\service;

use support\Db;
use Throwable;

class EasyPaySchemaService
{
    private static bool $ensured = false;

    public function ensure(): void
    {
        if (self::$ensured) {
            return;
        }

        Db::statement(<<<SQL
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL);

        $this->ensureColumns();
        self::$ensured = true;
    }

    private function ensureColumns(): void
    {
        $columns = [
            'api_secret_encrypted' => "ALTER TABLE `easypay_orders` ADD COLUMN `api_secret_encrypted` varchar(500) NOT NULL DEFAULT '' AFTER `api_client_id`",
            'notify_error' => "ALTER TABLE `easypay_orders` ADD COLUMN `notify_error` varchar(1000) NOT NULL DEFAULT '' AFTER `notify_response`",
            'last_notified_at' => "ALTER TABLE `easypay_orders` ADD COLUMN `last_notified_at` datetime NULL AFTER `notify_error`",
        ];

        foreach ($columns as $column => $sql) {
            if (!$this->hasColumn('easypay_orders', $column)) {
                $this->statementQuietly($sql);
            }
        }

        if ($this->hasColumn('easypay_orders', 'type')) {
            $this->statementQuietly("ALTER TABLE `easypay_orders` DROP COLUMN `type`");
        }
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
            // 字段已经存在时忽略，保证旧库可重复执行升级。
        }
    }
}

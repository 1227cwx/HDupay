<?php

namespace app\service;

use support\Db;
use Throwable;

class OpenApiSchemaService
{
    private static bool $ensured = false;

    public function ensure(): void
    {
        if (self::$ensured) {
            return;
        }

        $this->createTables();
        $this->ensureClientSecretEncryptedColumn();
        $this->ensureDepositOrderClientColumn();
        self::$ensured = true;
    }

    private function createTables(): void
    {
        Db::statement(<<<SQL
CREATE TABLE IF NOT EXISTS `open_api_clients` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `api_key` varchar(80) NOT NULL,
  `api_secret_hash` varchar(255) NOT NULL,
  `api_secret_encrypted` varchar(500) NOT NULL DEFAULT '',
  `callback_url` varchar(500) NOT NULL DEFAULT '',
  `ip_whitelist` text NULL,
  `status` varchar(32) NOT NULL DEFAULT 'enabled',
  `last_used_ip` varchar(64) NOT NULL DEFAULT '',
  `last_used_at` datetime NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_api_key` (`api_key`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL);

        Db::statement(<<<SQL
CREATE TABLE IF NOT EXISTS `open_api_callback_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `client_id` bigint unsigned NOT NULL DEFAULT 0,
  `order_no` varchar(64) NOT NULL,
  `callback_url` varchar(500) NOT NULL,
  `request_body` text NULL,
  `http_status` int unsigned NOT NULL DEFAULT 0,
  `response_body` text NULL,
  `status` varchar(32) NOT NULL DEFAULT 'pending',
  `retry_count` int unsigned NOT NULL DEFAULT 0,
  `error_message` varchar(1000) NOT NULL DEFAULT '',
  `last_called_at` datetime NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_client_order` (`client_id`,`order_no`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL);
    }

    private function ensureClientSecretEncryptedColumn(): void
    {
        try {
            $columns = Db::select("SHOW COLUMNS FROM `open_api_clients` LIKE 'api_secret_encrypted'");
            if (!$columns) {
                Db::statement("ALTER TABLE `open_api_clients` ADD COLUMN `api_secret_encrypted` varchar(500) NOT NULL DEFAULT '' AFTER `api_secret_hash`");
            }
        } catch (Throwable) {
            // 启动时不因为字段已存在等兼容性问题中断业务请求。
        }
    }

    private function ensureDepositOrderClientColumn(): void
    {
        try {
            $columns = Db::select("SHOW COLUMNS FROM `deposit_orders` LIKE 'api_client_id'");
            if (!$columns) {
                Db::statement("ALTER TABLE `deposit_orders` ADD COLUMN `api_client_id` bigint unsigned NOT NULL DEFAULT 0 AFTER `user_id`");
                Db::statement("ALTER TABLE `deposit_orders` ADD KEY `idx_api_client` (`api_client_id`)");
            }
        } catch (Throwable) {
            // 启动时不因为索引已存在等兼容性问题中断业务请求。
        }
    }
}

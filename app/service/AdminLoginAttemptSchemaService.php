<?php

namespace app\service;

use support\Db;

class AdminLoginAttemptSchemaService
{
    private static bool $ensured = false;

    public function ensure(): void
    {
        if (self::$ensured) {
            return;
        }

        Db::statement(<<<SQL
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL);

        self::$ensured = true;
    }
}

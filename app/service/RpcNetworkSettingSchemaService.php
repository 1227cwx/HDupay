<?php

namespace app\service;

use support\Db;
use Throwable;

class RpcNetworkSettingSchemaService
{
    private static bool $ensured = false;

    public function ensure(): void
    {
        if (self::$ensured) {
            return;
        }

        $addedMinConfirmBlocks = false;
        if (!$this->hasColumn('rpc_network_settings', 'min_confirm_blocks')) {
            $this->statementQuietly("ALTER TABLE `rpc_network_settings` ADD COLUMN `min_confirm_blocks` int unsigned NOT NULL DEFAULT 12 AFTER `monitor_interval_seconds`");
            $addedMinConfirmBlocks = true;
        }
        if (!$this->hasColumn('rpc_network_settings', 'large_amount_threshold')) {
            $this->statementQuietly("ALTER TABLE `rpc_network_settings` ADD COLUMN `large_amount_threshold` varchar(80) NOT NULL DEFAULT '100' AFTER `confirm_blocks`");
            $addedMinConfirmBlocks = true;
        }

        if ($addedMinConfirmBlocks) {
            $this->applyDefaultConfirmationSettings();
        }

        self::$ensured = true;
    }

    private function applyDefaultConfirmationSettings(): void
    {
        foreach (config('chains.networks') ?: [] as $networkCode => $cfg) {
            $this->statementQuietly(
                "UPDATE `rpc_network_settings` SET `min_confirm_blocks` = ?, `confirm_blocks` = ?, `large_amount_threshold` = ?, `updated_at` = NOW() WHERE `network_code` = ?",
                [
                    (int)($cfg['min_confirm_blocks'] ?? ($cfg['confirm_blocks'] ?? 12)),
                    (int)($cfg['confirm_blocks'] ?? 12),
                    (string)($cfg['large_amount_threshold'] ?? '100'),
                    (string)$networkCode,
                ]
            );
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

    private function statementQuietly(string $sql, array $bindings = []): void
    {
        try {
            Db::statement($sql, $bindings);
        } catch (Throwable) {
            // Columns may already exist when multiple workers start at the same time.
        }
    }
}

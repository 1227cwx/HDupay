<?php

$config = require __DIR__ . '/../config/database.php';
$db = $config['connections'][$config['default'] ?? 'mysql'];
$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $db['host'], $db['port'], $db['database'], $db['charset'] ?? 'utf8mb4');
$pdo = new PDO($dsn, $db['username'], $db['password'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$chains = require __DIR__ . '/../config/chains.php';
$networks = $chains['networks'] ?? [];
$now = date('Y-m-d H:i:s');

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function columnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function indexExists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?');
    $stmt->execute([$table, $index]);
    return (int)$stmt->fetchColumn() > 0;
}

function addColumnIfMissing(PDO $pdo, string $table, string $column, string $definition): void
{
    if (!columnExists($pdo, $table, $column)) {
        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN {$definition}");
    }
}

function firstRow(PDO $pdo, string $sql, array $params = []): ?array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row ?: null;
}

$pdo->exec("CREATE TABLE IF NOT EXISTS `rpc_network_settings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `network_code` varchar(64) NOT NULL,
  `contract_address` varchar(128) NOT NULL DEFAULT '',
  `decimals` int unsigned NOT NULL DEFAULT 6,
  `monitor_interval_seconds` int unsigned NOT NULL DEFAULT 10,
  `confirm_blocks` int unsigned NOT NULL DEFAULT 12,
  `scan_step_blocks` int unsigned NOT NULL DEFAULT 500,
  `active_group_id` bigint unsigned NOT NULL DEFAULT 0,
  `enabled` tinyint unsigned NOT NULL DEFAULT 0,
  `last_monitor_at` datetime NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_network` (`network_code`),
  KEY `idx_active_group` (`active_group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS `rpc_groups` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `network_code` varchar(64) NOT NULL,
  `name` varchar(128) NOT NULL,
  `rotation_mode` varchar(32) NOT NULL DEFAULT 'random',
  `single_attempts` int unsigned NOT NULL DEFAULT 2,
  `max_nodes` int unsigned NOT NULL DEFAULT 3,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_network` (`network_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

if (!tableExists($pdo, 'rpc_configs')) {
    $pdo->exec("CREATE TABLE `rpc_configs` (
      `id` bigint unsigned NOT NULL AUTO_INCREMENT,
      `network_code` varchar(64) NOT NULL,
      `group_id` bigint unsigned NOT NULL DEFAULT 0,
      `name` varchar(128) NOT NULL DEFAULT '',
      `provider` varchar(64) NOT NULL DEFAULT 'infura',
      `rpc_url` varchar(512) NOT NULL DEFAULT '',
      `api_key_cipher` text NULL,
      `api_key_masked` varchar(64) NOT NULL DEFAULT '',
      `use_api_key_secret` tinyint unsigned NOT NULL DEFAULT 0,
      `api_key_secret_cipher` text NULL,
      `proxy_id` bigint unsigned NOT NULL DEFAULT 0,
      `enabled` tinyint unsigned NOT NULL DEFAULT 0,
      `sort_order` int unsigned NOT NULL DEFAULT 0,
      `created_at` datetime NOT NULL,
      `updated_at` datetime NOT NULL,
      PRIMARY KEY (`id`),
      KEY `idx_network_group` (`network_code`,`group_id`),
      KEY `idx_group_enabled` (`group_id`,`enabled`),
      KEY `idx_proxy` (`proxy_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

addColumnIfMissing($pdo, 'rpc_configs', 'group_id', '`group_id` bigint unsigned NOT NULL DEFAULT 0 AFTER `network_code`');
addColumnIfMissing($pdo, 'rpc_configs', 'name', "`name` varchar(128) NOT NULL DEFAULT '' AFTER `group_id`");
addColumnIfMissing($pdo, 'rpc_configs', 'sort_order', '`sort_order` int unsigned NOT NULL DEFAULT 0 AFTER `enabled`');

if (indexExists($pdo, 'rpc_configs', 'uniq_network')) {
    $pdo->exec('ALTER TABLE `rpc_configs` DROP INDEX `uniq_network`');
}
if (!indexExists($pdo, 'rpc_configs', 'idx_network_group')) {
    $pdo->exec('ALTER TABLE `rpc_configs` ADD KEY `idx_network_group` (`network_code`,`group_id`)');
}
if (!indexExists($pdo, 'rpc_configs', 'idx_group_enabled')) {
    $pdo->exec('ALTER TABLE `rpc_configs` ADD KEY `idx_group_enabled` (`group_id`,`enabled`)');
}
if (!indexExists($pdo, 'rpc_configs', 'idx_proxy')) {
    $pdo->exec('ALTER TABLE `rpc_configs` ADD KEY `idx_proxy` (`proxy_id`)');
}

$hasOldContract = columnExists($pdo, 'rpc_configs', 'contract_address');
$hasOldDecimals = columnExists($pdo, 'rpc_configs', 'decimals');
$hasOldMonitor = columnExists($pdo, 'rpc_configs', 'monitor_interval_seconds');
$hasOldConfirm = columnExists($pdo, 'rpc_configs', 'confirm_blocks');
$hasOldStep = columnExists($pdo, 'rpc_configs', 'scan_step_blocks');
$hasOldLastMonitor = columnExists($pdo, 'rpc_configs', 'last_monitor_at');

foreach ($networks as $code => $cfg) {
    $old = firstRow($pdo, 'SELECT * FROM `rpc_configs` WHERE `network_code` = ? ORDER BY `id` LIMIT 1', [$code]) ?: [];
    $setting = firstRow($pdo, 'SELECT * FROM `rpc_network_settings` WHERE `network_code` = ?', [$code]);
    if (!$setting) {
        $stmt = $pdo->prepare('INSERT INTO `rpc_network_settings` (`network_code`,`contract_address`,`decimals`,`monitor_interval_seconds`,`confirm_blocks`,`scan_step_blocks`,`active_group_id`,`enabled`,`last_monitor_at`,`created_at`,`updated_at`) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([
            $code,
            strtolower((string)($hasOldContract && isset($old['contract_address']) && $old['contract_address'] !== '' ? $old['contract_address'] : ($cfg['usdc_contract'] ?? ''))),
            (int)($hasOldDecimals && isset($old['decimals']) ? $old['decimals'] : ($cfg['decimals'] ?? 6)),
            (int)($hasOldMonitor && isset($old['monitor_interval_seconds']) ? $old['monitor_interval_seconds'] : ($cfg['monitor_interval_seconds'] ?? 10)),
            (int)($hasOldConfirm && isset($old['confirm_blocks']) ? $old['confirm_blocks'] : ($cfg['confirm_blocks'] ?? 12)),
            (int)($hasOldStep && isset($old['scan_step_blocks']) ? $old['scan_step_blocks'] : ($cfg['scan_step_blocks'] ?? 500)),
            0,
            (int)($old['enabled'] ?? 0),
            $hasOldLastMonitor ? ($old['last_monitor_at'] ?? null) : null,
            $now,
            $now,
        ]);
        $settingId = (int)$pdo->lastInsertId();
    } else {
        $settingId = (int)$setting['id'];
    }

    $group = firstRow($pdo, 'SELECT * FROM `rpc_groups` WHERE `network_code` = ? ORDER BY `id` LIMIT 1', [$code]);
    if (!$group) {
        $stmt = $pdo->prepare('INSERT INTO `rpc_groups` (`network_code`,`name`,`rotation_mode`,`single_attempts`,`max_nodes`,`created_at`,`updated_at`) VALUES (?,?,?,?,?,?,?)');
        $stmt->execute([$code, '默认分组', 'random', 2, 3, $now, $now]);
        $groupId = (int)$pdo->lastInsertId();
    } else {
        $groupId = (int)$group['id'];
    }

    $stmt = $pdo->prepare('UPDATE `rpc_network_settings` SET `active_group_id` = ?, `updated_at` = ? WHERE `id` = ? AND (`active_group_id` IS NULL OR `active_group_id` = 0)');
    $stmt->execute([$groupId, $now, $settingId]);

    $stmt = $pdo->prepare("UPDATE `rpc_configs` SET `group_id` = ?, `name` = CASE WHEN `name` = '' THEN ? ELSE `name` END, `updated_at` = ? WHERE `network_code` = ? AND (`group_id` IS NULL OR `group_id` = 0)");
    $stmt->execute([$groupId, strtoupper($code) . ' Infura RPC', $now, $code]);
}

echo "RPC 分组迁移完成\n";

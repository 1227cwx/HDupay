<?php

$dbConfig = require __DIR__ . '/../config/database.php';
$chains = require __DIR__ . '/../config/chains.php';
$db = $dbConfig['connections']['mysql'];
$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $db['host'], $db['port'], $db['database'], $db['charset'] ?? 'utf8mb4');
$pdo = new PDO($dsn, $db['username'], $db['password'], $db['options'] ?? []);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$now = date('Y-m-d H:i:s');

foreach (($chains['networks'] ?? []) as $code => $cfg) {
    $networkStmt = $pdo->prepare(
        'INSERT INTO `networks` (`code`,`name`,`chain_id`,`status`,`created_at`,`updated_at`) VALUES (?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE `name`=VALUES(`name`), `chain_id`=VALUES(`chain_id`), `status`=VALUES(`status`), `updated_at`=VALUES(`updated_at`)'
    );
    $networkStmt->execute([
        $code,
        $cfg['name'] ?? $code,
        (int)($cfg['chain_id'] ?? 0),
        'enabled',
        $now,
        $now,
    ]);

    $tokenStmt = $pdo->prepare(
        'INSERT INTO `network_tokens` (`network_code`,`token_code`,`contract_address`,`decimals`,`standard`,`status`,`created_at`,`updated_at`) VALUES (?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE `contract_address`=VALUES(`contract_address`), `decimals`=VALUES(`decimals`), `standard`=VALUES(`standard`), `status`=VALUES(`status`), `updated_at`=VALUES(`updated_at`)'
    );
    $tokenStmt->execute([
        $code,
        $chains['token']['code'] ?? 'USDC',
        strtolower((string)($cfg['usdc_contract'] ?? '')),
        (int)($cfg['decimals'] ?? 6),
        'ERC20',
        'enabled',
        $now,
        $now,
    ]);

    $setting = firstRow($pdo, 'SELECT * FROM `rpc_network_settings` WHERE `network_code` = ?', [$code]);
    if (!$setting) {
        $stmt = $pdo->prepare(
            'INSERT INTO `rpc_network_settings` (`network_code`,`contract_address`,`decimals`,`monitor_interval_seconds`,`confirm_blocks`,`scan_step_blocks`,`active_group_id`,`enabled`,`last_monitor_at`,`created_at`,`updated_at`) VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $code,
            strtolower((string)($cfg['usdc_contract'] ?? '')),
            (int)($cfg['decimals'] ?? 6),
            (int)($cfg['monitor_interval_seconds'] ?? 10),
            (int)($cfg['confirm_blocks'] ?? 12),
            (int)($cfg['scan_step_blocks'] ?? 500),
            0,
            0,
            null,
            $now,
            $now,
        ]);
        $setting = firstRow($pdo, 'SELECT * FROM `rpc_network_settings` WHERE `network_code` = ?', [$code]);
    }

    $group = firstRow($pdo, 'SELECT * FROM `rpc_groups` WHERE `network_code` = ? ORDER BY `id` LIMIT 1', [$code]);
    if (!$group) {
        $stmt = $pdo->prepare(
            'INSERT INTO `rpc_groups` (`network_code`,`name`,`rotation_mode`,`single_attempts`,`max_nodes`,`created_at`,`updated_at`) VALUES (?,?,?,?,?,?,?)'
        );
        $stmt->execute([$code, '默认分组', 'random', 2, 3, $now, $now]);
        $group = firstRow($pdo, 'SELECT * FROM `rpc_groups` WHERE `network_code` = ? ORDER BY `id` LIMIT 1', [$code]);
    }

    if ($setting && $group && (int)($setting['active_group_id'] ?? 0) <= 0) {
        $stmt = $pdo->prepare('UPDATE `rpc_network_settings` SET `active_group_id` = ?, `updated_at` = ? WHERE `id` = ?');
        $stmt->execute([(int)$group['id'], $now, (int)$setting['id']]);
    }
}

echo "Network migration completed.\n";

function firstRow(PDO $pdo, string $sql, array $params = []): ?array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

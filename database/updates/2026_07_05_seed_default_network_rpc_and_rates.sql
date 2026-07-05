-- Seed default networks, RPC groups/settings, fiat rate settings and pending rate rows.
-- Target: MySQL 5.7+
-- Safe to run repeatedly. Existing custom RPC settings and successful rates are preserved.

SET NAMES utf8mb4;

INSERT INTO `networks` (`code`, `name`, `chain_id`, `status`, `created_at`, `updated_at`) VALUES
('ethereum', 'Ethereum Mainnet', 1, 'enabled', NOW(), NOW()),
('base', 'Base Mainnet', 8453, 'enabled', NOW(), NOW()),
('celo', 'Celo Mainnet', 42220, 'enabled', NOW(), NOW()),
('polygon', 'Polygon PoS Mainnet', 137, 'enabled', NOW(), NOW())
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`), `chain_id`=VALUES(`chain_id`), `status`=VALUES(`status`), `updated_at`=NOW();

INSERT INTO `tokens` (`code`, `name`, `decimals`, `status`, `created_at`, `updated_at`) VALUES
('USDC', 'USD Coin', 6, 'enabled', NOW(), NOW()),
('USDT', 'Tether USD', 6, 'enabled', NOW(), NOW())
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`), `decimals`=VALUES(`decimals`), `status`=VALUES(`status`), `updated_at`=NOW();

INSERT INTO `network_tokens` (`network_code`, `token_code`, `contract_address`, `decimals`, `standard`, `status`, `created_at`, `updated_at`) VALUES
('ethereum', 'USDC', '0xa0b86991c6218b36c1d19d4a2e9eb0ce3606eb48', 6, 'ERC20', 'enabled', NOW(), NOW()),
('ethereum', 'USDT', '0xdac17f958d2ee523a2206206994597c13d831ec7', 6, 'ERC20', 'enabled', NOW(), NOW()),
('base', 'USDC', '0x833589fcd6edb6e08f4c7c32d4f71b54bda02913', 6, 'ERC20', 'enabled', NOW(), NOW()),
('base', 'USDT', '0xfde4c96c8593536e31f229ea8f37b2ada2699bb2', 6, 'ERC20', 'enabled', NOW(), NOW()),
('celo', 'USDC', '0xceba9300f2b948710d2653dd7b07f33a8b32118c', 6, 'ERC20', 'enabled', NOW(), NOW()),
('celo', 'USDT', '0x48065fbbe25f71c9282ddf5e1cd6d6a887483d5e', 6, 'ERC20', 'enabled', NOW(), NOW()),
('polygon', 'USDC', '0x3c499c542cef5e3811e1192ce70d8cc03d5c3359', 6, 'ERC20', 'enabled', NOW(), NOW()),
('polygon', 'USDT', '0xc2132d05d31c914a87c6611c10748aeb04b58e8f', 6, 'ERC20', 'enabled', NOW(), NOW())
ON DUPLICATE KEY UPDATE `contract_address`=VALUES(`contract_address`), `decimals`=VALUES(`decimals`), `standard`=VALUES(`standard`), `status`=VALUES(`status`), `updated_at`=NOW();

INSERT INTO `rpc_groups` (`network_code`, `name`, `rotation_mode`, `single_attempts`, `max_nodes`, `created_at`, `updated_at`)
SELECT 'ethereum', 'Default RPC Group', 'random', 2, 3, NOW(), NOW()
FROM (SELECT COUNT(*) AS `existing_count` FROM `rpc_groups` WHERE `network_code` = 'ethereum') AS `guard`
WHERE `guard`.`existing_count` = 0;
INSERT INTO `rpc_groups` (`network_code`, `name`, `rotation_mode`, `single_attempts`, `max_nodes`, `created_at`, `updated_at`)
SELECT 'base', 'Default RPC Group', 'random', 2, 3, NOW(), NOW()
FROM (SELECT COUNT(*) AS `existing_count` FROM `rpc_groups` WHERE `network_code` = 'base') AS `guard`
WHERE `guard`.`existing_count` = 0;
INSERT INTO `rpc_groups` (`network_code`, `name`, `rotation_mode`, `single_attempts`, `max_nodes`, `created_at`, `updated_at`)
SELECT 'celo', 'Default RPC Group', 'random', 2, 3, NOW(), NOW()
FROM (SELECT COUNT(*) AS `existing_count` FROM `rpc_groups` WHERE `network_code` = 'celo') AS `guard`
WHERE `guard`.`existing_count` = 0;
INSERT INTO `rpc_groups` (`network_code`, `name`, `rotation_mode`, `single_attempts`, `max_nodes`, `created_at`, `updated_at`)
SELECT 'polygon', 'Default RPC Group', 'random', 2, 3, NOW(), NOW()
FROM (SELECT COUNT(*) AS `existing_count` FROM `rpc_groups` WHERE `network_code` = 'polygon') AS `guard`
WHERE `guard`.`existing_count` = 0;

INSERT INTO `rpc_network_settings` (`network_code`, `contract_address`, `decimals`, `monitor_interval_seconds`, `min_confirm_blocks`, `confirm_blocks`, `large_amount_threshold`, `scan_step_blocks`, `active_group_id`, `enabled`, `last_monitor_at`, `created_at`, `updated_at`)
SELECT 'ethereum', '0xa0b86991c6218b36c1d19d4a2e9eb0ce3606eb48', 6, 10, 12, 24, '100', 500, COALESCE((SELECT `id` FROM `rpc_groups` WHERE `network_code` = 'ethereum' ORDER BY `id` LIMIT 1), 0), 0, NULL, NOW(), NOW()
FROM (SELECT COUNT(*) AS `existing_count` FROM `rpc_network_settings` WHERE `network_code` = 'ethereum') AS `guard`
WHERE `guard`.`existing_count` = 0;
INSERT INTO `rpc_network_settings` (`network_code`, `contract_address`, `decimals`, `monitor_interval_seconds`, `min_confirm_blocks`, `confirm_blocks`, `large_amount_threshold`, `scan_step_blocks`, `active_group_id`, `enabled`, `last_monitor_at`, `created_at`, `updated_at`)
SELECT 'base', '0x833589fcd6edb6e08f4c7c32d4f71b54bda02913', 6, 10, 20, 40, '100', 2000, COALESCE((SELECT `id` FROM `rpc_groups` WHERE `network_code` = 'base' ORDER BY `id` LIMIT 1), 0), 0, NULL, NOW(), NOW()
FROM (SELECT COUNT(*) AS `existing_count` FROM `rpc_network_settings` WHERE `network_code` = 'base') AS `guard`
WHERE `guard`.`existing_count` = 0;
INSERT INTO `rpc_network_settings` (`network_code`, `contract_address`, `decimals`, `monitor_interval_seconds`, `min_confirm_blocks`, `confirm_blocks`, `large_amount_threshold`, `scan_step_blocks`, `active_group_id`, `enabled`, `last_monitor_at`, `created_at`, `updated_at`)
SELECT 'celo', '0xceba9300f2b948710d2653dd7b07f33a8b32118c', 6, 10, 5, 10, '100', 2000, COALESCE((SELECT `id` FROM `rpc_groups` WHERE `network_code` = 'celo' ORDER BY `id` LIMIT 1), 0), 0, NULL, NOW(), NOW()
FROM (SELECT COUNT(*) AS `existing_count` FROM `rpc_network_settings` WHERE `network_code` = 'celo') AS `guard`
WHERE `guard`.`existing_count` = 0;
INSERT INTO `rpc_network_settings` (`network_code`, `contract_address`, `decimals`, `monitor_interval_seconds`, `min_confirm_blocks`, `confirm_blocks`, `large_amount_threshold`, `scan_step_blocks`, `active_group_id`, `enabled`, `last_monitor_at`, `created_at`, `updated_at`)
SELECT 'polygon', '0x3c499c542cef5e3811e1192ce70d8cc03d5c3359', 6, 10, 20, 32, '100', 3000, COALESCE((SELECT `id` FROM `rpc_groups` WHERE `network_code` = 'polygon' ORDER BY `id` LIMIT 1), 0), 0, NULL, NOW(), NOW()
FROM (SELECT COUNT(*) AS `existing_count` FROM `rpc_network_settings` WHERE `network_code` = 'polygon') AS `guard`
WHERE `guard`.`existing_count` = 0;

UPDATE `rpc_network_settings`
SET `active_group_id` = COALESCE((SELECT `id` FROM `rpc_groups` WHERE `network_code` = 'ethereum' ORDER BY `id` LIMIT 1), 0), `updated_at` = NOW()
WHERE `network_code` = 'ethereum' AND (`active_group_id` IS NULL OR `active_group_id` = 0);
UPDATE `rpc_network_settings`
SET `active_group_id` = COALESCE((SELECT `id` FROM `rpc_groups` WHERE `network_code` = 'base' ORDER BY `id` LIMIT 1), 0), `updated_at` = NOW()
WHERE `network_code` = 'base' AND (`active_group_id` IS NULL OR `active_group_id` = 0);
UPDATE `rpc_network_settings`
SET `active_group_id` = COALESCE((SELECT `id` FROM `rpc_groups` WHERE `network_code` = 'celo' ORDER BY `id` LIMIT 1), 0), `updated_at` = NOW()
WHERE `network_code` = 'celo' AND (`active_group_id` IS NULL OR `active_group_id` = 0);
UPDATE `rpc_network_settings`
SET `active_group_id` = COALESCE((SELECT `id` FROM `rpc_groups` WHERE `network_code` = 'polygon' ORDER BY `id` LIMIT 1), 0), `updated_at` = NOW()
WHERE `network_code` = 'polygon' AND (`active_group_id` IS NULL OR `active_group_id` = 0);

INSERT IGNORE INTO `system_settings` (`key_name`, `value`, `created_at`, `updated_at`) VALUES
('fiat_rate_provider', 'coingecko', NOW(), NOW()),
('fiat_rate_proxy_mode', 'direct', NOW(), NOW()),
('fiat_rate_proxy_id', '0', NOW(), NOW()),
('fiat_rate_sync_interval_minutes', '60', NOW(), NOW()),
('fiat_rate_disable_cache', '0', NOW(), NOW());

INSERT INTO `fiat_exchange_rates` (`token_code`, `coingecko_id`, `fiat_currency`, `rate`, `auto_update`, `provider`, `source_date`, `status`, `error_message`, `last_refresh_at`, `created_at`, `updated_at`) VALUES
('USDC', 'usd-coin', 'CNY', 0.00000000000000000000, 1, 'coingecko', NULL, 'pending', '', NULL, NOW(), NOW()),
('USDC', 'usd-coin', 'USD', 0.00000000000000000000, 1, 'coingecko', NULL, 'pending', '', NULL, NOW(), NOW()),
('USDC', 'usd-coin', 'EUR', 0.00000000000000000000, 1, 'coingecko', NULL, 'pending', '', NULL, NOW(), NOW()),
('USDC', 'usd-coin', 'CAD', 0.00000000000000000000, 1, 'coingecko', NULL, 'pending', '', NULL, NOW(), NOW()),
('USDC', 'usd-coin', 'AUD', 0.00000000000000000000, 1, 'coingecko', NULL, 'pending', '', NULL, NOW(), NOW()),
('USDC', 'usd-coin', 'JPY', 0.00000000000000000000, 1, 'coingecko', NULL, 'pending', '', NULL, NOW(), NOW()),
('USDC', 'usd-coin', 'HKD', 0.00000000000000000000, 1, 'coingecko', NULL, 'pending', '', NULL, NOW(), NOW()),
('USDC', 'usd-coin', 'GBP', 0.00000000000000000000, 1, 'coingecko', NULL, 'pending', '', NULL, NOW(), NOW()),
('USDC', 'usd-coin', 'SGD', 0.00000000000000000000, 1, 'coingecko', NULL, 'pending', '', NULL, NOW(), NOW()),
('USDT', 'tether', 'CNY', 0.00000000000000000000, 1, 'coingecko', NULL, 'pending', '', NULL, NOW(), NOW()),
('USDT', 'tether', 'USD', 0.00000000000000000000, 1, 'coingecko', NULL, 'pending', '', NULL, NOW(), NOW()),
('USDT', 'tether', 'EUR', 0.00000000000000000000, 1, 'coingecko', NULL, 'pending', '', NULL, NOW(), NOW()),
('USDT', 'tether', 'CAD', 0.00000000000000000000, 1, 'coingecko', NULL, 'pending', '', NULL, NOW(), NOW()),
('USDT', 'tether', 'AUD', 0.00000000000000000000, 1, 'coingecko', NULL, 'pending', '', NULL, NOW(), NOW()),
('USDT', 'tether', 'JPY', 0.00000000000000000000, 1, 'coingecko', NULL, 'pending', '', NULL, NOW(), NOW()),
('USDT', 'tether', 'HKD', 0.00000000000000000000, 1, 'coingecko', NULL, 'pending', '', NULL, NOW(), NOW()),
('USDT', 'tether', 'GBP', 0.00000000000000000000, 1, 'coingecko', NULL, 'pending', '', NULL, NOW(), NOW()),
('USDT', 'tether', 'SGD', 0.00000000000000000000, 1, 'coingecko', NULL, 'pending', '', NULL, NOW(), NOW())
ON DUPLICATE KEY UPDATE `coingecko_id`=VALUES(`coingecko_id`), `provider`=VALUES(`provider`), `updated_at`=NOW();

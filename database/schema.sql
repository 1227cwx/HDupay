CREATE TABLE IF NOT EXISTS `networks` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(64) NOT NULL,
  `name` varchar(128) NOT NULL,
  `chain_id` bigint unsigned NOT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'enabled',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tokens` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(64) NOT NULL,
  `name` varchar(128) NOT NULL,
  `decimals` int unsigned NOT NULL DEFAULT 6,
  `status` varchar(32) NOT NULL DEFAULT 'enabled',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `network_tokens` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `network_code` varchar(64) NOT NULL,
  `token_code` varchar(64) NOT NULL,
  `contract_address` varchar(128) NOT NULL,
  `decimals` int unsigned NOT NULL DEFAULT 6,
  `standard` varchar(32) NOT NULL DEFAULT 'ERC20',
  `status` varchar(32) NOT NULL DEFAULT 'enabled',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_network_token` (`network_code`,`token_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `rpc_network_settings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `network_code` varchar(64) NOT NULL,
  `contract_address` varchar(128) NOT NULL DEFAULT '',
  `decimals` int unsigned NOT NULL DEFAULT 6,
  `monitor_interval_seconds` int unsigned NOT NULL DEFAULT 10,
  `min_confirm_blocks` int unsigned NOT NULL DEFAULT 12,
  `confirm_blocks` int unsigned NOT NULL DEFAULT 12,
  `large_amount_threshold` varchar(80) NOT NULL DEFAULT '100',
  `scan_step_blocks` int unsigned NOT NULL DEFAULT 500,
  `active_group_id` bigint unsigned NOT NULL DEFAULT 0,
  `enabled` tinyint unsigned NOT NULL DEFAULT 0,
  `last_monitor_at` datetime NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_network` (`network_code`),
  KEY `idx_active_group` (`active_group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `rpc_groups` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `rpc_configs` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `proxy_pools` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(128) NOT NULL,
  `proxy_type` varchar(16) NOT NULL,
  `host` varchar(255) NOT NULL,
  `port` int unsigned NOT NULL,
  `username` varchar(128) NOT NULL DEFAULT '',
  `password_cipher` text NULL,
  `password_masked` varchar(64) NOT NULL DEFAULT '',
  `status` varchar(32) NOT NULL DEFAULT 'enabled',
  `last_test_status` varchar(32) NOT NULL DEFAULT '',
  `last_test_message` varchar(512) NOT NULL DEFAULT '',
  `last_test_at` datetime NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `wallet_masters` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(128) NOT NULL,
  `mnemonic_fingerprint` varchar(128) NOT NULL,
  `encrypted_seed_or_xprv` text NOT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `wallet_accounts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `wallet_master_id` bigint unsigned NOT NULL,
  `network_code` varchar(64) NOT NULL,
  `derivation_path` varchar(128) NOT NULL,
  `xpub` text NULL,
  `encrypted_xprv` text NULL,
  `next_index` bigint unsigned NOT NULL DEFAULT 0,
  `deposit_timeout_minutes` int unsigned NOT NULL DEFAULT 10,
  `collection_type` varchar(32) NOT NULL DEFAULT 'local',
  `collection_address` varchar(128) NOT NULL DEFAULT '',
  `collection_derivation_path` varchar(128) NOT NULL DEFAULT '',
  `gas_funder_address` varchar(128) NOT NULL DEFAULT '',
  `gas_funder_derivation_path` varchar(128) NOT NULL DEFAULT '',
  `encrypted_gas_funder_private_key` text NULL,
  `gas_sync_enabled` tinyint unsigned NOT NULL DEFAULT 1,
  `gas_native_balance_wei` varchar(100) NOT NULL DEFAULT '0',
  `gas_sync_status` varchar(32) NOT NULL DEFAULT 'pending',
  `gas_sync_error` text NULL,
  `gas_last_balance_sync_at` datetime NULL,
  `status` varchar(32) NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_network` (`network_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `payment_addresses` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `network_code` varchar(64) NOT NULL,
  `token_code` varchar(64) NOT NULL,
  `wallet_account_id` bigint unsigned NOT NULL,
  `address` varchar(128) NOT NULL,
  `address_lower` varchar(128) NOT NULL,
  `derivation_path` varchar(128) NOT NULL,
  `address_index` bigint unsigned NOT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'available',
  `assigned_order_no` varchar(64) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL,
  `assigned_at` datetime NULL,
  `expired_at` datetime NULL,
  `frozen_at` datetime NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_network_address` (`network_code`,`address_lower`),
  UNIQUE KEY `uniq_account_index` (`wallet_account_id`,`address_index`),
  KEY `idx_status` (`network_code`,`token_code`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `deposit_orders` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `order_no` varchar(64) NOT NULL,
  `user_id` bigint unsigned NOT NULL DEFAULT 0,
  `source` varchar(32) NOT NULL DEFAULT 'frontend',
  `source_ip` varchar(64) NOT NULL DEFAULT '',
  `api_client_id` bigint unsigned NOT NULL DEFAULT 0,
  `network_code` varchar(64) NOT NULL,
  `token_code` varchar(64) NOT NULL,
  `fiat_currency` varchar(16) NOT NULL DEFAULT 'USD',
  `fiat_amount` varchar(80) NOT NULL DEFAULT '',
  `token_amount` varchar(80) NOT NULL DEFAULT '',
  `exchange_rate` varchar(80) NOT NULL DEFAULT '',
  `rate_provider` varchar(64) NOT NULL DEFAULT '',
  `rate_fetched_at` datetime NULL,
  `return_url` varchar(1000) NOT NULL DEFAULT '',
  `amount_int` varchar(80) NOT NULL,
  `amount_display` varchar(80) NOT NULL,
  `paid_amount_int` varchar(80) NOT NULL DEFAULT '0',
  `address_id` bigint unsigned NOT NULL,
  `address` varchar(128) NOT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'waiting',
  `expire_at` datetime NOT NULL,
  `confirmed_at` datetime NULL,
  `tx_hash` varchar(128) NOT NULL DEFAULT '',
  `tx_log_index` bigint unsigned NOT NULL DEFAULT 0,
  `tx_block_number` bigint unsigned NOT NULL DEFAULT 0,
  `from_address` varchar(128) NOT NULL DEFAULT '',
  `to_address` varchar(128) NOT NULL DEFAULT '',
  `required_confirmations` int unsigned NOT NULL DEFAULT 0,
  `current_confirmations` int unsigned NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_order_no` (`order_no`),
  KEY `idx_address` (`network_code`,`address`),
  KEY `idx_api_client` (`api_client_id`),
  KEY `idx_status` (`status`),
  KEY `idx_source` (`source`),
  KEY `idx_tx` (`network_code`,`tx_hash`,`tx_log_index`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `monitor_cursors` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `network_code` varchar(64) NOT NULL,
  `token_code` varchar(64) NOT NULL,
  `contract_address` varchar(128) NOT NULL,
  `last_scanned_block` bigint unsigned NOT NULL DEFAULT 0,
  `confirm_blocks` int unsigned NOT NULL DEFAULT 12,
  `scan_step_blocks` int unsigned NOT NULL DEFAULT 500,
  `status` varchar(32) NOT NULL DEFAULT 'enabled',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_cursor` (`network_code`,`token_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `collection_tasks` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `network_code` varchar(64) NOT NULL,
  `token_code` varchar(64) NOT NULL,
  `address_id` bigint unsigned NOT NULL,
  `from_address` varchar(128) NOT NULL,
  `to_address` varchar(128) NOT NULL,
  `collection_type` varchar(32) NOT NULL DEFAULT 'local',
  `amount_int` varchar(80) NOT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'pending_collect',
  `gas_funding_tx_hash` varchar(128) NOT NULL DEFAULT '',
  `collect_tx_hash` varchar(128) NOT NULL DEFAULT '',
  `actual_gas_used` bigint unsigned NOT NULL DEFAULT 0,
  `actual_gas_price_wei` varchar(100) NOT NULL DEFAULT '',
  `actual_gas_fee_wei` varchar(100) NOT NULL DEFAULT '',
  `collect_block_number` bigint unsigned NOT NULL DEFAULT 0,
  `current_confirmations` int unsigned NOT NULL DEFAULT 0,
  `required_confirmations` int unsigned NOT NULL DEFAULT 0,
  `error_message` text NULL,
  `retry_count` int unsigned NOT NULL DEFAULT 0,
  `last_retry_at` datetime NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_address` (`address_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `gas_funding_transactions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `network_code` varchar(64) NOT NULL,
  `from_address` varchar(128) NOT NULL,
  `to_address` varchar(128) NOT NULL,
  `amount_wei` varchar(100) NOT NULL,
  `tx_hash` varchar(128) NOT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'sent',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tx` (`tx_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `admin_id` bigint unsigned NOT NULL DEFAULT 0,
  `action` varchar(128) NOT NULL,
  `target_type` varchar(64) NOT NULL DEFAULT '',
  `target_id` bigint unsigned NOT NULL DEFAULT 0,
  `summary` varchar(512) NOT NULL DEFAULT '',
  `ip` varchar(64) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_action` (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `withdrawal_tasks` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `network_code` varchar(64) NOT NULL,
  `token_code` varchar(64) NOT NULL,
  `wallet_account_id` bigint unsigned NOT NULL,
  `from_address` varchar(128) NOT NULL,
  `to_address` varchar(128) NOT NULL,
  `amount_int` varchar(80) NOT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'pending_withdraw',
  `gas_funding_tx_hash` varchar(128) NOT NULL DEFAULT '',
  `withdraw_tx_hash` varchar(128) NOT NULL DEFAULT '',
  `actual_gas_used` bigint unsigned NOT NULL DEFAULT 0,
  `actual_gas_price_wei` varchar(100) NOT NULL DEFAULT '',
  `actual_gas_fee_wei` varchar(100) NOT NULL DEFAULT '',
  `withdraw_block_number` bigint unsigned NOT NULL DEFAULT 0,
  `current_confirmations` int unsigned NOT NULL DEFAULT 0,
  `required_confirmations` int unsigned NOT NULL DEFAULT 0,
  `error_message` text NULL,
  `retry_count` int unsigned NOT NULL DEFAULT 0,
  `max_retry_count` int unsigned NOT NULL DEFAULT 3,
  `last_retry_at` datetime NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_wallet_account` (`wallet_account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `system_settings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `key_name` varchar(128) NOT NULL,
  `value` text NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_key_name` (`key_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `open_api_clients` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `api_key` varchar(80) NOT NULL,
  `api_secret_hash` varchar(255) NOT NULL,
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `admin_users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(64) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `nickname` varchar(128) NOT NULL DEFAULT '',
  `status` varchar(32) NOT NULL DEFAULT 'active',
  `last_login_at` datetime NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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

INSERT IGNORE INTO `admin_users` (`username`, `password_hash`, `nickname`, `status`, `created_at`, `updated_at`) VALUES
('admin', '$2y$12$m3h/OgYsAFMIJB70Dgqsk.ivasPQlN5czHTa.MnT5ybzVd6SaVufm', '超级管理员', 'active', NOW(), NOW());

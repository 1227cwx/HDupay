# ⚠️ Important Disclaimer

English | [简体中文](README.zh-CN.md)

> **This project is intended solely for developers learning about blockchain, HD wallets, EVM stablecoin payment collection, and Webman application development.**
> This open-source project does not constitute investment advice, payment licensing advice, financial business advice, or a guarantee of production security. Users bear full responsibility for any deployment, modification, derivative development, payment collection, transfers, fund sweeping, third-party integration, or other use of this project. Its authors, maintainers, and contributors accept no responsibility for such activities.
> Do not use this project for illegal or non-compliant purposes. Before handling real assets, complete code audits, security hardening, permission isolation, private-key management, risk and compliance reviews, and thorough testing with small amounts.

---

# 💎 HDupay

<p align="center">
  <img src="https://img.shields.io/badge/PHP-8.4%2B-777BB4?style=for-the-badge&logo=php&logoColor=white" />
  <img src="https://img.shields.io/badge/Webman-2.x-00A98F?style=for-the-badge" />
  <img src="https://img.shields.io/badge/Workerman-5.x-2F80ED?style=for-the-badge" />
  <img src="https://img.shields.io/badge/Vue-3-42B883?style=for-the-badge&logo=vue.js&logoColor=white" />
  <img src="https://img.shields.io/badge/Naive_UI-Admin-18A058?style=for-the-badge" />
  <img src="https://img.shields.io/badge/MySQL-5.7%2B-4479A1?style=for-the-badge&logo=mysql&logoColor=white" />
</p>

**HDupay** is an open-source example platform for cryptocurrency stablecoin payment collection, built with **Webman + Vue3 + Naive UI**. It demonstrates:

- 🧠 Mnemonic-based HD wallet architecture
- 🔗 Payment address derivation across multiple EVM networks
- 💵 USDC / USDT order payments
- 📡 RPC block scanning, monitoring, and confirmation progress
- 🧾 OpenAPI and Epay-compatible interfaces
- 🏦 Collection wallets, Gas wallets, and automated fund sweeping
- 🎛️ A complete business workflow in the administration console

<p align="center">
  <a href="#features"><kbd>✨ Highlights</kbd></a>
  <a href="#networks"><kbd>🌍 Supported Networks</kbd></a>
  <a href="#currencies"><kbd>💵 Supported Currencies</kbd></a>
  <a href="#tech-stack"><kbd>🧱 Technology Stack</kbd></a>
  <a href="#modules"><kbd>🧩 System Modules</kbd></a>
  <a href="#requirements"><kbd>🖥️ System Requirements</kbd></a>
  <a href="#install"><kbd>🚀 Quick Installation</kbd></a>
  <br/>
  <a href="#access"><kbd>🔗 Access Points</kbd></a>
  <a href="#rpc-proxy"><kbd>📡 RPC and Proxies</kbd></a>
  <a href="#openapi"><kbd>🔌 OpenAPI</kbd></a>
  <a href="#epay"><kbd>🧩 Epay</kbd></a>
  <a href="#new-api"><kbd>🔗 New-Api Integration</kbd></a>
  <a href="#security"><kbd>🏦 Security Recommendations</kbd></a>
  <a href="#project-structure"><kbd>📁 Project Structure</kbd></a>
  <a href="#faq"><kbd>🛠️ FAQ</kbd></a>
</p>

---

<a id="features"></a>

## ✨ Highlights

| Module | Capability |
|---|---|
| 🔐 HD wallets | Initialize a root wallet from a mnemonic and derive network accounts and payment sub-addresses |
| 🌐 Multiple networks | Ethereum, Base, Celo, and Polygon EVM mainnets |
| 💰 Stablecoins | USDC and USDT support |
| 📦 Address pool | Dynamically assign one-time payment addresses to orders and freeze them on timeout |
| 📡 On-chain monitoring | Scan ERC20 Transfer logs through EVM RPC `eth_getLogs` |
| ✅ Block confirmations | Configure confirmation counts per network and display progress in the frontend |
| 🏦 Automated sweeping | Sweeping tasks, Gas funding, failure reasons, and retry counts |
| 🔌 OpenAPI | Public interfaces authenticated with an API Key / API Secret |
| 🧩 Epay compatibility | An Epay-style redirect entry point at `/submit.php` |
| 🧭 RPC management | Infura, Dwellir, and OnFinality support, with grouping, round-robin selection, and retries |
| 🧦 Proxy pool | HTTP / HTTPS / SOCKS5 proxies with enforced routing for bound RPC requests |
| 📈 Exchange-rate synchronization | Synchronize USDC / USDT prices against multiple fiat currencies through CoinGecko |
| 🎨 Administration UI | Vue3 + Naive UI administration console, payment page, and QR codes |

---

<a id="networks"></a>

## 🌍 Supported Networks

| Network | Chain ID | Native Gas Token | Status | Supported Stablecoins |
|---|---:|---|---|---|
| 🔷 Ethereum Mainnet | `1` | ETH | ✅ Supported | 🔵 USDC / 🟢 USDT |
| 🔵 Base Mainnet | `8453` | ETH | ✅ Supported | 🔵 USDC / 🟢 USDT |
| 🟡 Celo Mainnet | `42220` | CELO | ✅ Supported | 🔵 USDC / 🟢 USDT |
| 🟣 Polygon PoS Mainnet | `137` | POL | ✅ Supported | 🔵 USDC / 🟢 USDT |

> This project primarily targets **EVM networks**. Integrating non-EVM networks such as Tron or Solana requires additional address derivation, signing, scanning, and fund-sweeping implementations.

---

<a id="currencies"></a>

## 💵 Supported Currencies

### Cryptocurrencies

| Token | Name | Description |
|---|---|---|
| 🔵 USDC | USD Coin | One of the currently supported default stablecoins |
| 🟢 USDT | Tether USD | One of the currently supported default stablecoins |

### Fiat Exchange Rates

The exchange-rate module supports common fiat currencies, including:

`CNY`, `USD`, `EUR`, `CAD`, `AUD`, `JPY`, `HKD`, `GBP`, `SGD`

When an order is placed, the system calculates the stablecoin amount payable using prices synchronized in the administration console, rounding up to the smallest unit of the stablecoin.

---

<a id="tech-stack"></a>

## 🧱 Technology Stack

### Backend

| Technology | Purpose |
|---|---|
| 🐘 PHP 8.4+ | Runtime environment |
| ⚡ Webman 2.x | HTTP framework |
| 🚀 Workerman 5.x | Persistent processes and high-performance network services |
| 🌀 Swoole Event Loop | Coroutine event-loop support |
| 🗄️ webman/database | MySQL data access |
| 📡 Hyperf Guzzle | Coroutine-friendly HTTP client |
| 🔐 BitWasp Bitcoin | BIP39 / BIP32 functionality |
| 🧮 web3p/ethereum-* | EVM addresses, signatures, and transactions |
| 🧂 Sodium | Encryption of sensitive information |

### Frontend

| Technology | Purpose |
|---|---|
| 🟢 Vue 3 | Frontend framework |
| ⚡ Vite | Build tooling |
| 🎨 Naive UI | Administration UI component library |
| 🧭 Vue Router | Frontend routing |
| 🍍 Pinia | State management |
| 🎯 TypeScript | Type support |

---

<a id="modules"></a>

## 🧩 System Modules

```text
HDupay
├── 🎛️ Administration console /hdupay
│   ├── Overview
│   ├── RPC nodes / Network configuration / Proxy pool
│   ├── Wallet settings / Collection wallets / Gas wallets
│   ├── Transaction orders / Address pool / Sweeping records
│   └── API settings / Exchange-rate settings / System settings
│
├── 💳 Public payment page /pay
│   ├── Network selection
│   ├── Stablecoin selection
│   ├── QR code display
│   └── On-chain confirmation progress
│
├── 🔌 OpenAPI /api/v1
│   ├── Query available networks
│   ├── Create orders
│   └── Query order status
│
└── 🧩 Epay-Compatible Endpoint /submit.php
```

---

<a id="requirements"></a>

## 🖥️ System Requirements

| Environment | Version Requirement |
|---|---|
| PHP | **8.4+** |
| MySQL | **5.7+**, with 8.0+ recommended |
| Composer | **2.10+** |
| Node.js | Recommended 20+ |
| NPM | Recommended 10+ |
| Redis | Optional, but recommended |
| Linux | Recommended for production |

### Required PHP Extensions

Ensure that the following extensions are installed and enabled:

| Extension | Description |
|---|---|
| `swoole` | Recommended for the Webman coroutine event loop |
| `pdo` / `pdo_mysql` | MySQL database connections |
| `sodium` | Encrypt wallet mnemonics, API Secrets, and other sensitive information |
| `gmp` | HD wallet / elliptic-curve computation dependency |
| `bcmath` | High-precision arithmetic |
| `openssl` | Encryption and randomness |
| `curl` | Guzzle HTTP requests and proxy support |
| `mbstring` | String processing |
| `json` | JSON encoding and decoding |
| `ctype` / `filter` / `iconv` / `session` | Basic functionality required by Webman and its dependencies |
| `pcntl` / `posix` | Workerman process management on Linux |
| `opcache` | Recommended for production |
| `redis` | Recommended when Redis functionality is enabled |

Example checks:

```bash
php -v
php -m | grep -E "swoole|pdo_mysql|sodium|gmp|bcmath|openssl|curl|mbstring|pcntl|posix|redis"
composer -V
```

---

<a id="install"></a>

## 🚀 Quick Installation

### 1️⃣ Clone the project

```bash
git clone <your-repository-url> HDupay
cd HDupay
```

### 2️⃣ Install Backend Dependencies

```bash
composer install
```

For production, you can use:

```bash
composer install --no-dev --optimize-autoloader
```

### 3️⃣ Import the database

Prepare the target database (for example, `hdupay`), then import the project SQL file:

```bash
mysql -uroot -p hdupay < database/schema.sql
```

Next, update the database connection configuration:

```text
config/database.php
```

If this file does not exist, copy the example first:

```bash
cp config/database.example.php config/database.php
```

Update these values for your environment:

```php
'host'     => '127.0.0.1',
'port'     => '3306',
'database' => 'hdupay',
'username' => 'your_user',
'password' => 'your_password',
```

> ⚠️ Do not use weak passwords in production. Grant the database account only the permissions required for the application database.

---

## 🔐 Create the `.env` File

Create a `.env` file in the project root to store sensitive configuration.

If there is no `.env.example`, create it manually:

```bash
cat > .env <<'EOF'
# Required: encryption key for wallet data, API Secrets, and other sensitive information
# Generate with: php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
WALLET_ENCRYPTION_KEY=replace_with_a_64_character_random_hex_string

EOF
```

Generate a secure key:

```bash
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

You can also use another suitable cryptographic method to generate the key, then copy it into the file.

> 🔥 **Important:** Once the application is in use and encrypted data has been written, do not casually change `WALLET_ENCRYPTION_KEY`. Doing so may make existing encrypted sensitive information impossible to decrypt.

---

## 🎨 Frontend Installation and Build

> 💡 Note: A frontend build is not required for installation; compiled assets are already included. Rebuild only when you modify the frontend source in `web/`.

The frontend project is located in:

```text
web/
```

Install dependencies:

```bash
cd web
npm install
```

Development mode:

```bash
npm run dev
```

Build production assets:

```bash
npm run build
```

The compiled files are output to the following directory in the project root:

```text
public/
```

---

## ▶️ Start the Project

### Development mode

```bash
php webman start
```

Alternatively:

```bash
php start.php start
```

Default listening address:

```text
http://127.0.0.1:2828
```

### Daemon mode

```bash
php webman start -d
```

Common commands:

```bash
php webman status
php webman restart
php webman stop
```

For local Windows development, try:

```bash
php windows.php
```

---

<a id="access"></a>

## 🔗 Access Points

| Entry Point | Path | Description |
|---|---|---|
| 🎛️ Administration console | `/hdupay/login` | Administrator login |
| 💳 Payment page | `/pay` | Public payment collection page |
| 🔌 OpenAPI | `/api/v1` | API entry point |
| 🧩 Epay compatibility | `/submit.php` | Epay-style redirect entry point |
| 🛡️ Administration API | `/admin` | Administration API prefix |

Examples:

```text
http://127.0.0.1:2828/hdupay/login
http://127.0.0.1:2828/pay
```

---

### 🔐 Default Administrator

Importing `database/schema.sql` creates a default administrator account:

| Item | Value |
|---|---|
| Login URL | `/hdupay/login` |
| Username | `admin` |
| Password | `Admin@123456` |

> ⚠️ Immediately after your first login, open System Settings and change the administrator username and password.

---

<a id="rpc-proxy"></a>

## 📡 RPC and Proxies

Supported RPC providers:

| Provider | Support | Description |
|---|---|---|
| Infura | ✅ | Supports API Key Secret |
| Dwellir | ✅ | API Key mode |
| OnFinality | ✅ | API Key mode |

Supported proxy types:

- 🌐 HTTP
- 🔒 HTTPS
- 🧦 SOCKS5 / SOCKS5H

> If an RPC node is bound to a proxy, its requests must use that proxy and will not automatically fall back to a direct connection. This makes it possible to distinguish proxy failures from RPC failures.

---

<a id="openapi"></a>

## 🔌 OpenAPI Overview

OpenAPI uses the following entry point:

```text
POST /api/v1
```

Authentication:

```http
x-api-key:     your_api_key
x-api-secret:  your_api_secret
```

Endpoints:

| Endpoint | Method | Description |
|---|---|---|
| `/api/v1/networks` | POST | Query currently available payment collection networks |
| `/api/v1/orders/create` | POST | Create a payment order and return its payment link |
| `/api/v1/orders/status` | POST | Query payment status and progress as a percentage |

---

<a id="epay"></a>

## 🧩 Epay-Compatible Endpoint

The project provides an Epay-style entry point:

```text
GET/POST /submit.php
```

Description:

- `pid` reuses the OpenAPI API Key configured in the administration console.
- The signing key reuses the API Key Secret.
- The `type` field is accepted for compatibility, but the system does not use it to determine the payment method.
- On success, a redirectable payment page at `/pay?epay_order=...` is returned.

---

<a id="new-api"></a>

## 🔗 New-Api Integration

HDupay provides an Epay-compatible `/submit.php` entry point that can be integrated with the Epay payment channel in New-Api. Before integrating, add an API under API Settings in the HDupay administration console and obtain its API Key and API Secret.

### 1️⃣ Configure the Epay channel

Select the Epay configuration in the New-Api administration console and enter the API information created in HDupay:

| New-Api Setting | Value |
|---|---|
| Epay gateway / Endpoint URL | `https://your-hdupay-domain/submit.php` |
| PID / Merchant ID | API Key created in the HDupay administration console |
| Key / Communication Secret | API Secret created in the HDupay administration console |

![New-Api Epay configuration](docs/images/newapi-1.png)

### 2️⃣ Update the top-up method settings

Replace the New-Api top-up method settings with the following content. Copy it directly and save:

```json
[
  {
    "color": "black",
    "min_topup": "1",
    "name": "USDC/USDT",
    "type": "USDC/USDT",
    "icon": "data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAADAAAAAwCAYAAABXAvmHAAAAAXNSR0IArs4c6QAAA/ZJREFUaEPtWV1oFFcU/s7dXRPdiZJNRSsiihbxRayOsZZCa0UQUUEKfbDgT9GKL6K+FLRx7q5Y0BcLfZSCUmxfJAgqQRC0xSgmkyKCPojFgEhdiDZmdww1dY4Oychmnbl3Nrtm52EvhLBzz5z7fff8zr2EKkdBml8Q4aqnhhnZFmnLUpUFaUoiWN4zw7KpyuXeeb1qhQ0CVZqkYYGGC71PF/J2F4D3FzoYPF8QbR/LQn+8kb9WJuxlqc+9Zy7hMBj5BPgJOJGnJOen/u/kSd57OVEeyhgodQ/lAlwyW67Rm/Oe+f8DFBGhk5k6060fnKN9Xf9VQqY2BCpZUS17nxm/l9cS1StxIzCKlfgb40jfb1H2JZ4EwP0j/GpNq7zdryOhJyBG24Bxg8MD22snAhclXipASxlYqAM1agX8ZByxD+hkKy5kpb1NkPKgfqhUbji7cq1LvJUZ32rA3TYs++PYEfABOVnzHANfqQCmWwebad8DZVaadAv4gIfkyk8FcbeKQFLwouaOvr8nnIWCXqzWhUp1OlmzwIARBpAZa1qkXV4Yx4nXzQIeimLOvApFQkAyMds4fCsfWwsUc+YQGC0hAB8Zlj0vtkE8lGtfLdi9EQaQgAtpy94cWwLFnHkJjA2hBITYlO7ouRhLAs5RcwO7uFTt7o/WuwpHlCwU0FKDBM0FMJuYP1Hlf891KJHaO+2Hm4+jQKs5gSiLBsvQYzCfNaT9fSU66kngGcBdgOh6xcmuGfLms0qA+7L1JFCK9y0Zw+o9WwmRuBB4i5kI5wWJg1M7eh5GIVJ7AvTON7F3YpciIAMgQ4SM91sDbpBA29JW7wUdiZoT0PUvzrEVH7ojtEcQdjMwJxQg44oh7XWxI+ADcnLLVzALWwXQZXfndPnX6cnuhbQdpA+oKM39IJxUFLTutGV/FlsC/8pl81OUVAXrgGHZM2NLwAPmZM0Hqu9kkUjNVVXlSQ/i8t0sZs17AJaE7bIuKdSVwNCP7W1ixB1QuUiSxeJm2XNfESe6RDV+PkIzFzmIX2Tbt7hwO1UIhp2m6TNPdBdiR+C5XJ1J0kiP+pyI+w2rb0Hsgtg52r4RrvsdA5s09v/VsOxtEyYQdrzuX9oFKX4TVNLlwO+MWYLQxkRtYP4yiuMSsCtt2b9URcC/gYyyYG1l6LJh9a7X6dSfjY5doeoU1Xj+booTXzfJW16KVY7YEWDG9SlI7IkC3mMWGwIMFIhx6p+ng4c++ll9HlpqkroRIKAI4I7LuEOEP4edpouqfD+hOjCWhXQ+uMzvKF3mMwQa1/4yeId/CSgYqwRo4MWw+zRzvO+5zr+jzFfcSpQrbdwTR9lmhUzDAvV2odd2YUZPCPus4AAAAABJRU5ErkJggg=="
  }
]
```

![New-Api top-up method settings](docs/images/newapi-2.png)

### 3️⃣ User starts a top-up

When users click `USDC/USDT` on the New-Api top-up page, they are redirected to the HDupay payment page to complete their stablecoin payment.

![Starting a New-Api top-up](docs/images/newapi-3.png)

---

<a id="security"></a>

## 🏦 Wallet and Asset Security Recommendations

> Wallet functionality involves real on-chain assets. Use it with caution.

Recommendations:

- 🔐 Back up the root mnemonic offline. Never take screenshots or upload it to cloud storage.
- 🧊 Use a dedicated server, a separate database, and least-privilege accounts in production.
- 🧪 Before going live, test the complete payment collection, confirmation, and sweeping workflow with small amounts.
- 🧱 Add a firewall, administration access restrictions, HTTPS, a WAF, and log auditing.
- 🧾 Never embed RPC Keys, API Secrets, or wallet keys in code or commit them to the repository.
- 🔍 Obtain a professional security audit before real commercial use.

---

<a id="project-structure"></a>

## 📁 Project Structure

```text
HDupay
├── app/
│   ├── controller/        # Controllers
│   ├── service/           # Business logic
│   ├── model/             # Data models
│   └── process/           # Persistent processes: monitoring, sweeping, rate synchronization
│
├── config/                # Webman configuration, routes, processes, database, chain settings
├── database/              # schema.sql database structure
├── docs/                  # Documentation images and supporting materials
├── public/                # Compiled frontend assets and static resources
├── scripts/               # Data migration scripts
├── support/               # Webman support files
├── web/                   # Vue3 + Vite + Naive UI frontend project
├── webman                 # Webman command entry point
├── start.php              # startup entry point
└── README.md              # project documentation
```

---

<a id="faq"></a>

## 🛠️ FAQ

### 1. `WALLET_ENCRYPTION_KEY` Is Not Configured

Check that `.env` exists in the project root and contains:

```env
WALLET_ENCRYPTION_KEY=replace_with_a_64_character_random_hex_string
```

Restart after making changes:

```bash
php webman restart
```

### 2. RPC Test Fails

Check the following:

- Is the RPC URL correct?
- Is the API Key valid?
- Does the current network match the RPC endpoint?
- If a proxy is bound, is it available?
- Is the firewall blocking outbound requests?

### 3. Frontend Changes Are Not Visible

Rebuild the frontend:

```bash
cd web
npm run build
```

Then restart the backend service or refresh the browser cache.

### 4. On-chain transfer completed but order is unconfirmed

Check the following:

- Is the RPC node operating normally?
- Is automatic monitoring enabled in the network configuration?
- Is the contract address correct?
- Is the required confirmation count too high?
- Is the scanning step size appropriate?
- Does the order address match the on-chain receiving address?

---

## 📜 License

This project is released under an open-source license. See the project root for details:

```text
LICENSE
```

---

## ❤️ For Developers

If you are learning about:

- HD wallets
- EVM address derivation
- ERC20 payment monitoring
- Persistent Webman processes
- Vue3 administration systems
- OpenAPI payment interface design

HDupay can serve as a complete reference project for learning.
Remember: **Moving from a learning environment to production still requires substantial work on security, compliance, risk management, auditing, and operations.**

---

## 🌐 Community

This open-source project is linked with and recognizes the [LINUX DO](https://linux.do) community.


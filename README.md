# SyncBridge — PrestaShop ↔ AboutYou Sync Platform

Full-stack PHP + MySQL sync platform with a modern admin UI.

## Architecture

```
PrestaShop API
      ↓
SyncBridge DB (MySQL)   ← products, variants, images, orders, sync_logs
      ↓
AboutYou Seller Center API
```

All data lands in the local DB first. This means:
- Fast retries without re-fetching from PS
- Real-time per-product progress visible in UI
- Full audit history in sync_logs table
- Image files stored on your server, paths in DB

## Requirements

- PHP 8.1+
- MySQL 8.0+ or MariaDB 10.5+
- Composer 2+
- PHP GD extension (for image normalization)
- PHP PDO + pdo_mysql

## Installation

### 1. Clone and install dependencies

```bash
git clone <your-repo> syncbridge
cd syncbridge
composer install
```

### 2. Configure environment

```bash
cp .env.example .env
nano .env
```

Minimum required:
```
DB_HOST, DB_NAME, DB_USER, DB_PASSWORD
PS_BASE_URL, PS_API_KEY
AY_BASE_URL, AY_API_KEY, AY_BRAND_ID
```

### 3. Create database and run migrations

```bash
mysql -u root -p -e "CREATE DATABASE syncbridge CHARACTER SET utf8mb4"
php bin/migrate.php
```

This creates all tables and seeds the admin user (admin / admin123).
**Change the password immediately** via Settings → Users or direct SQL:
```sql
UPDATE users SET password_hash = ? WHERE username = 'admin';
-- generate hash in PHP: echo password_hash('yourpassword', PASSWORD_BCRYPT, ['cost'=>12]);
```

### 4. Configure web server

Point your web server document root to `public/`.
All requests to `index.php` and `api.php`.

Example nginx config:
```nginx
server {
    listen 80;
    server_name syncbridge.yourshop.com;
    root /path/to/syncbridge/public;
    index index.php;

    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

### 5. Create image output directory

```bash
mkdir -p public/ay-normalized
chmod 755 public/ay-normalized
```

### 6. Test the setup

```bash
php bin/sync.php status
```

## Usage

### Admin UI

Open `https://syncbridge.yourshop.com` in your browser.
Login: **admin** / **admin123** (change this!)

Panels:
- **Dashboard** — live stats, pipeline health, recent activity
- **Onboarding** — step-by-step setup wizard
- **Products** — full product list from DB, push selected to AY
- **Quality Check** — data quality scores, missing attributes
- **Images** — image normalization status, per-image details
- **Orders** — AY→PS imports, status push, quarantine management
- **Sync Engine** — run any sync command, watch live progress
- **Logs** — searchable structured logs from DB
- **Settings** — all config stored in DB and .env

### CLI commands

```bash
php bin/sync.php products          # Full product sync PS→DB→AY
php bin/sync.php products:inc      # Incremental (changed since last run)
php bin/sync.php stock             # Stock + price update
php bin/sync.php orders            # Import new AY orders → PS
php bin/sync.php order-status      # Push PS order states → AY
php bin/sync.php all               # Run stock + orders + order-status
php bin/sync.php status            # Show current stats
```

### Cron jobs

```cron
# Stock every 10 min
*/10 * * * * php /path/to/syncbridge/bin/sync.php stock >> logs/cron.log 2>&1

# Orders every 5 min
*/5 * * * * php /path/to/syncbridge/bin/sync.php orders >> logs/cron.log 2>&1

# Order status every 5 min
*/5 * * * * php /path/to/syncbridge/bin/sync.php order-status >> logs/cron.log 2>&1

# Incremental products every 15 min
*/15 * * * * php /path/to/syncbridge/bin/sync.php products:inc >> logs/cron.log 2>&1

# Full products daily at 2am
0 2 * * * php /path/to/syncbridge/bin/sync.php products >> logs/cron.log 2>&1
```

## Database Schema

| Table | Purpose |
|-------|---------|
| `users` | Admin accounts (bcrypt passwords) |
| `products` | PS products cached locally with AY style keys |
| `product_variants` | PS combinations / AY variants with SKUs |
| `product_images` | Image URLs, local paths, normalization status |
| `orders` | AY orders with PS order IDs and sync state |
| `order_items` | Individual line items with resolved PS product IDs |
| `sync_runs` | One row per sync invocation with progress counters |
| `sync_logs` | Structured log entries queryable from UI |
| `attribute_maps` | Color/size/attribute ID mappings |
| `settings` | Key-value config editable from UI |

## Safety Modes

```bash
# In .env:
TEST_MODE=true   # No writes to AY API
DRY_RUN=true     # No writes to PS or DB (reads only)
```

Toggle from Settings panel in UI.

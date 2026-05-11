<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## API Documentation (Swagger / OpenAPI)

This project uses `darkaonline/l5-swagger` with OpenAPI 3 for auto-generated API docs.

- UI endpoint: `/api/documentation`
- JSON spec endpoint: `/docs`
- Annotation scan path: `app/`
- Security schemes:
  - `bearerAuth` (`Authorization: Bearer <token>`)
  - `apiTokenHeader` (`X-Api-Token: <token>`)

### Setup

1. Install dependencies:
   - `composer install`
2. Configure environment values in `.env`:
   - `APP_URL=http://localhost:8000`
   - `SYNCBRIDGE_API_TOKEN=your-shared-token`
   - `L5_SWAGGER_GENERATE_ALWAYS=true` (dev)
3. Generate docs (manual refresh):
   - `php artisan l5-swagger:generate`
4. Start app and open:
   - [http://localhost:8000/api/documentation](http://localhost:8000/api/documentation)

### Notes

- Docs are organized by tags: `Products`, `Orders`, `Settings`, `Sync`, `Legacy`.
- Reusable schemas are defined in `app/OpenApi/OpenApiSpec.php`.
- Controllers contain operation-level annotations to keep docs aligned with implementation.

## SyncBridge (API-first vs legacy admin UI)

The legacy project in the repository root ships a full browser admin (dashboard, products, orders, logs, etc.). This Laravel app is **API-first**: there is no full feature parity with those panels. Operate it via Swagger, HTTP clients, and Artisan.

| Legacy panel (root README) | Laravel equivalent |
|----------------------------|-------------------|
| Dashboard / health | Open **`/`** for counts + env flags, or `GET /api/v1/products` / `php artisan syncbridge:run` (default `status`) |
| Products list / push | `GET /api/v1/products`, `GET /api/v1/products/{id}`, `POST /api/v1/sync` with `command: products` (requires token) |
| Orders | `GET /api/v1/orders`, `POST /api/v1/sync` with `orders` / `order-status` |
| Settings | `GET/POST /api/v1/settings` (`POST` requires token) |
| Sync engine / CLI | `POST /api/v1/sync` or `php artisan syncbridge:run <sync_command>` — same commands as `bin/sync.php` in the legacy tree |
| Logs / metrics | Query `sync_logs` / `sync_metrics` in MySQL, or inspect `storage/logs` and the sync log path from `LOG_PATH` |

**Cron (Laravel host):** use `php /path/to/laravel11/artisan syncbridge:run stock` (and the same substitutions as in the root README for `orders`, `order-status`, `products:inc`, `products`).

**Safety flags:** `dry_run` and `test_mode` rows in the `settings` table (seeded by `SettingsSeeder`, editable via `GET/POST /api/v1/settings` or the admin UI). With dry run on, the bridge performs PrestaShop/AboutYou reads and in-memory product preflight where applicable, but does not persist catalog/order changes, `sync_runs` rows, or call mutating APIs.

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

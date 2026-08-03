# Afghan China Shopping Center — Backend

Laravel API for the Afghan China Shopping Center MIS. Sanctum token auth,
`spatie/laravel-permission` RBAC, single-DB multi-tenancy via `company_id` +
a global `CompanyScope`, branch layer via `X-Branch-Id` + `BranchScope`.
SQLite (WAL) by default; the config stays driver-aware.

## Run locally

```bash
composer install
cp .env.example .env
touch database/database.sqlite
php artisan key:generate
php artisan migrate --seed
php artisan serve   # :8000
```

Seeded admin: `admin@afghanchina.af` / `password`.

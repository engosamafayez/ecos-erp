# ECOS ERP — Development Guide

## Quick Start

```bash
./scripts/setup.sh
```

Opens `http://localhost:8080` (Nginx + PHP-FPM) and `http://localhost:8025` (Mailpit).

The Vite dev server (HMR) runs separately:

```bash
cd frontend && npm install && npm run dev
# → http://localhost:5173/app/
```

---

## Administrator Credentials

| Field    | Value             |
|----------|-------------------|
| Email    | `admin@ecos.local` |
| Password | `Admin@123456`    |

These credentials are canonical. They are defined in three places that must stay in sync:

| Location | File |
|----------|------|
| Seeder   | `backend/database/seeders/AdminUserSeeder.php` |
| Reset command | `backend/Modules/IAM/Application/Commands/ResetDevAdminCommand.php` |
| This document | `DEVELOPMENT.md` |

### Resetting the admin password

If the password ever drifts (e.g. after running factory-based tests against the dev DB):

```bash
docker compose exec app php artisan ecos:reset-dev-admin
```

Re-running the seeder also restores it:

```bash
docker compose exec app php artisan db:seed --class=AdminUserSeeder --force
```

---

## Databases

| Database      | Purpose                          |
|---------------|----------------------------------|
| `ecos_erp`    | Development — live data          |
| `ecos_erp_test` | Test suite — wiped by PHPUnit  |

**Tests always run against `ecos_erp_test`**, never the dev database. This is enforced by `phpunit.xml` (`DB_DATABASE=ecos_erp_test`). The test database is created automatically on fresh MySQL init via `docker/mysql/init/01_create_test_db.sql`.

To create the test database on an existing container:

```bash
docker compose exec mysql mysql -u root -proot -e "
CREATE DATABASE IF NOT EXISTS \`ecos_erp_test\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON \`ecos_erp_test\`.* TO 'ecos'@'%';
"
```

---

## Running Tests

```bash
docker compose exec app php artisan test
# or
docker compose exec app php artisan test --parallel
```

Tests use `RefreshDatabase` and operate against `ecos_erp_test`. They never touch `ecos_erp`.

---

## Common Commands

```bash
# Migrations
docker compose exec app php artisan migrate

# Run all seeders (dev data)
docker compose exec app php artisan db:seed

# Seed only admin (safe to re-run)
docker compose exec app php artisan db:seed --class=AdminUserSeeder --force

# Reset admin password
docker compose exec app php artisan ecos:reset-dev-admin

# Tinker (REPL)
docker compose exec app php artisan tinker

# Tail logs
docker compose logs -f app
```

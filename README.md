# ECOS ERP — Enterprise Resource Planning

An enterprise-grade ERP platform built as a **Modular Monolith** following
**Clean Architecture**. This repository contains the full containerized stack —
backend, frontend, and infrastructure — orchestrated with Docker Compose.

> ⚙️ The project is in its **foundation phase**. The development environment and
> the architectural skeleton are in place; **no ERP business logic** is
> implemented yet.

---

## Technology Stack

| Layer         | Technology                               |
| ------------- | ---------------------------------------- |
| Backend       | Laravel 12 · PHP 8.4                      |
| Frontend      | React 19 · TypeScript · Vite             |
| Database      | MySQL 8.4                                |
| Cache / Queue | Redis 7                                  |
| Web server    | Nginx 1.27                               |
| Mail          | Mailpit (SMTP sink + web UI)             |
| Process mgmt  | Supervisor (PHP-FPM · queue · scheduler) |
| Orchestration | Docker · Docker Compose v2               |
| Tooling       | Composer · Node 22 · npm · Pint · ESLint |

**Application settings:** `APP_NAME = ECOS ERP` · Timezone `Africa/Cairo` · Locale `en`.

---

## Project Architecture

ECOS ERP is a **Modular Monolith**: a single deployable application internally
partitioned into independent **bounded-context modules**, each organized with
**Clean Architecture** layers. This gives the development speed and operational
simplicity of a monolith with the boundaries and testability of microservices —
without distributed-systems overhead.

### Architectural layers

```
            ┌──────────────────────────────────────────────┐
            │                 Presentation                  │  controllers, requests,
            │            (HTTP, CLI, React SPA)             │  resources, routes
            ├──────────────────────────────────────────────┤
            │                 Application                    │  use cases, commands,
            │        (orchestration, ports/interfaces)      │  queries, DTOs
            ├──────────────────────────────────────────────┤
            │                    Domain                      │  entities, value objects,
            │           (enterprise business rules)         │  domain events, services
            ├──────────────────────────────────────────────┤
            │                Infrastructure                  │  Eloquent, repositories,
            │        (framework, persistence, adapters)     │  external integrations
            └──────────────────────────────────────────────┘
                 Dependencies always point inward → Domain
```

### Building blocks

| Location                 | Namespace        | Responsibility                                            |
| ------------------------ | ---------------- | --------------------------------------------------------- |
| `backend/app/Core`       | `App\Core`       | Framework-agnostic domain primitives shared by all modules |
| `backend/app/Shared`     | `App\Shared`     | Shared Kernel — cross-module value objects & contracts    |
| `backend/app/Support`    | `App\Support`    | Technical utilities & framework glue (infrastructure)     |
| `backend/Modules`        | `Modules\*`      | Bounded-context modules (Inventory, Sales, …) — added later |
| `backend/tests/Architecture` | `Tests\Architecture` | Fitness functions enforcing the boundaries above   |

**Module dependency rules**
- A module may depend on `App\Core` and `App\Shared` — **never** on another
  module's internals.
- Cross-module communication happens through `Shared` contracts or domain events.
- Inside a module, dependencies point inward: Presentation → Application → Domain;
  Infrastructure implements the ports defined by Application/Domain.

See [`docs/architecture/`](docs/architecture/) for details.

---

## Folder Structure

```
ECOS-ERP/
├── backend/                      # Laravel 12 · PHP 8.4
│   ├── app/
│   │   ├── Core/                 # App\Core    — domain primitives (framework-agnostic)
│   │   ├── Shared/               # App\Shared  — shared kernel (cross-module)
│   │   ├── Support/              # App\Support — technical utilities / glue
│   │   ├── Http/  Models/  Providers/         # standard Laravel folders (unchanged)
│   ├── Modules/                  # Modules\*   — bounded-context modules
│   ├── bootstrap/ config/ database/ public/ resources/ routes/ storage/
│   ├── tests/
│   │   ├── Architecture/         # Tests\Architecture — boundary/fitness tests
│   │   ├── Feature/  Unit/
│   ├── composer.json  artisan  phpunit.xml  .env
│
├── frontend/                     # React 19 · TypeScript · Vite
│   ├── src/  public/  index.html  vite.config.ts  tsconfig*.json  package.json
│
├── docker/                       # Container build context & service configs
│   ├── php/    Dockerfile · php.ini · www.conf · supervisord.conf · entrypoint.sh
│   ├── nginx/  default.conf
│   └── mysql/  my.cnf
│
├── docs/                         # Documentation (organized by concern)
│   ├── architecture/             # system & software architecture
│   ├── engineering/              # process, milestone reports, conventions
│   ├── api/                      # API contracts / OpenAPI (placeholder)
│   └── database/                 # schema, ERDs, migrations (placeholder)
│
├── .github/
│   └── workflows/                # GitHub Actions CI/CD pipelines (placeholder)
│
├── scripts/                      # setup.ps1 · setup.sh
├── docker-compose.yml
├── .dockerignore  .gitignore  README.md
```

> Standard Laravel folders are left in place. The new `Core/`, `Shared/`,
> `Support/`, `Modules/`, and `tests/Architecture/` directories are registered
> via PSR-4 autoloading in `backend/composer.json`.

---

## Development Workflow

1. **Start the stack**
   ```bash
   docker compose up -d        # or ./scripts/setup.ps1  (Windows)
   ```
   App → http://localhost:8080 · Mailpit → http://localhost:8025

2. **Branch** off `main` using a conventional prefix:
   `feat/…`, `fix/…`, `chore/…`, `docs/…`, `refactor/…`.

3. **Develop**
   - Backend changes live-reload through the `./backend` bind mount.
   - Frontend hot-reload: `cd frontend && npm run dev` (http://localhost:5173).
   - New domain code goes into a module under `backend/Modules/{Module}` using
     the Clean Architecture layers; shared concepts go in `app/Core` / `app/Shared`.

4. **Quality gates (run before pushing)**
   ```bash
   docker compose exec app php artisan test     # PHP tests
   docker compose exec app ./vendor/bin/pint    # PHP formatting
   cd frontend && npm run lint && npm run build  # TS + ESLint + build
   ```

5. **Commit** using Conventional Commits (e.g. `feat: …`, `fix: …`) and open a PR.
   CI (under `.github/workflows/`) will enforce the quality gates.

---

## How to Run

### Prerequisites
- Docker Engine with **Docker Compose v2**
- Free host ports `8080`, `3306`, `6379`, `1025`, `8025`

### Quick start
```bash
docker compose build      # build images
docker compose up -d      # start the stack
docker compose ps         # watch services become healthy
```
The `app` entrypoint waits for MySQL, generates the app key (if missing), and
runs migrations on first boot.

---

## Useful Commands

| Task                | Command                                                |
| ------------------- | ------------------------------------------------------ |
| Start / stop        | `docker compose up -d` / `docker compose down`         |
| Service status      | `docker compose ps`                                    |
| Tail app logs       | `docker compose logs -f app`                           |
| Shell into app      | `docker compose exec app bash`                         |
| Artisan             | `docker compose exec app php artisan <cmd>`            |
| Run tests           | `docker compose exec app php artisan test`             |
| Format PHP          | `docker compose exec app ./vendor/bin/pint`            |
| Regenerate autoload | `docker compose exec app composer dump-autoload`       |
| Frontend dev server | `cd frontend && npm run dev`                           |
| Frontend build      | `cd frontend && npm run build`                         |
| MySQL CLI           | `docker compose exec mysql mysql -u ecos -psecret ecos_erp` |
| Redis CLI           | `docker compose exec redis redis-cli`                  |

---

## Documentation

- [Architecture](docs/architecture/) · [Engineering](docs/engineering/) ·
  [API](docs/api/) · [Database](docs/database/)

---

## License

Proprietary — © ECOS ERP Enterprise. All rights reserved.

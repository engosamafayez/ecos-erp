# GitHub Actions Workflows

CI/CD pipeline definitions for ECOS ERP live here as `*.yml` files.

**Planned workflows**
- `ci.yml` — build the app image, run `php artisan test`, `vite build`,
  Laravel Pint, ESLint, and TypeScript checks on every pull request
- `docker.yml` — build & publish the application image
- `deploy.yml` — environment deployments (staging / production)

> Each workflow file added here is picked up automatically by GitHub Actions.
> This README is a placeholder; no workflows are defined yet.

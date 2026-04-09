# Event Loader (reference)

This repository contains a minimal reference implementation scaffold for the event loader described in `spec.md`.

The full project specification is available in `spec.md`, including the key architectural decisions, tradeoffs, and implementation reasoning behind this solution.

The repository also includes container artifacts so the project can be started without running `composer install` manually on the host.

Quick start (after installing dependencies):

1. Start the local build with Docker Compose:

```bash
docker compose up -d
```

This uses the local source tree mounted into the PHP containers.

2. If you want to run a one-off command manually:

```bash
composer install
php bin/console app:event-loader --once
```

3. If you want to test the published image only, use the compose file in `test/`:

```bash
docker compose -f test/docker-compose.yml up -d
```

The `test/docker-compose.yml` file is intended to run against the public GitHub Container Registry image `ghcr.io/acosoft/capslock:latest`.

The repository also contains `.github/workflows/publish-image.yml`, which publishes that image to GHCR on pushes to `main` or via manual workflow dispatch.

Notes:
- This is a minimal scaffold. Concrete adapters for Redis, HTTP clients and databases are intentionally out of scope.


# Event Loader (reference)

This repository contains a minimal reference implementation scaffold for the event loader described in `spec.md`.

The full project specification is available in `spec.md`, including the key architectural decisions, tradeoffs, and implementation reasoning behind this solution.

## Specification

The full specification for this task is documented in `spec.md`.

That document is intended to make the solution process visible, not just the final requirements. It captures the architectural decisions, tradeoffs, open questions, and the reasoning used to shape the implementation.

GitHub Copilot was used to help structure and refine the specification, while the final technical decisions, scope interpretation, and implementation choices were kept aligned with the task requirements.

The repository also includes container artifacts so the project can be started without running `composer install` manually on the host.

## Local Development

Start the local build with Docker Compose:

```bash
docker compose up -d
```

This uses the local source tree mounted into the PHP containers.

If you want to run a one-off command manually:

```bash
composer install
php bin/console app:event-loader --once
```

To follow the runtime logs from both loader instances and Redis:

```bash
docker compose logs --no-color --tail=200 -f
```

## Test With Published Image

To test the published image only, use the compose file in `test/`:

```bash
docker compose -f test/docker-compose.yml up -d
```

The `test/docker-compose.yml` file is intended to run against the public GitHub Container Registry image `ghcr.io/acosoft/capslock:latest`.

The repository also contains `.github/workflows/publish-image.yml`, which publishes that image to GHCR on pushes to `main` or via manual workflow dispatch.

To follow logs in that setup, use:

```bash
docker compose -f test/docker-compose.yml logs --no-color --tail=200 -f
```

Environment variables:

- `LOADER_NAME`: logical loader instance name shown in logs, for example `L1` or `L2`
- `REDIS_DSN`: Redis connection string, default is `tcp://redis:6379`
- `THROTTLE_MS`: minimum time between two consecutive requests to the same source, in milliseconds
- `MAX_CALL_DURATION_MS`: lease TTL / expected upper bound for one fetch-and-store cycle, in milliseconds
- `LOOP_SLEEP_US`: pause between loop iterations and source attempts, in microseconds

## Example Logs

Example log output:

```text
L1: attempting to acquire lease for source Source1 owner L1
L1: lease acquired for source Source1 owner L1
L1: fetching events for source Source1 after 120
L1: fetched 16 events from source Source1 in 3000ms
L1: storing 16 events for source Source1
L1: stored 16 events for source Source1
L1: advanced cursor for source Source1 to 167
L1: released lease for source Source1 owner L1
L2: attempting to acquire lease for source Source1 owner L2
L2: throttle: skipping source Source1 for another 7001ms
```

This shows the expected coordination flow: one loader acquires the lease, fetches and stores a batch, updates the checkpoint, and another loader skips the same source when the throttle window is still active.

## Notes

This is a minimal scaffold. Concrete adapters for Redis, HTTP clients and databases are intentionally out of scope.


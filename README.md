# Event Loader (reference)

This repository contains a minimal reference implementation scaffold for the event loader described in `spec.md`.

Quick start (after installing dependencies):

1. Start redis and an app container:

```bash
docker-compose up -d
```

2. Inside the `app` container run:

```bash
composer install
php bin/console app:event-loader --once
```

Notes:
- This is a minimal scaffold. Concrete adapters for Redis, HTTP clients and databases are intentionally out of scope.
- The `Sym` agent persona is available at `.github/agents/Sym.agent.md`.

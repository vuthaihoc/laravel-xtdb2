# Testing

```bash
docker run -d --name xtdb-test -p 127.0.0.1:5435:5432 -p 127.0.0.1:8083:8080 ghcr.io/xtdb/xtdb:2.2.0-beta3
composer test        # Unit (no server) + Feature (XTDB on 127.0.0.1:5435)
composer test:known-issues   # XTDB issues the driver works around, asserting PostgreSQL behaviour: failures expected
composer phpstan
composer cs
```

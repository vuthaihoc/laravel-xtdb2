# Feasibility scratch

Throwaway experiments behind `PLAN.md`. Not part of the package.

```bash
# XTDB 2.2.0-beta3 (target) and 2.1.0 (last stable), data in tmpfs
docker run -d --name xtdb-beta3 -p 127.0.0.1:5435:5432 -p 127.0.0.1:8083:8080 \
    --tmpfs /var/lib/xtdb:uid=20000,gid=20000 ghcr.io/xtdb/xtdb:2.2.0-beta3
docker run -d --name xtdb-scratch -p 127.0.0.1:5434:5432 -p 127.0.0.1:8082:8080 \
    --tmpfs /var/lib/xtdb:uid=20000,gid=20000 ghcr.io/xtdb/xtdb:latest

# Raw PDO probes (pgwire behaviour)
XTDB_PORT=5435 php probes/probe1.php          # native vs emulated prepares, CRUD, transactions
XTDB_PORT=5435 php probes/probe2.php          # SQL features, DDL, information_schema, bitemporal
XTDB_PORT=5435 php probes/probe3.php          # typing: booleans, timestamps, nested data, PATCH
php probes/probe6.php 5435                    # missing tables/columns, CREATE TABLE

# Laravel prototype (illuminate/database 12): Eloquent, schema, transactions
cd prototype && composer install && XTDB_PORT=5435 php scenarios.php
```

`prototype/src/` is the minimal driver the scenarios run on: `Literal` inlines bindings as typed
XTDB literals, `XtdbGrammar` / `XtdbSchemaGrammar` hold the overrides found so far.

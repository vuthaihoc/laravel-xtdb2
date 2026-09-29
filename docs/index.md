---
layout: home

hero:
  name: Laravel XTDB
  text: An XTDB 2 driver for Laravel
  tagline: Eloquent, the query builder and migrations on a bitemporal database, over the PostgreSQL wire protocol.
  actions:
    - theme: brand
      text: Get started
      link: /docs/installation
    - theme: alt
      text: Bitemporal data
      link: /docs/bitemporal
    - theme: alt
      text: GitHub
      link: https://github.com/vuthaihoc/laravel-xtdb2

features:
  - title: Laravel as usual
    details: Eloquent models, relations, soft deletes, casts, pagination and migrations on XTDB's schemaless tables.
  - title: Valid time and system time
    details: Read rows as they were valid at any date, or as XTDB had recorded them; write scheduled changes and back-dated corrections.
  - title: Nothing is overwritten
    details: Every change is a new version. history() lists them, delete() ends a validity period, erase() removes data for good.
  - title: Honest about the differences
    details: Typed literals, client-side keys, read-or-write transactions, and a KnownIssues suite for every XTDB limit.
---

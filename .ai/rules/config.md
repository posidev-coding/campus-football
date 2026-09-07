---
paths:
  - 'config/**'
  - config/database.php
---

# Config

## Pulse buffers on Redis DB 2 and its default gate is backwards
Pulse ingest is `redis` in EVERY environment (defaulted in config/pulse.php, not only in .env) on connection `pulse` = Redis DB 2. Never move it to the `cache` connection (DB 1): `cache:clear` calls flushdb() there and is run deliberately, so buffered telemetry would be collateral damage. `pulse:work` must be running or nothing reaches MySQL — a stalled drain looks exactly like "no traffic"; it rides `composer dev` locally and needs a Cloud daemon in production.

Pulse ships a `viewPulse` gate answering `environment('local')` — open to every developer locally, CLOSED to everyone in production, i.e. exactly backwards. AppServiceProvider redefines it on `User::isAdmin()`; that define wins because package providers boot before application ones. CacheInteractions and Queues recorders are deliberately off (volume: every cache read, every job state transition).

## The cache refuses objects app-wide, so Pulse's dashboard needs the array store
`config/cache.php`'s `serializable_classes => false` is Laravel 13's gadget-chain default: every cache read is `unserialize(..., ['allowed_classes' => false])`, so ANY object written to a serializing store (redis, file, database) comes back as `__PHP_Incomplete_Class`. This is the MECHANISM behind the standing "never cache anything but a scalar or an array" rule — it is enforced by the framework, not by discipline, and it fails on the SECOND read, never the first.

It is GLOBAL, not per-store: `CacheManager::getSerializableClasses()` accepts the store's own config and ignores it. So a dedicated Redis store does not escape it, and the only serialization-free store is `array`.

Pulse caches each dashboard card's result as an object, so `PULSE_CACHE_DRIVER=array` is required or all nine cards fatal. Cost: card queries re-run on each 5s poll, and `pulse:restart` stops reaching a running `pulse:work` (the signal is a cross-process cache write) — restart the daemon directly instead. Do NOT fix this by relaxing `serializable_classes`; that trades the whole app's protection for one admin page.

## PDO::ATTR_TIMEOUT bounds reaching MySQL and nothing after it
`PDO::ATTR_TIMEOUT` maps to `MYSQL_OPT_CONNECT_TIMEOUT`, which bounds the TCP connect only. The server's greeting, the TLS handshake and auth are READS, and reads are bounded by the `mysqlnd.net_read_timeout` ini — default 86400, a day. So a scale-to-zero database that accepts the connection and then stalls before it speaks blocks with NO ceiling, which is how a single-row `feed_runs` INSERT was billed 15586ms in production (CFB-49).

Measured 2026-09-06, not reasoned: against a listener that accepts TCP and says nothing, a connect with `ATTR_TIMEOUT => 2` blocked past 120 SECONDS; the same connect under `php -d mysqlnd.net_read_timeout=3` failed at exactly 3.00s with `[2006] MySQL server has gone away`.

Do not "fix" a slow connect by lowering `DB_CONNECT_TIMEOUT` — it cannot reach this. pdo_mysql exposes no read-timeout attribute, so the app cannot declare the missing half in config at all; the only lever is that global ini, and it caps EVERY read on every connection, so it has to sit above the slowest legitimate query (`cfb:aggregate`, migrations) rather than above the wake. Laravel's connector retries a lost connect exactly once, so two attempts is the worst case for reaching the server — the only part that is bounded. Long form and the measurement live in the `mysql` docblock in this file; `DatabaseTimeoutTest` pins that no read-timeout attribute exists, and goes red if pdo_mysql ever grows one.

---
paths:
  - 'config/**'
---

# Config

## Pulse buffers on Redis DB 2 and its default gate is backwards
Pulse ingest is `redis` in EVERY environment (defaulted in config/pulse.php, not only in .env) on connection `pulse` = Redis DB 2. Never move it to the `cache` connection (DB 1): `cache:clear` calls flushdb() there and is run deliberately, so buffered telemetry would be collateral damage. `pulse:work` must be running or nothing reaches MySQL — a stalled drain looks exactly like "no traffic"; it rides `composer dev` locally and needs a Cloud daemon in production.

Pulse ships a `viewPulse` gate answering `environment('local')` — open to every developer locally, CLOSED to everyone in production, i.e. exactly backwards. AppServiceProvider redefines it on `User::isAdmin()`; that define wins because package providers boot before application ones. CacheInteractions and Queues recorders are deliberately off (volume: every cache read, every job state transition).

## The cache refuses objects app-wide, so Pulse's dashboard needs the array store
`config/cache.php`'s `serializable_classes => false` is Laravel 13's gadget-chain default: every cache read is `unserialize(..., ['allowed_classes' => false])`, so ANY object written to a serializing store (redis, file, database) comes back as `__PHP_Incomplete_Class`. This is the MECHANISM behind the standing "never cache anything but a scalar or an array" rule — it is enforced by the framework, not by discipline, and it fails on the SECOND read, never the first.

It is GLOBAL, not per-store: `CacheManager::getSerializableClasses()` accepts the store's own config and ignores it. So a dedicated Redis store does not escape it, and the only serialization-free store is `array`.

Pulse caches each dashboard card's result as an object, so `PULSE_CACHE_DRIVER=array` is required or all nine cards fatal. Cost: card queries re-run on each 5s poll, and `pulse:restart` stops reaching a running `pulse:work` (the signal is a cross-process cache write) — restart the daemon directly instead. Do NOT fix this by relaxing `serializable_classes`; that trades the whole app's protection for one admin page.

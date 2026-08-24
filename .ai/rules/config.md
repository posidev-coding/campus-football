---
paths:
  - 'config/**'
---

# Config

## Pulse buffers on Redis DB 2 and its default gate is backwards
Pulse ingest is `redis` in EVERY environment (defaulted in config/pulse.php, not only in .env) on connection `pulse` = Redis DB 2. Never move it to the `cache` connection (DB 1): `cache:clear` calls flushdb() there and is run deliberately, so buffered telemetry would be collateral damage. `pulse:work` must be running or nothing reaches MySQL — a stalled drain looks exactly like "no traffic"; it rides `composer dev` locally and needs a Cloud daemon in production.

Pulse ships a `viewPulse` gate answering `environment('local')` — open to every developer locally, CLOSED to everyone in production, i.e. exactly backwards. AppServiceProvider redefines it on `User::isAdmin()`; that define wins because package providers boot before application ones. CacheInteractions and Queues recorders are deliberately off (volume: every cache read, every job state transition).

---
paths:
  - 'app/Http/Middleware/**,bootstrap/app.php,app/Actions/RecordActivity.php'
---

# Middleware Actions

## Livewire's update route is named default-livewire.update, and a plaintext cookie needs the encrypt exception
Two traps found building the page-view sensor (CFB-70, 2026-09-06).

Livewire registers its endpoint as `default-livewire.update`, NOT `livewire.update`. A route-name filter written as `str_starts_with($name, 'livewire.')` matches nothing, so every component update walks through as a screen — silently, since the sensor keeps working and the number just reads high. Match Livewire route names with `str_contains`, and `route('livewire.update')` throws "Route not defined" in a test.

A cookie written by JavaScript arrives in plaintext, and `EncryptCookies` swallows the `DecryptException` and hands the middleware NULL — not an error, a permanent absence of data. Any such cookie must be listed in `$middleware->encryptCookies(except: [...])` in bootstrap/app.php.

Also: `Redis::purge()` does NOT re-read config — `RedisManager` snapshots its config array at construction, so `config()->set('database.redis.pulse.port', …)` changes nothing and a test written that way passes against a healthy Redis. Rebind the `redis` singleton with a fresh `RedisManager` to simulate an outage. And `redis-cli` needs the key prefix (`campus-football-database-`) to see anything Laravel wrote.

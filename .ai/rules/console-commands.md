---
paths:
  - 'app/Actions/**,app/Support/**,app/Console/Commands/**'
---

# Console Commands

## phpredis stringifies an array argument, and `signal` is reserved in MySQL 8
Two traps found while building the telemetry sensors (2026-08-24).

phpredis turns an ARRAY argument into the literal string "Array". `$redis->sadd($key, [$member])` silently adds one member named `Array` — and still returns 1 the first time, so the "was it new?" check looks like it works. Every subject deduped to the same subject and the nightly rollup found no days to roll up. Pass set members as SCALARS (`sadd($key, $member)`). Same shape applies to any phpredis call taking a member list.

`signal` is a RESERVED WORD in MySQL 8, like `STORED`. An unbackticked `signal` in a `selectRaw` is a 1064 syntax error, not a wrong answer. `ux_events` has both a `signal` and a `count` column, so its raw selects backtick both.

Telemetry writes NEVER break the product: RecordUxEvent and RecordClientError swallow every Throwable, and the `Queue::failing` hook swallows too (it runs inside the handler for something that already failed, so a throw there replaces the real exception with a bookkeeping one).

---
paths:
  - 'routes/**,app/Http/**'
---

# Http

## The /ops surfaces fail closed, live outside the web group, and render JSON
`/ops/telemetry` (read) and `/ops/workbook` (write) are the ONLY externally-reachable endpoints the AI layer adds, for a Claude Code routine with no database access. Registered from `bootstrap/app.php`'s `then:`, deliberately OUTSIDE the `web` group — no session, no cookies, and no CSRF exemption that somebody widens later. `bootstrap/app.php`'s `shouldRenderJsonWhen` covers `ops/*` because a 302 to a login page tells a machine nothing about a malformed payload.

`EnsureOpsToken` FAILS CLOSED: no `OPS_TOKEN` configured (or one under 32 chars) means 404, not 403 — a 403 tells a stranger there is something worth guessing at, and the naive version compares a null header to a null config and admits everybody. `hash_equals`, 401 on mismatch, `throttle:ops` (30/min by IP) runs BEFORE the token check.

The READ is `signed` as well as tokened; the WRITE is not (nothing hands the routine that URL, and `signed` does not cover a body). The signed URL cannot be hand-composed — `php artisan cfb:advisor-setup` prints it. Rotating `OPS_TOKEN` is the revocation path; the signature has no expiry and killing it would mean rotating `APP_KEY`.

The write reaches only `workbook_items` plus one `feed_runs` row: `status`, `position` and `source` in a payload are ignored, and the dismissal guard lives in `WorkbookItem::propose()` so it holds for every caller.

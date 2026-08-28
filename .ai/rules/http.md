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

## /ops/issues signs the fixed-path read and nothing else, on purpose
The issue routes (2026-08-28) add one signed GET and six unsigned POSTs. `signed` protects a URL that is HANDED to a client and then lives in a config file, a shell history and a log line — it was never doing authentication; the token is. A URL the client COMPOSES (`/ops/issues/CFB-12/claim`) gains nothing from a signature and cannot carry one, so every variable-path route is a WRITE, and writes were never signed. The cost is that the routes are enumerable by a token holder, who already reaches `/ops/workbook`.

The mitigation is SCOPE, not signing, and it is enforced by the ROUTING TABLE rather than a validator: routes are named after transitions (`claim`, `release`, `start`, `review`, `comment`), so the reachable set is exactly `planned → in_progress → in_review`. There is no create, no delete, no dismiss, no `position`, no arbitrary `PATCH {status}` and **no `done`** — merging earns Done and merging is a human's. `->where('issue', ...)` stops a traversal probe at the router.

`204` when nothing is ready (branch on the code, not on an empty body) and `409 {"result":"held","by":…}` on a double assign (a 200 with `claimed:false` invites a routine to carry on). The envelope key is `result`, never `status` — the issue keeps its own `status`. `pr_url` is pinned to `config('cfb.repo_host')`, because the panel renders it as a link an admin clicks.

## /ops/github authenticates the other way round, and is the ONLY path to Done
GitHub will not send `X-Ops-Token`, so the merge webhook (2026-08-28) sits outside the token group with its own `EnsureGithubSignature` — an HMAC over the RAW body against `GITHUB_WEBHOOK_SECRET`, with the same four failure modes as the token: unset means 404, under 32 chars counts as unset, `hash_equals`, 401 with no hint. Never re-encode the parsed body to verify: `json_encode(json_decode($body))` is not byte-identical to what GitHub signed.

It does not weaken "an agent can never reach Done" — a merge IS the human's answer. It acts only on `pull_request.merged`, matches `head.ref` against the STORED `branch` column (which is why that column is unique and never rewritten), moves that one issue, and writes nothing else. It records actor `github`, never the merging user: every payload carries a login and an email, and `actor` is the column that would break the no-identity guarantee.

Always 200, even on no match — GitHub retries a non-2xx, and every other branch in the repository comes through this door. Idempotent because `MoveWorkbookItem` writes an event only when the column actually changes.

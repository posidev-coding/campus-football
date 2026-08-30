---
paths:
  - 'routes/**,app/Http/Controllers/Ops/**,app/Support/RemoteBoard.php,app/Console/Commands/Issue*.php'
---

# Support Console Commands

## cfb:issue picks a board by config, and the composed reads are tokened but unsigned
CFB_BOARD_URL (config('cfb.board_url')) chooses the board for cfb:issue and cfb:issues: unset = the local table (unchanged default), set = that deployment's /ops/issues over HTTP through App\Support\RemoteBoard, with OPS_TOKEN in the X-Ops-Token header. NEVER add a fallback in either direction — a failed remote call is a non-zero exit naming the board, because writing to the local table instead is the original bug (a session commenting on a board nobody reads and being told it worked). RemoteBoardTest catches a fallback by asserting the local row did not move; reintroducing one turns 7 tests red.

Two consequences for the routing table. (1) "Every variable-path /ops/issues route is a WRITE" is no longer true: GET issues/{issue}/brief is a variable-path READ. The signing rule is unchanged and is what actually holds — signed protects a URL that is HANDED to a client, and a terminal cannot sign anything (the signature comes off the board's APP_KEY), so the composed reads (issues/ready, issues/{issue}/brief) are tokened and unsigned while the handed index stays signed. (2) Every variable path still carries a trailing verb, so there is deliberately NO route on issues/{issue} itself and a DELETE or PATCH there is still 404 — OpsIssueEndpointTest pins that. issues/ready is a fixed segment that only works because of it.

Remote refuses by name what has no route rather than dropping it: --effort/--label, done, ready, link, and every cfb:issues filter but --ready. A failed comment prints the note back on stdout (one JSON document under --json) — it is the one verb whose content exists nowhere else. The token is header-only and never enters a URL, argv or a message; transport-failure text is scrubbed on the way past.

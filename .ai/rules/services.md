---
paths:
  - 'app/Support/**, app/Services/**'
---

# Services

## Version the cache key of any long-TTL structured value
A day-class TTL (Brand::settings, TeamVenue at 86,400s) outlives a deploy, so changing the SHAPE of a cached array strands every reader on the old shape for up to a day — reads fatal on a missing key with no error at write time. Put a version token in the key itself (the standings `v2` convention) and bump it in the same commit that changes the shape; the old entries age out unread. Never rely on cache:clear at deploy: it also re-arms the mail/SMS budgets and the ESPN limiter.

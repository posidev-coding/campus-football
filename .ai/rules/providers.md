---
paths:
  - 'app/Providers/**'
---

# Providers

## The morph map is enforced; identity-map any model that already morphs by class name
Relation::enforceMorphMap in AppServiceProvider makes every unmapped model THROW on any morph write. The Conversation's topics use short aliases (game/team/group). User is identity-mapped (FQCN => class) on purpose: notifications, push_subscriptions and Pennant scopes already store 'App\Models\User', so a short alias would strand those rows behind queries that now say 'user', and no entry would break the writes entirely. If a new model starts morphing, map it here first — identity-map it if rows already exist with its FQCN.

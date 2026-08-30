---
paths:
  - 'app/Enums/UxSignal.php,app/Actions/RecordUxEvent.php,app/Listeners/**,resources/views/livewire/**'
---

# Listeners Views Livewire

## One emitter per funnel signal, and auth-lifecycle signals ride the event
A UxSignal emitted from two screens stops measuring what it is named after. `onboarding_registered` was emitted only by the overlay wizard, so it counted wizard completions, not registrations — every /register and header Sign up account was invisible, and the step agreed dead-on with the two wizard steps after it (5/5/5) because those were the only people it could see.

Count a signal that corresponds to an auth lifecycle fact at the EVENT, not in a screen: `App\Listeners\CountRegistration` on `Registered`, sibling of `GrantVerificationReward` on `Verified`. Both are plain classes in app/Listeners, auto-discovered — no wiring in AppServiceProvider (its closures are for app-domain events). A listener counts by construction, so a third registration path is counted without anybody remembering to.

Use `handle()`, not `handleOnce()`, when the event itself is the once — a dedupe key would put a user id into a pipeline that deliberately has none. `UxFunnelTest` sweeps app/ and resources/views/ and fails if the signal is emitted from more than one file; if you are adding a second emitter, move it to the event instead.

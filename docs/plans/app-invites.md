# Codeless app invites — a /join link that isn't a group

## Context

Today the only way to invite anybody is a **private group's** 8-character code
(`CreateGroup`, `/join/{CODE}?by=handle`). That is a real gap on two fronts:

- **Private groups cannot play Week 0** — an 8-game Saturday cannot field one —
  so on rehearsal day the only invite the product offers points at the one thing
  a new person cannot use.
- **Public rooms are deliberately not invitable.** `group.blade.php:411`:
  *"Never rendered for lobbies: rooms are joined from the lobby, not by
  invitation."* That reasoning is sound and stays — a room code would go stale
  weekly and could point at a filled or dead room. But it leaves **no link at
  all** for "come play this thing with me," which is the message the inviter
  actually wants to send.

The goal is a codeless personal invite: a link carrying only the inviter's
handle, landing on a pitch, then through to the lobby. Plus a share affordance
that composes a personalized message and hands it to the OS share sheet, so the
SMS arrives **from the inviter's own number** — which is what makes "Taylor is
inviting you" credible to the person receiving it.

**Decisions locked with the user — do not re-ask:**

| Question | Decision |
|---|---|
| When | **2026-08-29**, ahead of the Aug 30–31 slot the launch plan reserves for the invite-path fix |
| SMS | **OS share sheet only.** `navigator.share()` is already wired; no server-side Vonage |
| Admin by invite | **No.** Invite to the app, promote in the panel — two deliberate acts |
| Landing | **Codeless `/join` pitch page** |

## Two things to know before starting

**1. This link does nothing for a guest until the flip.** `join.blade.php`'s
`mount()` bounces anyone for whom `Feature::active('pickem')` is false, and the
flag is admin-only until Tue 2026-09-01. A guest tapping a codeless invite
before then lands on `pickem.home`. **This is correct and must not be "fixed":**
the flag gating the whole pick'em surface pre-flip is exactly what the
closed-flag rehearsal is proving. The link becomes useful at the flip.

So the second-admin path is unchanged and needs no code: they register at
`/register`, verify, claim a handle, then Panel → Users → **Make admin**, then
`php artisan pennant:purge pickem`.

**2. Do not add a `UxSignal` case.** The enum is bounded on purpose —
*"eight named signals, and nothing else may be counted… an enum makes the
vocabulary a code review."* `InviteOpened` already fires in `mount()` **before**
the group lookup, so it counts codeless opens for free.

## Approach

Extend the **existing** join screen rather than building a new one. It already
does almost all of this: the flag check, the `?by=` handle validation, the
`InviteOpened` signal, the register-not-login redirect with the `url.intended`
machinery, and a `group()` computed that **already returns `null` on an empty
code** (`join.blade.php:77`). The only genuinely new thing is a third template
branch and the copy for it.

### 1. Route — `routes/web.php:54`

Make the code optional: `Route::livewire('join/{code?}', 'join')`. One route,
one name, so every existing `route('pickem.join', ['code' => …])` call is
untouched and `route('pickem.join', ['by' => $handle])` yields `/join?by=…`.

### 2. `resources/views/livewire/join.blade.php`

- `mount(string $code = '')`.
- Add `#[Computed] isAppInvite(): bool` → `$this->code === ''`.
- **Template gets three branches, not two.** Today it is
  `@if ($this->group === null)` → the "Invite not found" miss card. A codeless
  visit must **not** land there — that copy tells you to ask your friend for a
  fresh link. Order: app invite → miss → group preview. Keep the miss branch
  exactly as it is; it is a regression guard, not dead code.
- Reuse the existing inviter line (`@{{ handle }} invited you`,
  `join.blade.php:233`) verbatim — it already renders from `$this->inviter`.
- Add a `start()` action for the app-invite CTA. Mirror `join()`'s guest arm
  (`join.blade.php:170-185`): `session()->put('url.intended', …)` by hand, then
  `redirectRoute('register')` — **register, not login**, for the reason already
  documented there. Difference: `url.intended` is the **lobby**, not back here.
  There is nothing to be seated into, so the destination is the destination. An
  authenticated visitor goes straight to `pickem.lobby`.

### 3. Share affordance — `resources/views/livewire/lobby.blade.php`

Lift the Alpine block from `group.blade.php:826-850` almost verbatim:
`window.cfbClipboard.copy()`, `navigator.share()` with the existing `canShare`
detection, copy-link and Share buttons. Two differences: the URL is
`route('pickem.join', ['by' => auth()->user()?->handle])`, and there is **no
code fallback** — a codeless invite has no code to read aloud.

The lobby is the right home: it is the walk-on destination, and the voice
already frames it that way (`'No invite? Public lobbies take walk-ons.'`).
Account is a reasonable second entry point later; one is enough for this pass.

### 4. `app/Support/Voice.php` — four keys, three registers each

Non-negotiable per `CLAUDE.md`. Model them on the existing
`groups.invite.hint` / `groups.invite.share_text` pair (`Voice.php:851-864`).

- `join.app.heading` — what this link is
- `join.app.body` — the pitch, for a guest who has never heard of the app
- `join.app.hint` — beside the share buttons on the lobby
- `join.app.share_text` — **rides the share sheet beside the URL**, and carries
  the personalization: interpolate `:inviter` from the sharer's first name.
  Note this one renders in the **sharer's** register, since they are composing
  it, not receiving it.

Pick'em is a LOUD area — all three registers get real writing, and the roast is
aimed at the pick, never the person.

### 5. Tests

- **`tests/Feature/InviteTest.php`** — codeless `/join` renders the app pitch
  and **not** the miss card, for a guest and for an authed user; `?by=handle`
  renders the inviter line and an unknown handle renders nothing; a *dead code*
  still renders the miss card (the branch-order regression guard); the flag
  being closed still redirects a guest to `pickem.home`.
- **`tests/Feature/PickemVoiceTest.php`** — the dataset iterates `Voice`'s
  `$lines` automatically, so the new keys are picked up, but the shared
  replacement set (`PickemVoiceTest.php:52`) must gain an `inviter` value or
  `join.app.share_text` fails on the unreplaced token.
- `ChromeConsistencyTest` already guards the house vocabulary — no
  `<flux:select>`, nothing new scrolling horizontally.

## Explicitly out of scope

- **No admin-conferring invite.** `admin` stays outside `$fillable`;
  `toggleAdmin` stays the only sanctioned write. An invite token that conferred
  admin would be a second privilege-escalation path, reachable by anyone who
  sees the message.
- **No server-side SMS.** `User::canReceiveSms()` requires an opted-in,
  phone-verified *existing* user; the Vonage path has a daily budget and an
  opt-out webhook built around consented users. There is no path to a stranger's
  number, and building one is a compliance posture change, not a quick feature.
- **No room codes.** Rooms stay joined from the lobby.
- **No new `UxSignal` case.**

## Verification

1. `php artisan test --compact --filter="InviteTest|PickemVoiceTest|ChromeConsistency"`
2. Full suite — **and the tree must hold still for the whole run**; concurrent
   `artisan test` invocations share `campusfootball_test` and corrupt each
   other's schema, which produced two false red readings on 2026-08-29.
3. `vendor/bin/pint --dirty --format agent`
4. `npm run build` — Blade changed, so new Tailwind utilities are missing at
   runtime without it and it will read as a design bug.
5. Device pass at real widths, via the harness not a resized window:
   `/__device?path=/join&w=390,768&h=800` and the same for `/lobby`.
6. **Break it back:** point the app-invite branch at a non-empty code and
   confirm the miss-card test goes red. The branch-order bug is the one this
   change can actually introduce, and a template test passes for the wrong
   reason easily.
7. By hand as admin: lobby → Share → confirm the composed message names the
   sharer and the URL is `/join?by=<handle>`. Then open that URL signed out and
   confirm it bounces to `pickem.home` while the flag is closed — that bounce is
   the feature working, not failing.

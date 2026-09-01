# Launch comms — the three sends, September 2026

The messages that go out with the flip. The app now generates the FACTUAL
half of each of these itself (group, mode, rules, deadline, link, code) —
open any group's clubhouse → Standings → Invite → **Ready to send**. What is
written below is the half the app cannot write: why *this* person is asking
*these* people.

Nothing here is sent by the app. Every one of these goes out from a human's
own number, Slack account or inbox, which is the only reason anybody will
open it. The app has no path to a stranger's phone or address and is not
getting one — see `docs/plans/app-invites.md` for that decision, and
`docs/operations.md` for why A2P 10DLC makes it a compliance posture rather
than a feature.

---

## 1. Log In, Vol Out — text message

**Send from:** your phone, one thread or individually.
**Link:** https://campusfootball.net/join/WDSQVFOX?by=taylor

The app's **Text message** template is fine as-is, but for people who know
you it should sound like you. Either works:

> Been building a college football pick'em app for a while and it finally
> works. Made a group for us this season — Log In, Vol Out.
>
> You pick every game against the spread, it scores itself, it keeps the
> standings, and it talks a fair amount of trash. Free, no money in it.
>
> Takes about two minutes: https://campusfootball.net/join/WDSQVFOX?by=taylor
>
> Heads up, it'll email you to confirm your address — you have to click that
> before you can make picks.

**Send it from the app if you can**: the clubhouse's Invite → **Share** hands
it to the OS share sheet, so it arrives from your own number with the link
already attached. That is what makes "Taylor is inviting you" credible.

---

## 2. VOLS 101: No Prerequisites — Slack

**Send from:** your Slack account, in the existing private channel.
**Link:** https://campusfootball.net/join/3HARBGTA?by=taylor

Read on a desktop, joined on a phone — so post the **QR** with it. The
clubhouse's invite panel renders one; screenshot it and attach it.

> Hey all — personal ask, not a work thing.
>
> I've been building a college football pick'em app on and off since 2016.
> This is the fourth time I've built it. The first three didn't survive, for
> ordinary reasons — mostly that keeping a league scored by hand is a job
> nobody wants by week six.
>
> This one works, and this is the first season it's open to anyone but me.
>
> There's a group set up for us: **VOLS 101: No Prerequisites**. Every week
> you pick games against the spread before the deadline. It scores itself,
> ranks everybody and settles the week on its own — no spreadsheet, nobody
> chasing you for picks.
>
> ⬇️ *[paste the app's **Slack post** template here — it fills in the game,
> the rules, the deadline and the link for this group]*
>
> What I'm actually asking for is people playing it while the season is on,
> and telling me when something is broken, slow or confusing. I'd like to put
> it on the app stores next season, and I'd rather find the rough edges now,
> with people who'll tell me straight.
>
> It's free, there's no money in it, and you can stop any week you want. No
> hard feelings if it's not your thing.
>
> It's built for a phone — scan this and it'll take you straight in:
>
> *[attach the QR from the invite panel]*
>
> One thing that trips people up: it emails you to confirm your address, and
> you have to click that link before you can make picks.

**Why it reads the way it does** — you asked for concise and humble, so it
says the first three attempts failed, states the ask plainly, and never
claims the thing is good. The one number in it (2016) is the only credential
it offers, and it is offered as duration rather than achievement.

---

## 3. Behind the Woodshed — email

**Send from:** the new commissioner's own inbox, after the handoff.
**Link:** https://campusfootball.net/join/RQUZXKLZ?by=taylor

Hand the seat over first: clubhouse → Standings → the member's row →
**Make commissioner**. Then they own the slate and this email is theirs to
send. (Until 2026-09-01 there was no way to do that at all — the seat could
not move and the commissioner could not leave.)

The app's **Email** template carries the rules recap. This is the version
with the history in it.

> **Subject: The Woodshed is back, for good**
>
> Ten years ago this group ran a pick'em league off a rules email and a
> spreadsheet. It died the way those always die: somebody has to grade
> fifteen games every week, and eventually nobody does.
>
> It's back. This time nobody grades anything.
>
> Taylor rebuilt it, and the mode we play is called **The Woodshed** — named
> after this group, on purpose. There are three ways to play in the app and
> that is the one that came out of our 2016 rules email. Same tiers, same
> Lock, same Bear.
>
> **THE RULES — unchanged**
>
> - 15 games against the spread, in three tiers of five: 8, 6 and 4 points.
> - **The Lock.** One a week, on the featured game. +6 if you're right, −4 if
>   you're wrong. Still optional — leave it alone and the game just scores
>   like any other tier one.
> - **The Bear.** Still picking every game on a theme, still ineligible for
>   Locks. Beat his total outright and take 5 points.
> - **Tiebreaker.** Your over/under call on the featured game, same as always.
> - A perfect week is **101**. Every other mode in the app tops out at 100.
>   Ours keeps the extra point.
>
> **WHAT'S DIFFERENT**
>
> - **Picks are due Thursday at noon ET**, not Saturday at noon. The week
>   turns over Tuesday, the card goes up Thursday.
> - **No money, no divisions, no playoff.** Weekly scores and season
>   standings only. Those parts of 2016 weren't rebuilt — if we want them
>   back, that's a conversation, not a missing feature.
> - Scores update on their own while the games are on.
>
> **YOUR SEAT**
>
> https://campusfootball.net/join/RQUZXKLZ?by=taylor
>
> That link does the whole thing — makes your account and drops you into the
> group. If you'd rather do it by hand, sign up and enter code **RQUZXKLZ**.
>
> It will email you to confirm your address. You have to click that before
> you can make picks; it's the one step people skip.

**Do not send this through the app.** These are addresses that never signed
up for anything, and the sending domain (Cloudflare Email Service, in beta,
100/day budget) is the same one every verification email rides. Burning its
reputation on a cold blast would break the one message that has to arrive.

---

## Before any of this goes out

1. `php artisan pennant:purge pickem` on production. Pennant's database
   driver persists resolved values, so anyone who loaded a page while the
   flag was closed still sees the teaser until this runs.
2. Confirm the unfurl once, before pasting into Slack:
   `curl -s https://campusfootball.net/join/3HARBGTA | grep 'og:'`
3. Scan the QR with a real phone camera. An emulator screenshot proves
   nothing about a camera.

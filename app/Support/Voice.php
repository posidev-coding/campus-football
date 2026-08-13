<?php

namespace App\Support;

use App\Enums\ContentRating;
use App\Models\User;

/**
 * Copy that changes register with the reader.
 *
 * One resolver rather than `match ($rating)` scattered through Blade: three
 * levels across a growing pile of strings does not survive inline ternaries in
 * views, and a single map is also the only place you can see all three variants
 * of a line side by side — which is how you catch PG being written as a
 * punishment.
 *
 * Resolution falls DOWN the ladder, never up. `ContentRating::includes()`
 * already encodes that an R reader may be shown PG copy while a PG reader must
 * never see PG-13, so a line that only defines `pg` is safe to add and a line
 * that only defines `r` simply never reaches the people who did not ask for it.
 *
 * What belongs here: subtext, empty states, limits, confirmations — anywhere
 * the app is talking TO somebody. What does not: format rules and field labels,
 * where a joke between the reader and the instruction is just friction, and
 * anything on Scores or League, which report facts rather than address a person.
 */
class Voice
{
    /**
     * @var array<string, array<string, string>>
     */
    private const LINES = [
        'teams.subheading' => [
            'pg' => 'Pin your favorite. Their news leads your home page.',
            'pg13' => "Pin your favorite. They'll headline your home page — good week or bad.",
            'r' => "Pin your favorite. You'll hear about them either way.",
        ],

        'teams.empty' => [
            'pg' => 'No teams yet. Search above to add your first.',
            'pg13' => 'Nobody yet. Pick a team — bandwagons welcome.',
            'r' => "Empty. Pick somebody, even if you'll regret it by November.",
        ],

        'teams.no_matches' => [
            'pg' => 'No FBS team matches ":query".',
            'pg13' => 'No FBS team called ":query". Check the spelling.',
            'r' => 'Nobody in FBS answers to ":query".',
        ],

        'teams.at_limit' => [
            'pg' => 'All :max spots used — unfollow one to add another',
            'pg13' => "Roster's full at :max. Cut somebody first.",
            'r' => "Bench is full at :max. Somebody's getting cut.",
        ],

        'follow.limit' => [
            'pg' => 'You can follow up to :max teams. Unfollow one to make room.',
            'pg13' => "That's :max teams, which is your limit. Drop one to add another.",
            'r' => "You're at :max. Something's gotta give.",
        ],

        'home.follow_prompt' => [
            'pg' => 'Follow a team and their games, news and trends lead this page.',
            'pg13' => 'Follow a team and this page starts working for you — games, news and trends, front and center.',
            'r' => 'This page is better with a team on it. Pick yours and their games, news and trends take over.',
        ],

        'home.first_team' => [
            'pg' => 'Pick your team and this page fills in — records, trends, next games.',
            'pg13' => 'Pick your team. Records, trends, next games — all of it lands right here.',
            'r' => 'Pick your team. Records, trends, and every upcoming disaster — right here.',
        ],

        'home.another_team' => [
            'pg' => 'Room for :remaining more. Add a rival, or a team you just like watching.',
            'pg13' => 'Room for :remaining more. Add a rival — someone has to lose.',
            'r' => 'Room for :remaining more. Add a rival you enjoy watching suffer.',
        ],

        /*
         * The front door speaks to two audiences with two different promises.
         * A GUEST is being sold the whole app — signup included — and has no
         * content rating, so `line()` falls back to PG-13, the right register
         * for a first impression anyway. A signed-in reader with no teams is
         * being sold one thing: putting their favorite up top.
         */
        'onboarding.guest.heading' => [
            'pg' => 'Your teams, your season',
            'pg13' => 'Your season starts here',
            'r' => 'Your season starts here. Yes, even yours.',
        ],

        'onboarding.guest.body' => [
            'pg' => "Follow your teams, watch every score live, and be first in line for Pick'em. Signing up takes about a minute.",
            'pg13' => "Live scores, your teams up top, and Pick'em on the way. A minute to sign up, a whole season to argue about it.",
            'r' => "Live scores, your teams up top, Pick'em on the way. One minute to sign up, a whole season to be wrong in public.",
        ],

        'onboarding.member.heading' => [
            'pg' => 'Add your favorite team',
            'pg13' => 'Put your team up top',
            'r' => 'Rep your team already',
        ],

        'onboarding.member.body' => [
            'pg' => 'Pick your favorite and they lead your home page — records, trends, next games.',
            'pg13' => 'Your favorite headlines this page — records, trends, next games, good week or bad.',
            'r' => 'Your favorite headlines this page — records, trends, next games, and nowhere to hide in November.',
        ],

        /*
         * The three counted headings — short, because the bar above them is
         * already saying "you're nearly done" and a long heading argues with
         * it. Rating keeps its full question: it is the one screen where the
         * question IS the personality.
         */
        'onboarding.name' => [
            'pg' => 'What should we call you?',
            'pg13' => 'Easy one first — your name.',
            'r' => 'Name first. The grief needs an addressee.',
        ],

        'onboarding.rating' => [
            'pg' => 'How much grief should this app give you?',
            'pg13' => 'How much grief can you take?',
            'r' => 'How much grief can you take? Be honest.',
        ],

        'onboarding.credentials' => [
            'pg' => 'Last step — email and a password.',
            'pg13' => 'Last one — email and a password.',
            'r' => 'Last one. Somewhere to file your terrible opinions.',
        ],

        /*
         * The signup splash — the branded beat between finishing the wizard
         * and landing on Home, wearing a fake to-do list while it holds the
         * screen. `:team` is the mascot ("Tar Heels"), `:place` the school
         * ("North Carolina") — and when the reader skipped the picker, both
         * resolve to Bandwagon State, which writes its own jokes.
         */
        'splash.warmup.greet' => [
            'pg' => 'High-fiving :team...',
            'pg13' => 'High-fiving :team...',
            'r' => 'Chest-bumping strangers in :team colors...',
        ],

        'splash.warmup.travel' => [
            'pg' => 'Road-tripping to :place...',
            'pg13' => 'Road-tripping to :place...',
            // Reads right whether :place is a stadium ("Neyland Stadium"),
            // a school ("Tennessee"), or the bandwagon's home field.
            'r' => 'Tailgating outside :place since dawn...',
        ],

        'splash.warmup.field' => [
            'pg' => 'Painting the end zones...',
            'pg13' => 'Painting the end zones...',
            'r' => 'Painting the end zones. Both of them.',
        ],

        'splash.warmup.song' => [
            'pg' => 'Practicing the fight song...',
            'pg13' => 'Learning your fight song...',
            'r' => 'Butchering your fight song...',
        ],

        'splash.warmup.latte' => [
            'pg' => 'Chilling the Beast Lattes...',
            'pg13' => 'Icing down the Beast Lattes...',
            'r' => 'Hiding the good Beast Lattes...',
        ],

        /*
         * The boot splash's deck — the cold-start beat every launch of the
         * installed app opens on. A different pool from the warmup arc on
         * purpose: warmup is ORDERED signup theater told once, this is a
         * SHUFFLED deck seen hundreds of times, so the lines are generic
         * gameday logistics (no :team, no :place — a guest cold start has
         * nobody to name) and none may echo a warmup line. Roast a score or
         * a program, never a person.
         */
        'splash.boot.gates' => [
            'pg' => 'Opening the gates...',
            'pg13' => 'Opening the gates early...',
            'r' => 'Opening the gates before security is ready...',
        ],

        'splash.boot.chains' => [
            'pg' => 'Moving the chains...',
            'pg13' => 'Moving the chains...',
            'r' => 'Moving the chains. The spot stands.',
        ],

        'splash.boot.headsets' => [
            'pg' => 'Untangling the headsets...',
            'pg13' => 'Untangling the headsets...',
            'r' => 'Untangling whatever the coordinator did to the headsets...',
        ],

        'splash.boot.scores' => [
            'pg' => 'Rounding up the scores...',
            'pg13' => 'Rounding up the scores...',
            'r' => 'Rounding up the scores. Some of them should be ashamed.',
        ],

        'splash.boot.turf' => [
            'pg' => 'Chalking the sidelines...',
            'pg13' => 'Chalking the sidelines...',
            'r' => 'Chalking the sidelines nobody stays behind...',
        ],

        'splash.boot.replay' => [
            'pg' => 'Warming up the replay booth...',
            'pg13' => 'Warming up the replay booth...',
            'r' => 'Warming up the replay booth. The call still stands...',
        ],

        'onboarding.favorite' => [
            'pg' => "Who's your team?",
            'pg13' => "Who's your team?",
            'r' => "Who's your team? Choose wisely.",
        ],

        /*
         * The moment's subheading, single-team era: one pick, one promise.
         * The five-slot education moved to the tour's glance stop — this
         * screen asks exactly one question and gets out of the way.
         */
        'onboarding.picker' => [
            'pg' => 'Your favorite leads your home page. You can add more later.',
            'pg13' => 'Your favorite headlines your home page — you can add more later.',
            'r' => 'Your favorite headlines your page all season. Add more later; this one matters.',
        ],

        /*
         * The guided tour. Coach-mark chrome frames factual screens, so it
         * may speak even while pointing at Scores — the facts underneath stay
         * untouched, and the controls (Back, Next, Skip) stay plain.
         */
        'tour.glance.heading' => [
            'pg' => 'Your teams, at a glance',
            'pg13' => 'Your teams live here',
            'r' => 'Your teams live here — all of them',
        ],

        /*
         * The glance body carries the five-slot education now — the moment
         * collects ONE team and promises "more later"; this is where "more"
         * gets its number.
         */
        'tour.glance.body' => [
            'pg' => 'One card per team you follow — records, trends, next games. You have five slots; swipe across to see them all.',
            'pg13' => 'One card per team, five slots to fill — records, trends, next games. Swipe the whole roster, even the rebuilding ones.',
            'r' => 'Five slots, one card each — records, trends, next games. Swipe the roster; what you did in the rankings stays between you and the card.',
        ],

        'tour.search.heading' => [
            'pg' => 'Search everything',
            'pg13' => 'Search anything',
            'r' => 'Search anything, settle everything',
        ],

        /*
         * The example team is the READER'S own first team, never a canned
         * school — a hardcoded example is somebody's rival, and the pilot
         * audience taught us which one. `body_team` carries the pick
         * (`:prefix` is its first three letters); the plain `body` is the
         * skipped-the-picker fallback, naming nobody.
         */
        'tour.search.body' => [
            'pg' => 'Teams, players, coaches, games — start typing and it finds them.',
            'pg13' => 'Teams, players, coaches, games. Start typing — three letters is usually enough.',
            'r' => 'Teams, players, coaches, games. Settle the argument before they finish making their point.',
        ],

        'tour.search.body_team' => [
            'pg' => 'Teams, players, coaches, games — ":prefix" is enough to find :team.',
            'pg13' => 'Teams, players, coaches, games. Start typing — ":prefix" is enough to find :team.',
            'r' => 'Teams, players, coaches, games. ":prefix" pulls up :team; the rest is for settling arguments.',
        ],

        /*
         * Scoreboard and league lines define pg + r only: the pg line is
         * already the right sentence for a PG-13 reader, and resolution
         * falls down the ladder to hand it to them.
         */
        'tour.scores.heading' => [
            'pg' => 'The Scoreboard',
            'r' => 'The Scoreboard never lies',
        ],

        'tour.scores.body' => [
            'pg' => 'Every game, every week, in real time. Your teams pin themselves to the top.',
            'r' => 'Every game, every week, in real time. Your teams pin to the top whether the score flatters them or not.',
        ],

        'tour.picks.heading' => [
            'pg' => 'Picks are coming',
            'pg13' => 'Your picks live here soon',
            'r' => 'The tab your record will haunt',
        ],

        'tour.picks.body' => [
            'pg' => "Weekly picks against your friends land right here. It's on the way — this tab is holding the seat.",
            'pg13' => 'Weekly picks, groups, and a running record of who called it. Not live yet — this tab is holding the seat.',
            'r' => 'Weekly picks, groups, and a permanent record of every game you called wrong. Not live yet, but the seat is saved.',
        ],

        /*
         * The currency stays out of drinking vocabulary on purpose — Beast
         * Lattes are the app's currency, full stop, and the copy never says
         * otherwise. See components/wallet-chips.blade.php for the strategy.
         */
        'tour.wallet.heading' => [
            'pg' => 'Beast Lattes and ranks',
            'pg13' => 'Get paid in Beast Lattes',
            'r' => 'Get paid in Beast Lattes',
        ],

        'tour.wallet.body' => [
            'pg' => "The app runs on Beast Lattes. Earn them and stack XP by playing Pick'em — your balance and rank live up here.",
            'pg13' => 'The app runs on Beast Lattes. Win picks, stack XP, climb the ranks — your balance sits up here, judging quietly.',
            'r' => 'The app runs on Beast Lattes. Win picks, stack XP, climb the ranks — and your balance up here will say exactly how good you really are.',
        ],

        /*
         * Appended to the wallet stop ONLY when the first-team seed grant
         * exists — a skipper tours too, and telling them they earned XP they
         * did not would be the app's first lie. `:xp` is
         * GrantWalletEntry::FIRST_TEAM_XP, so the copy can never drift from
         * the ledger.
         */
        'tour.wallet.seeded' => [
            'pg' => 'The :xp XP already in there? Picking your team earned that.',
            'pg13' => 'That :xp XP already sitting in there? Picking your team paid it.',
            'r' => "Already up :xp XP just for picking a team. Easiest money you'll make all season.",
        ],

        'tour.league.heading' => [
            'pg' => 'Around the league, go deep',
            'r' => 'Around the league, go deep — bring receipts',
        ],

        'tour.league.body' => [
            'pg' => 'Standings, rankings, teams, players, stats, recruiting — the straight sports-app half of the app, and it runs deep.',
            'r' => 'Standings, rankings, stats, rosters, recruiting — every number your rival pretends not to know, deep enough to get lost in.',
        ],

        'tour.account.heading' => [
            'pg' => 'Make it yours',
            'pg13' => 'Make it yours',
            'r' => 'Set your tolerance',
        ],

        'tour.account.body' => [
            'pg' => 'Account is where you manage your teams, toggle dark mode, and set how much personality the app brings.',
            'pg13' => "Account is where you manage & reorder your teams, toggle dark mode, and set how much grief we're allowed to give.",
            'r' => 'Account is where you manage and reorder your teams, toggle dark mode, and crank the grief dial as far as it goes.',
        ],

        /*
         * The tour's closer sells the install NOW — the steps for the
         * detected browser render right inside the card, so "right now" is
         * a thing the reader can literally do without leaving the spot.
         */
        'tour.install.heading' => [
            'pg' => 'One more thing — install it',
            'pg13' => 'Install it. Right now.',
            'r' => 'Install it. Now.',
        ],

        'tour.install.body' => [
            'pg' => 'Ten seconds and it lives on your home screen — full screen, no browser bars, one tap from kickoff.',
            'pg13' => "Ten seconds, right now, while you're thinking about it — full screen, no browser bars, one tap from kickoff.",
            'r' => "Ten seconds. Full screen, no browser bars, kickoff one tap away. You've spent longer deciding nothing.",
        ],

        /*
         * The install pitch — INSTALL language, not "bookmark it": this is a
         * real web-app install (Chromium's own UI says Install), and calling
         * it what it is outranks underselling it as a home-screen shortcut.
         * Chrome that frames the whole app rather than any one screen, so it
         * speaks — but the actual steps ("tap Share", "Add to Home Screen")
         * stay plain in the view: those are Apple's and Google's own labels,
         * and the user is hunting for them verbatim.
         */
        'install.banner.heading' => [
            'pg' => 'Install this app',
            'pg13' => 'Install the app',
            'r' => 'Install the app already',
        ],

        'install.banner.body' => [
            'pg' => 'The full-screen web app, one tap from your first game. About ten seconds.',
            'pg13' => 'Full screen, no browser bars, ten seconds to install.',
            'r' => "Full screen, zero clutter, ten seconds. You'll manage.",
        ],

        'install.screen.heading' => [
            'pg' => 'The best way to use this app is installed — full screen, fast, and one tap away on game day.',
            'pg13' => 'The best seat in the house is the installed app — full screen, instant, one tap from kickoff.',
            'r' => 'The best seat in the house is the installed app — full screen, instant, and no address bar between you and the damage.',
        ],

        /*
         * The case against the tab, made plainly: installed is not a nicer
         * option, it is the better one, and the copy is allowed to insist.
         * The claims stay factual — full screen, own icon, never buried —
         * so the insistence never outruns what the install actually does.
         */
        'install.screen.case' => [
            'pg' => 'Installed genuinely beats a browser tab: full screen with no address bar, its own icon on your home screen, and it never gets lost behind your other tabs.',
            'pg13' => "Installed is objectively better than a tab: full screen, no browser chrome, one tap from your home screen — and it can't get buried under forty other tabs by Saturday.",
            'r' => 'A tab is where apps go to get lost. Installed is full screen, no chrome, one tap from your home screen — and come kickoff it will not be hiding behind forty other tabs.',
        ],

        'install.screen.installed' => [
            'pg' => "You're all set — you're using the installed app right now.",
            'pg13' => "You're already in the app. Nothing left to install — go check a score.",
            'r' => "You're already in the app. Installing it twice won't fix your team's defense.",
        ],

        'home.pickem' => [
            'pg' => "Groups, weekly picks and bragging rights with your friends. It's on the way.",
            'pg13' => "Groups, weekly slates, and a season-long paper trail of everyone's bad calls. It's coming.",
            'r' => "Groups, weekly slates, and receipts on every terrible pick your friends swear they never made. It's coming.",
        ],

        /*
         * The Bandwagon State card and its news panel — the placeholder that
         * fills a zero-team Home. The joke roasts the act of following
         * nobody, never the person, and the card's "Pick your real team"
         * affordance stays plain in the view: the joke never eats the
         * instruction.
         */
        'placeholder.body' => [
            'pg' => 'This is what following nobody looks like. Tap here and put your real team up top.',
            'pg13' => 'Following nobody gets you a front-row seat on the bandwagon. Tap and pick your real team.',
            'r' => 'No team? Then you ride the bandwagon with the rest of the tourists. Tap and pick a real one.',
        ],

        'placeholder.news' => [
            'pg' => ':name never plays, so there is never news. Pick your real team and this section fills in.',
            'pg13' => ':name has no news, no games, and no plan. Pick your real team and this section goes to work.',
            'r' => ':name has no news — nothing ever happens to a team that only exists to spite your indecision. Pick a real one.',
        ],

        /*
         * The Picks screen's pitch — Pick'em is a LOUD surface, and this is
         * its front door before there is anything behind it. The feature
         * cards below it stay plain: they are promises about what ships,
         * and a joke between a reader and a promise reads as hedging.
         */
        'picks.screen.pitch' => [
            'pg' => "Weekly picks against your friends, live scoring on Saturdays, and a leaderboard to settle who saw it coming. It's on the way.",
            'pg13' => "Weekly picks against your group, live scoring while the games run, and a leaderboard nobody can argue with. It's coming.",
            'r' => "Weekly picks against your group, live scoring while it all goes sideways, and a leaderboard that remembers what everyone said. It's coming.",
        ],

        // The example school is Tennessee, not Georgia, on purpose — the
        // pilot audience wears orange, and a rival in the empty state reads
        // as the app picking a side against them.
        'search.empty' => [
            'pg' => 'No matches for ":query". Try the start of a name — "Ten" finds Tennessee.',
            'pg13' => 'Swing and a miss on ":query". Try the start of a name — "Ten" finds Tennessee.',
            'r' => '":query"? Never heard of them. Try the start of a name — "Ten" finds Tennessee.',
        ],

        'profile.subheading' => [
            'pg' => 'Your handle is how everyone else sees you.',
            'pg13' => 'Your handle is what everyone else will be yelling.',
            'r' => "Your handle is what you'll be called. Choose accordingly.",
        ],

        /*
         * The claim affordance for the handleless — registration stopped
         * asking, so Account is where a handle begins. Future seam: the same
         * claim rides the first Pick'em entry or chat message, the two
         * features that actually need one.
         */
        'profile.claim_handle' => [
            'pg' => 'Claim your handle',
            'pg13' => 'Claim your handle before someone else does',
            'r' => 'Claim your handle — the good ones go first',
        ],

        'profile.rating_description' => [
            'pg' => "We'll have opinions about your picks, your team and your record — never about you. This sets how many.",
            'pg13' => 'We roast your picks, your team and your record — never you. This sets how hard.',
            'r' => "We roast your picks, your team and your record — never you. This sets how hard, and you've picked hard.",
        ],

        /*
         * The verify-your-email nudge — LOUD, reward-first, and ONE sentence:
         * it rides a single slim row on Home, so the Beast Latte does all the
         * selling and the fine print stays in the mail. The picks variant
         * explains the one gate verification actually holds.
         */
        'verify.callout.body' => [
            'pg' => 'Confirm your email — your first Beast Latte and XP are waiting.',
            'pg13' => "Confirm your email — there's a Beast Latte and XP waiting on it.",
            'r' => "Confirm your email. There's a Beast Latte riding on it, and the clock is running.",
        ],

        'verify.picks.body' => [
            'pg' => "Confirm your email to make picks when Pick'em opens.",
            'pg13' => 'Confirm your email to get in the game — picks need a real address behind them.',
            'r' => 'No confirmed email, no picks. Sort it before the season starts without you.',
        ],

        /*
         * The post-verify moment, in its three homes: the landing screen a
         * mail click opens (title + reward + a body per context) and Home's
         * one-row celebration behind the `verify.moment` flash. The landing
         * body's INSTRUCTION — open it from your home screen — must survive
         * legibly in every register; the joke rides around it, never through
         * it. `body_app` is the Android link-capture case, where the click
         * lands inside the installed app and coaching would be nonsense.
         * Rewards stay unnumbered here like mail.verify.reward, so the
         * amounts have one source of truth.
         */
        'verify.landing.title' => [
            'pg' => "You're verified.",
            'pg13' => "Verified. You're official.",
            'r' => "Verified. Now you're dangerous.",
        ],

        'verify.landing.reward' => [
            'pg' => 'Your first Beast Latte and XP just landed in your account.',
            'pg13' => 'Your first Beast Latte and XP just hit the account. Told you it paid.',
            'r' => 'First Beast Latte and XP: paid in full. We keep our word around here.',
        ],

        'verify.landing.body' => [
            'pg' => 'This tab did its job. Open the app from your home screen and everything will be waiting.',
            'pg13' => "This tab's work here is done — the app has your latte. Open it from your home screen.",
            'r' => 'This tab was a means to an end. The real thing is on your home screen — go open it.',
        ],

        'verify.landing.body_app' => [
            'pg' => "And you're already in the right place.",
            'pg13' => "And look at that — you're already in the app. Efficient.",
            'r' => "And you're already in the app. Some people just know shortcuts.",
        ],

        'verify.celebration.body' => [
            'pg' => 'Email confirmed — your first Beast Latte and XP are in.',
            'pg13' => 'Email confirmed. Beast Latte and XP: banked.',
            'r' => 'Email confirmed. Latte banked, XP banked — now we can get to work.',
        ],

        /*
         * Push. Every push key is rendered from a QUEUED notification, so
         * every caller passes `for:` — the mail lesson, same stakes. Titles
         * carry the FACT and stay factual where the fact matters (a kickoff
         * title names the game, plainly); bodies carry the voice. The
         * banner pair is the one-tap nudge row on Home; its instruction
         * ("turn them on") survives every register.
         */
        'push.welcome.title' => [
            'pg' => 'Notifications: on',
            'pg13' => 'Notifications: on',
            'r' => 'Notifications: armed',
        ],

        'push.welcome.body' => [
            'pg' => "This is what they'll look like. Kickoff alerts for your teams are live.",
            'pg13' => "First of many. Kickoff alerts for your teams are live — you'll hear it here first.",
            'r' => "First of many, and the rest won't knock this politely. Kickoffs for your teams: covered.",
        ],

        'push.banner.heading' => [
            'pg' => 'Never miss a kickoff',
            'pg13' => 'Never miss a kickoff',
            'r' => 'Miss a kickoff once, never again',
        ],

        'push.banner.body' => [
            'pg' => 'Turn on alerts and your teams will find you.',
            'pg13' => 'Turn them on and your teams find you first.',
            'r' => 'Turn them on. Your teams will come find you.',
        ],

        'push.banner.confirmed' => [
            'pg' => 'On. The first one is already on the way.',
            'pg13' => "On. First one's already on the way.",
            'r' => 'Armed. The first one is already in the air.',
        ],

        'push.kickoff.body' => [
            'pg' => ':team kicks off soon — get to a screen.',
            'pg13' => ':team kicks off soon. Drop what you were pretending to do.',
            'r' => ':team kicks off soon. Whatever this is, it can lose to football.',
        ],

        /*
         * Mail.
         *
         * Every one of these is rendered from a QUEUED job, where there is no
         * authenticated user for `line()` to fall back to — so every caller
         * must pass `for: $user` or the reader silently gets the PG-13 line.
         *
         * Verification is the one transactional email allowed a personality:
         * it is the first thing a new account ever receives. A password reset
         * is not on this list on purpose — somebody locked out of their account
         * is not in the mood, and the reset mail stays plain.
         */
        'mail.verify.intro' => [
            'pg' => 'One tap and your account is ready to go.',
            'pg13' => "One tap and you're in. Then we can start arguing about your team.",
            'r' => "One tap and you're in. Then we can talk about whatever it is you call a team.",
        ],

        /*
         * Verifying PAYS: the first Beast Latte and the first real XP land on
         * the tap, and Pick'em participation is gated behind it. The reward
         * leads because it is the true incentive — the lock is just policy.
         */
        'mail.verify.reward' => [
            'pg' => "Confirming also pays out your first Beast Latte and XP, and saves your seat for Pick'em.",
            'pg13' => "Confirming pays out your first Beast Latte and XP — and unlocks Pick'em when it opens.",
            'r' => "Confirm and collect: one Beast Latte, your first XP, and a seat at Pick'em. Free money, minus the money.",
        ],

        /*
         * The self-destruct warning, sent three days before a never-verified
         * account is pruned. `:days` is User::VERIFICATION_REMINDER_LEAD_DAYS —
         * the same constant the prune query enforces, so the mail can never
         * promise a window the query does not honor. LOUD, because it is about
         * the reader's own account — but the stakes stay truthful: this is the
         * one email where over-joking reads as not meaning it.
         */
        'mail.reminder.subject' => [
            'pg' => 'Your account expires in :days days',
            'pg13' => 'This account self-destructs in :days days',
            'r' => ':days days until this account deletes itself',
        ],

        'mail.reminder.intro' => [
            'pg' => 'You signed up but never confirmed your email, so this account is scheduled to be removed in :days days.',
            'pg13' => 'You never confirmed your email, so in :days days this account quietly deletes itself — teams, name, everything.',
            'r' => 'You ghosted your own signup. In :days days this account deletes itself — teams, name, the works.',
        ],

        'mail.reminder.outro' => [
            'pg' => 'One tap below keeps it — and your first Beast Latte and XP come with it.',
            'pg13' => 'One tap keeps it, and your first Beast Latte and XP land on the spot.',
            'r' => 'One tap keeps it and pays out a Beast Latte. Ignoring it is choosing the void.',
        ],

        'mail.newsletter.subject' => [
            'pg' => 'Your week in college football',
            'pg13' => 'Your week, and how your teams did',
            'r' => 'Your week, and the damage report',
        ],

        'mail.newsletter.intro' => [
            'pg' => "Here's how your teams got on this week.",
            'pg13' => "Here's how your teams got on. No editorializing. Much.",
            'r' => "Here's the damage. Read it standing up.",
        ],

        'mail.newsletter.empty' => [
            'pg' => 'Nothing on the schedule for your teams this week — back soon.',
            'pg13' => 'Your teams had the week off. Enjoy the break from the stress.',
            'r' => 'Nobody played. Nothing to yell about. Savor it.',
        ],

        'mail.unsubscribed' => [
            'pg' => "You're unsubscribed. We won't email you about the week anymore.",
            'pg13' => 'Done — no more weekly emails. Your scores will have to find you some other way.',
            'r' => "Done. No more weekly emails. You're on your own out there.",
        ],
    ];

    /**
     * A line at the reader's level, or the closest one below it.
     *
     * @param  array<string, string|int>  $replace
     */
    public static function line(string $key, array $replace = [], ?User $for = null): string
    {
        $variants = self::LINES[$key] ?? [];

        if ($variants === []) {
            return '';
        }

        $rating = ($for ?? auth()->user())?->content_rating ?? ContentRating::Pg13;

        // `includes()` runs mildest-first, so the reader's own level is last —
        // walk back from there and take the first line that exists.
        foreach (array_reverse($rating->includes()) as $level) {
            if (isset($variants[$level->value])) {
                return self::fill($variants[$level->value], $replace);
            }
        }

        return '';
    }

    /**
     * @param  array<string, string|int>  $replace
     */
    private static function fill(string $line, array $replace): string
    {
        foreach ($replace as $key => $value) {
            $line = str_replace(':'.$key, (string) $value, $line);
        }

        return $line;
    }
}

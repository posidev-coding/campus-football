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

        // The front door once the flag is OPEN — "on the way" is wrong on
        // day one, to exactly the person an invite link brought here.
        'onboarding.guest.body_live' => [
            'pg' => "Follow your teams, watch every score live, and play Pick'em with your friends. Signing up takes about a minute.",
            'pg13' => "Live scores, your teams up top, and Pick'em open for business. A minute to sign up, a whole season to argue about it.",
            'r' => "Live scores, your teams up top, Pick'em open now. One minute to sign up, a whole season to be wrong in public.",
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
         * The same stop once the flag is OPEN. "Picks are coming" walked to
         * the center tab on launch day was the single worst copy defect the
         * audit found — the tour must never promise what is already there.
         */
        'tour.picks_live.heading' => [
            'pg' => 'Make your picks here',
            'pg13' => 'Your picks live here now',
            'r' => 'The tab your record will haunt',
        ],

        'tour.picks_live.body' => [
            'pg' => 'Weekly picks against your friends, right here. Join a group or grab a seat in the lobby and get your first card in.',
            'pg13' => "Weekly picks, groups, and a running record of who called it. It's live — grab a seat and get a card in.",
            'r' => 'Weekly picks, groups, and a permanent record of every game you call wrong. Live now — pull up a seat.',
        ],

        /*
         * The room beat — the stop that actually SEATS somebody. For an
         * in-season pilot the first-week retention hinge is being in a
         * contest, not having followed a team, and before this no stop,
         * CTA or nudge produced a room join. The stop's anchor renders
         * only while the flag is open, so pre-flip tours step over it.
         */
        'tour.room.heading' => [
            'pg' => 'Get in a room',
            'pg13' => 'Get yourself in a room',
            'r' => 'You need a room',
        ],

        'tour.room.body' => [
            'pg' => "Picks are better with company. This card is the door — join your group's room or grab an open seat in the lobby before Saturday.",
            'pg13' => "Picks mean nothing without witnesses. This card is the door — your group's room, or an open lobby seat before Saturday.",
            'r' => "Picks without witnesses are just opinions. Through this card: your group's room or an open lobby seat. Saturday won't wait.",
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
            'pg' => 'Account is where you manage your teams, catch up on your notifications, toggle dark mode, and set how much personality the app brings.',
            'pg13' => "Account is where you manage & reorder your teams, catch up on your inbox, toggle dark mode, and set how much grief we're allowed to give.",
            'r' => 'Account is where you manage and reorder your teams, read what the inbox has on you, toggle dark mode, and crank the grief dial as far as it goes.',
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

        // The same card the moment the flag opens: the promise becomes the
        // invitation, on the config mirror — never Feature::active().
        'home.pickem.live' => [
            'pg' => "Groups, weekly picks and bragging rights with your friends. It's live — get your picks in.",
            'pg13' => "Groups, weekly slates, and a season-long paper trail of everyone's bad calls. Live now.",
            'r' => 'Groups, weekly slates, and receipts on every terrible pick your friends swear they never made. Live now — no excuses.',
        ],

        /*
         * COLLEGE GAMEDAY, and the split the plan calls for: the LOCATION is
         * a fact and stays factual in the markup above these lines, while the
         * framing around it is loud — and louder still when the bus is
         * parked on a campus the reader follows, because that stops being a
         * league headline and becomes a personal event.
         *
         * The roast is aimed at the ritual and at the signs, never at the
         * people holding them. Nobody is the butt of a 6am camera.
         */
        'home.gameday' => [
            'pg' => 'The set, the signs and the headgear pick — all from :city this week.',
            'pg13' => 'Signs, sleeping bags and one very loud headgear pick. :city has it this week.',
            'r' => ':city spends Saturday morning on national television holding cardboard. Worth it.',
        ],

        'home.gameday.yours' => [
            'pg' => 'GameDay is coming to :team. Set an early alarm.',
            'pg13' => 'GameDay is at :team this week. Your people are getting up at 5am to hold a bedsheet on TV.',
            'r' => 'GameDay is at :team. Somebody you went to school with will be on television at 6am with a bedsheet and a grudge.',
        ],

        /*
         * The honest empty state. ESPN announces the next site about a week
         * ahead, usually Sunday or Monday, so an empty Monday is the system
         * working — which is exactly why it gets said out loud rather than
         * hidden behind a card that does not render.
         */
        'home.gameday.unknown' => [
            'pg' => 'ESPN has not announced this week\'s location yet. It usually lands Sunday.',
            'pg13' => 'No location yet. ESPN usually gives it up Sunday, once they know which game got good.',
            'r' => 'ESPN has not picked one yet. They are waiting to see which game stops being a blowout.',
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

        /*
         * Search is a PURE surface and the ANSWER is a fact, printed plainly.
         * What speaks is the chrome around it — the offer, the shrug and the
         * cap — which is the same licence the empty state above already has.
         */
        /*
         * The idle screen, which is where anybody ever finds out this exists.
         * Nobody types a question into a search box unless something told them
         * they could — so this line is an invitation and the examples under it
         * are the instruction, kept plain the way every affordance here is.
         */
        'search.ask.idle' => [
            'pg' => 'Ask a question and we will look it up. Tap one to see how.',
            'pg13' => 'Ask a question and we will look it up. Tap one and see what comes back.',
            'r' => 'Ask a question and we will go get the number. Tap one — it comes from the box score, not a hunch.',
        ],

        // Deliberately does NOT repeat "nothing matched" — the empty-state
        // callout directly underneath already says it, and reading the same
        // sentence twice makes the offer look like an error message.
        'search.ask' => [
            'pg' => 'That reads like a question. We can look the number up.',
            'pg13' => 'That reads like a question. We can go find the number.',
            'r' => 'That reads like a question. Say the word and we will go dig the number out.',
        ],

        'search.ask.none' => [
            'pg' => 'We could not answer that one. The results below might still help.',
            'pg13' => "Couldn't answer that one. Try naming the player and the stat.",
            'r' => "No idea. Name the player and the stat and we'll try again.",
        ],

        'search.ask.capped' => [
            'pg' => "That's all the questions for today. They reset tomorrow.",
            'pg13' => "You're out of questions for today. They come back tomorrow.",
            'r' => "You've asked enough for one day. Come back tomorrow.",
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

        // Sells BOTH jobs: kickoff alerts, and the Phase 6 retention
        // feature — the nudge before a card locks with picks still owed.
        'push.banner.body' => [
            'pg' => 'Turn on alerts: your teams find you at kickoff, and your picks get a nudge before they lock.',
            'pg13' => 'Turn them on — your teams find you at kickoff, and your picks get a warning before they lock.',
            'r' => 'Turn them on. Kickoffs find you, and your picks get exactly one warning before they lock without you.',
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

        /*
         * Groups — Pick'em's social half, and a LOUD surface. The register
         * rises with the reader, the roast stays on picks and records, and
         * the one hard instruction (the code format) lives in the view as
         * plain text, not here.
         */
        'groups.mine.empty' => [
            'pg' => 'No groups yet. Start one and invite your people.',
            'pg13' => 'No groups yet. Start one — somebody in the chat has to be the commissioner.',
            'r' => 'No groups yet. Start one, or keep talking with nothing on the line.',
        ],

        'groups.lobbies.subheading' => [
            'pg' => 'Open to everyone. Jump into a public lobby and start picking.',
            'pg13' => 'No invite? Public lobbies take walk-ons. Jump in and pick this week.',
            'r' => 'No invite? Public lobbies take anybody. Jump in and prove something.',
        ],

        'groups.join.subheading' => [
            'pg' => "Got a code? Enter it and you're in.",
            'pg13' => 'Got a code from your group? Punch it in.',
            'r' => 'Got a code? Punch it in and get in here.',
        ],

        'groups.join.bad_code' => [
            'pg' => "That code doesn't match any group. Check it and try again.",
            'pg13' => 'No group answers to that code. Check it with whoever sent it.',
            'r' => "That code opens nothing. Somebody fat-fingered it, and it wasn't us.",
        ],

        'groups.joined' => [
            'pg' => "You're in :group. Welcome.",
            'pg13' => "You're in :group. Picks open when the slate drops.",
            'r' => "You're in :group. Fresh meat.",
        ],

        'groups.created' => [
            'pg' => ':group is live. Share the code and get your people in.',
            'pg13' => ':group is live. Share the code — a league of one is just a diary.',
            'r' => ':group is live. Share the code and start collecting victims.',
        ],

        'groups.verify_first' => [
            'pg' => 'Verify your email first — then you can create and join groups.',
            'pg13' => "Verify your email first. The league needs to know you're real.",
            'r' => 'Verify your email first. No ghosts in this league.',
        ],

        'groups.invite.hint' => [
            'pg' => 'Share this link to invite people to :group.',
            'pg13' => 'This link is the invite. Send it to your people.',
            'r' => 'This link is the key to the door. Hand it out on purpose.',
        ],

        // Rides the share sheet BESIDE the URL — the url field carries the
        // link, so the text sells the tap.
        'groups.invite.share_text' => [
            'pg' => 'Join :group — we pick college football games against the spread every week.',
            'pg13' => 'Join :group. One slate a week, everyone on the record.',
            'r' => 'Join :group. Every pick on the record, every excuse in writing.',
        ],

        'groups.leave.commissioner' => [
            'pg' => "Commissioners can't leave while the group has members.",
            'pg13' => "You're the commissioner — the last one out. Everyone else leaves first.",
            'r' => "You're the commissioner. Captains go down with the ship.",
        ],

        'groups.left' => [
            'pg' => 'You left :group.',
            'pg13' => "You're out of :group.",
            'r' => "You're out of :group. They'll cope.",
        ],

        'groups.member.removed' => [
            'pg' => ':name was removed from the group.',
            'pg13' => ':name is out of the group.',
            'r' => ':name got cut.',
        ],

        /*
         * The publish violations — the engine's validateForPublish() keys
         * rendered as feedback: each one keeps its instruction intact and
         * wears only as much personality as fits beside it. The key names
         * are the engine's contract and never change; the prose speaks
         * THE SLATE like everything else.
         */
        'picks.publish.count' => [
            'pg' => 'This slate needs exactly :size games. Add or trim until it\'s right.',
            'pg13' => 'The slate takes exactly :size games — no more, no less. Fill it out.',
            'r' => ':size games. Not :size-ish. Fix the slate.',
        ],

        'picks.publish.line_missing' => [
            'pg' => 'Every game needs a posted spread before publishing. Swap out any game still waiting on a line.',
            'pg13' => 'No line, no game. Swap out anything still waiting on a spread.',
            'r' => 'No spread, no seat. Cut the games the books won\'t touch.',
        ],

        'picks.publish.whole_line' => [
            'pg' => 'Every line must sit on a half point — no whole numbers, so no ties.',
            'pg13' => 'Half-point lines only. Nobody drives home on a push in this league.',
            'r' => 'Half points only. Pushes are for leagues without the nerve to pick a side.',
        ],

        'picks.publish.not_saturday' => [
            'pg' => 'Only Saturday games from noon Eastern on can go on the slate.',
            'pg13' => 'Saturday, noon to midnight Eastern — that\'s the whole window, and the whole religion.',
            'r' => 'Saturday, noon Eastern onward. No breakfast kickoffs, no Tuesday-night specials.',
        ],

        /*
         * Replaces the old `wrong_week` 2026-08-20. A slate is ONE SATURDAY,
         * not one ESPN week: 2026's Week 1 holds two of them, so the week
         * comparison passed for games a fortnight apart.
         */
        'picks.publish.wrong_saturday' => [
            'pg' => 'One of these games isn\'t on this slate\'s Saturday. Remove it.',
            'pg13' => 'There\'s a game from another Saturday on here. Off it goes.',
            'r' => 'One of these escaped from another Saturday. Round it up.',
        ],

        'picks.publish.started' => [
            'pg' => 'A game on the slate has already kicked off. Swap it out.',
            'pg13' => 'One of these already kicked off. You can\'t pick a game that\'s playing.',
            'r' => 'One of these already kicked. Even this league has rules.',
        ],

        'picks.publish.tiebreaker' => [
            'pg' => 'Pick a tiebreaker game before publishing.',
            'pg13' => 'The slate needs a tiebreaker game. Old league law.',
            'r' => 'No tiebreaker, no slate. The founders would riot.',
        ],

        'picks.publish.tiers' => [
            'pg' => 'Each tier needs exactly five games.',
            'pg13' => 'Five games per tier, three tiers, no freelancing.',
            'r' => 'Five per tier. This is a slate, not a suggestion box.',
        ],

        'picks.publish.featured_metric' => [
            'pg' => "The Woodshed's tiebreaker is always the featured game's combined points. Switch the question back.",
            'pg13' => "In the Woodshed the question is the featured game's over/under — founders' law. Switch it back.",
            'r' => "The Woodshed asks ONE question: the featured game's total. The founders wrote it; you don't get to edit it.",
        ],

        /*
         * The clubhouse — one group's home, rebuilt around THE SLATE (the
         * word "board" is retired product-wide, including internals; these
         * are the first keys written for the new vocabulary).
         */
        'group.slate.waiting' => [
            'pg' => 'No slate yet. Your commissioner sets the week\'s games — picks open here the moment it lands.',
            'pg13' => 'No slate yet. The commissioner is on the clock — picks open the second it drops.',
            'r' => 'No slate yet. The commissioner is sitting on the week. Go rattle their cage.',
        ],

        'group.slate.build_prompt' => [
            'pg' => 'This week\'s slate isn\'t set yet — that\'s the commissioner\'s job, and that\'s you.',
            'pg13' => 'No slate, no picks. The week is waiting on you, commissioner.',
            'r' => 'The week doesn\'t exist until you set the slate. That gavel isn\'t decorative.',
        ],

        /*
         * A SATURDAY TOO THIN TO SLATE. The clubhouse takes the build
         * door away and states both numbers plainly above this line —
         * the mode's size and the card's — so this stays mood only. No
         * count and no date rides in here: a register line that carries
         * the facts is a second place for them to go wrong.
         */
        'group.slate.thin' => [
            'pg' => 'There is not enough football on this Saturday to fill your slate. The card fills out next week.',
            'pg13' => 'Not enough football this Saturday to make a real slate. Next week the whole sport clocks in.',
            'r' => 'This Saturday is a warmup with a TV deal. Next week the whole sport clocks in and you can deal a real card.',
        ],

        'group.season.empty' => [
            'pg' => 'No weeks in the books yet. Season standings start counting when the first slate goes final.',
            'pg13' => 'Nothing on the ledger yet. Standings start counting the first time a week goes official.',
            'r' => 'Zero weeks settled. Nobody\'s earned a thing here yet — change that Saturday.',
        ],

        /*
         * WHAT A PRIVATE GROUP IS, said under the clubhouse hero — the
         * symmetric half of the room's flavor blurb. The facts (mode,
         * slate size) are the mode's own blurb above this line; this is
         * the one thing the mode cannot say, which is that the container
         * around it is yours and runs to the bowls.
         */
        'group.private.frame' => [
            'pg' => 'A private group: invite-only, one mode, and the standings run all season.',
            'pg13' => 'Yours, invite-only, all season. Nobody walks in off the street and nobody walks out clean.',
            'r' => 'Invite-only and season-long. Every week goes on the ledger, and the ledger has a long memory.',
        ],

        /*
         * A PUBLIC ROOM WHOSE SATURDAY IS GONE. A room has no
         * commissioner and no next week, so the waiting line — "the
         * commissioner is on the clock" — is a lie on a card like this.
         * The room keeps its URL forever, so the card still travels.
         */
        'group.room.past' => [
            'pg' => 'This room\'s Saturday is done. Your picks are still in there.',
            'pg13' => 'That Saturday is in the books. The receipts are still inside.',
            'r' => 'This room already happened. The evidence is still in there if you\'re brave.',
        ],

        /*
         * A ROOM WITH NO CARD THIS WEEK. Same lie as group.room.past and
         * a different week: a room has no commissioner, so "the
         * commissioner is sitting on the week" names somebody who does
         * not exist. The past line cannot cover it either — that one
         * promises picks are still in there, and a room whose slate never
         * landed has none.
         */
        'group.room.no_card' => [
            'pg' => 'This room has no card this week. The Lobby has rooms with games in them.',
            'pg13' => 'No card in this room. The Lobby is full of ones that have games.',
            'r' => 'This room has nothing on it. Go find one that does.',
        ],

        /*
         * The creation wizard: name, THE GAME, the invite moment. The
         * one-season rule rides create.mode.hint — fine print that must
         * stay legible in every register, because it is the rule the
         * pivot modal later holds people to.
         */
        'create.subheading' => [
            'pg' => 'Give your group a name — the one your people will see all season.',
            'pg13' => 'Name the group. Choose wisely — it\'s on the standings all season.',
            'r' => 'Name the group. This is what somebody loses to every week, so make it hurt.',
        ],

        'create.mode.hint' => [
            'pg' => 'Your group plays one game for the whole season. You can change it once, and everyone in the group is told.',
            'pg13' => 'One game, all season. You get a single mid-season change, and the whole group hears about it.',
            'r' => 'One game, all season. You get exactly one change of heart, and the group gets a note about it — no quiet rewrites.',
        ],

        'create.share.text' => [
            'pg' => 'Join my :group pick\'em group — use code :code.',
            'pg13' => 'Join :group. Code :code. Bring your best calls.',
            'r' => 'Join :group. Code :code. Bring picks or bring excuses.',
        ],

        /*
         * The mode pivot — one per season, announced. The blocked lines
         * carry the WHY, because a refused lever with no reason reads as
         * a bug.
         */
        'mode.change.warning' => [
            'pg' => 'One change per season — this is it. The group\'s slates switch to the new mode from the next published week.',
            'pg13' => 'This is your one change this season. Once you pull it, the lever\'s gone until next year.',
            'r' => 'One lever, one pull, one season. After this the league office stops taking your calls.',
        ],

        'mode.change.note' => [
            'pg' => 'Everyone in the group gets a note about the change.',
            'pg13' => 'Everyone gets a note — nobody finds out from the standings.',
            'r' => 'Everyone gets a note. Rule changes in the dark are how leagues die.',
        ],

        'mode.change.done' => [
            'pg' => 'Done — your group plays :mode now.',
            'pg13' => ':mode it is. The group\'s been told.',
            'r' => ':mode it is. The note\'s out — brace for the group chat.',
        ],

        'mode.change.blocked.used' => [
            'pg' => 'Your group already changed its mode this season — one change is the limit.',
            'pg13' => 'That was the one change. The league runs on this rule; see you next season.',
            'r' => 'You already pulled the lever this season. It doesn\'t grow back.',
        ],

        'mode.change.pick_one' => [
            'pg' => 'Pick the new mode first.',
            'pg13' => 'Pick the new mode first — the lever needs a target.',
            'r' => "Pick a mode before pulling the lever. It's not a slot machine.",
        ],

        'mode.change.blocked.inflight' => [
            'pg' => 'A week is still being played. The game can change after it goes official.',
            'pg13' => 'There\'s a live week on the table. Let it settle, then change the game.',
            'r' => 'Mid-hand rule changes? No. Let the week settle first.',
        ],

        /*
         * The commissioner's wizard — five stations, one ritual. The
         * whole-point-law line rides wizard.lines.hint; the half-point
         * grid is the league's own physics and gets said plainly in every
         * register.
         */
        'wizard.games.hint' => [
            'pg' => 'The suggestions are a starting point — every slot is yours to change.',
            'pg13' => 'Suggestions get you started; the commissioner gets the last word.',
            'r' => 'The suggestions are advice. You were elected for a reason.',
        ],

        'wizard.games.empty' => [
            'pg' => 'No games on the slate yet. Add from the list below, or fill it from suggestions.',
            'pg13' => 'Blank slate. Fill it from suggestions, or hand-pick like a purist.',
            'r' => 'Blank slate. Auto-fill it, or do it the hard way and own the results.',
        ],

        'wizard.no_candidates' => [
            'pg' => 'No eligible games for this week yet — lines are still being posted.',
            'pg13' => 'Nothing eligible yet. The books haven\'t posted this week\'s lines.',
            'r' => 'Nothing to slate yet. Even the books are still thinking.',
        ],

        'wizard.tiers.hint' => [
            'pg' => 'Sort the slate into tiers — tier 1 games pay the most. Five in each.',
            'pg13' => 'Tier 1 is where the points live. Five per tier — spend them wisely.',
            'r' => 'The big points live up top. Put the real games there and own the fallout.',
        ],

        'wizard.lines.hint' => [
            'pg' => 'Nudge a line one whole point at a time, up to 3 off the book — every line stays on a half point, so every pick wins or loses, never ties.',
            'pg13' => 'One whole point per tap, up to 3 off the book. Lines live on half points — no pushes in this league, ever.',
            'r' => 'Whole-point nudges, half-point lines, 3 off the book at most. Ties are a failure of leadership.',
        ],

        'wizard.tiebreaker.hint' => [
            'pg' => 'Pick the tiebreaker game and its question — the closest call settles tied weeks.',
            'pg13' => 'Choose the week\'s question. When the top of the room ties, the closest call takes it.',
            'r' => 'Pick the question that settles the arguments. Somebody\'s going to lose by one yard of it.',
        ],

        'wizard.preview.hint' => [
            'pg' => 'This is exactly what your group will see. Look it over, then publish.',
            'pg13' => 'What you see here is what they get. Look it over, then send it.',
            'r' => 'This is the week you\'re about to hang on the wall. Look hard, then publish.',
        ],

        'wizard.deadline' => [
            'pg' => 'Publish by :due, or the standard slate publishes itself so your group never misses a week.',
            'pg13' => 'The clock says :due. Miss it and the standard slate ships without you.',
            'r' => ':due, commissioner. Miss it and the league office publishes for you — and takes the credit.',
        ],

        'wizard.published' => [
            'pg' => 'The slate is published — your group can start picking.',
            'pg13' => 'Slate\'s up. Let the second-guessing begin.',
            'r' => 'Slate\'s up. May God have mercy on their picks.',
        ],

        'wizard.already_published' => [
            'pg' => 'This week\'s slate is already out. Your group is picking against it right now.',
            'pg13' => 'The week is out the door — the clubhouse owns it now.',
            'r' => 'Published means published. Go watch them wrestle with your lines.',
        ],

        'leaderboard.empty' => [
            'pg' => 'Nothing on the ledger for this window yet. XP lands as picks land.',
            'pg13' => 'Nobody\'s earned a thing in this window. First pick plants the flag.',
            'r' => 'An empty leaderboard is a dare. Go make some picks.',
        ],

        'history.empty' => [
            'pg' => 'No settled weeks yet. Your results collect here after each week goes official.',
            'pg13' => 'Nothing in the books yet. Every settled week files itself here — wins and otherwise.',
            'r' => 'No history yet. Play a week and this page starts keeping receipts.',
        ],

        'contest.room.full' => [
            'pg' => 'That room just filled up. A fresh one should be open right below it.',
            'pg13' => 'Too slow — that room\'s full. The next one\'s already open.',
            'r' => 'Room\'s full. The next one\'s open — move faster this time.',
        ],

        'contest.room.winner' => [
            'pg' => ':name wins the week.',
            'pg13' => ':name takes the week.',
            'r' => ':name ran this room. Everyone else, form an orderly line to complain.',
        ],

        /*
         * The collapsed closed-shelf line — one muted sentence where a
         * gray wall of dashed rows used to stand. :list is the shapes the
         * Saturday could not seat, already ·-joined.
         */
        'lobby.shelf.also' => [
            'pg' => 'Also on this shelf when the schedule allows: :list.',
            'pg13' => 'When the schedule allows, this shelf also stocks :list.',
            'r' => 'This shelf also stocks :list — when the Saturday earns it.',
        ],

        'lobby.publics.empty' => [
            'pg' => 'Public rooms open when the week\'s slate posts. Check back — there\'s always a seat.',
            'pg13' => 'Rooms open when the week\'s slate drops. Come back and grab a seat before they fill.',
            'r' => 'No rooms open. When the slate drops, seats go fast — don\'t be the one refreshing at kickoff.',
        ],

        'lobby.first_run.body' => [
            'pg' => 'Three ways to play, all against the spread. Start a group and bring your people.',
            'pg13' => 'Three games, one league office, zero pushes. Pick your poison and drag your group in.',
            'r' => 'Three games, no pushes, nowhere to hide. Pick one and go recruit somebody to beat.',
        ],

        /*
         * THE TWO PRODUCTS, told apart. My Picks stacks a private group
         * and a public room under two headings now, and these are the
         * one-line definitions underneath each — the difference a reader
         * could not see when both sat under the word "groups".
         *
         * The heading is the navigation and stays plain; the DEFINITION
         * is the instruction, so every register keeps its two load-
         * bearing facts intact (invite-only + all season / open to
         * anyone + one Saturday). The joke rides the end of the line,
         * never the middle of it.
         */
        'picks.groups.subheading' => [
            'pg' => 'Invite-only, and the standings run all season.',
            'pg13' => 'Invite-only, all season. Your people, your mode, one long argument.',
            'r' => 'Invite-only, all season. The people you picked, and the receipts you can\'t outrun.',
        ],

        'picks.rooms.subheading' => [
            'pg' => 'Public rooms, open to anyone. Each one plays a single Saturday.',
            'pg13' => 'Public and open to anyone. One Saturday each — win it or wait for the next.',
            'r' => 'Open to anybody with a thumb. One Saturday, one verdict, no rematch.',
        ],

        /*
         * The first run's group path, over the three mode doors. The
         * doors say what each MODE is; this says what the thing you are
         * about to create is — a season-long room of your own people.
         */
        'picks.first_run.group' => [
            'pg' => 'Your own private group: invite your people, pick one mode, and play every week this season.',
            'pg13' => 'Your group, your people, one mode all season. Somebody has to be the commissioner.',
            'r' => 'Your group, your people, one mode all season. Build it and start collecting victims.',
        ],

        'lobby.needs.subheading' => [
            'pg' => 'Slates are open — get your picks in before kickoff.',
            'pg13' => "Open slates don't pick themselves. Kickoff is the deadline.",
            'r' => "Unpicked games become zeros at kickoff. Zeros are how legends don't happen.",
        ],

        'lobby.rules.subheading' => [
            'pg' => 'Every mode in plain words — points, the Lock, and the Bear.',
            'pg13' => 'The rules, straight: what pays what, and what the Bear is doing here.',
            'r' => "Read the rules once, argue about them forever. That's the sport.",
        ],

        /*
         * WHAT THE STORE SELLS, at the top of it. The plain sentence
         * above this one carries the two facts (public, one Saturday);
         * this is the mood, and it is where the reader is told the other
         * half of the product lives somewhere else.
         */
        'lobby.intro.zinger' => [
            'pg' => 'No invite needed — and your season-long groups live over on My Picks.',
            'pg13' => 'No invite, no waiting. Season-long groups are a different door — this one is walk-ons.',
            'r' => 'Walk in, take a seat, ruin somebody\'s Saturday. Season-long grudges live on My Picks.',
        ],

        /*
         * THE LOBBY'S SHELVES — one optional line under each plain
         * heading. The HEADING is the navigation ("House rooms", "Quick
         * hits"), so the joke rides underneath it and never in place of
         * it: a shelf whose name is a punchline is a shelf nobody can
         * find. All render-guarded — an unwritten register is a quieter
         * shelf, never a hole.
         */
        'lobby.shelf.house' => [
            'pg' => 'The three standard games, every Saturday.',
            'pg13' => 'The house games. No gimmicks, no excuses afterward.',
            'r' => 'The house games. Nowhere to hide behind a theme when you go 3-7.',
        ],

        'lobby.shelf.quick_hits' => [
            'pg' => 'Short cards and small tables.',
            'pg13' => 'Short cards, small tables, quick verdicts.',
            'r' => 'Short cards, small tables. Fewer picks to blow, and everyone watching you blow them.',
        ],

        'lobby.shelf.spotlight' => [
            'pg' => 'Themed rooms — one idea per card.',
            'pg13' => 'Themed rooms: ranked chaos, night games, dogs that bite.',
            'r' => 'Themed rooms for people who think they have a specialty. Prove it.',
        ],

        'lobby.shelf.conference' => [
            'pg' => 'One league, all Saturday. Play your own.',
            'pg13' => 'Your league, every game of it. Pretend you watch the road ones.',
            'r' => 'Your whole league on one card. Time to find out if you actually watch it or just yell about it.',
        ],

        /*
         * The lobby teaser on My Picks, under the plain room count. The
         * COUNT is the information; this is the nudge to walk over.
         */
        'lobby.teaser.zinger' => [
            'pg' => 'Open to anyone — grab a seat and play this Saturday.',
            'pg13' => 'No group? No problem. Take a seat with strangers and beat them instead.',
            'r' => 'Seats are open. Go take somebody else\'s Saturday personally.',
        ],

        /*
         * THE SPECIALTY SHELF — one zinger per flavored room, rendered
         * under the card's factual blurb: LobbyFlavor::blurb() sizes and
         * prices the card, these are the mood. The conference family
         * shares one key with a :conference replacement.
         */
        'lobby.flavor.zinger.ranked_action' => [
            'pg' => 'Every ranked team, one card. Bring a pencil.',
            'pg13' => 'All the ranked teams at once. Your poll takes are about to be graded.',
            'r' => "Every ranked team on one card. Somebody's poll darling is getting exposed by 4pm.",
        ],

        'lobby.flavor.zinger.under_lights' => [
            'pg' => 'Night games only — the good stuff.',
            'pg13' => 'Nothing before 7. Chaos keeps late hours.',
            'r' => 'After dark is when the bad beats come out. Daylight is for cowards.',
        ],

        'lobby.flavor.zinger.two_minute' => [
            'pg' => 'Five picks, in and out.',
            'pg13' => "Five picks. Blow this one and it's fully on you.",
            'r' => 'Five picks. Tank a five-game card and the room will remember.',
        ],

        'lobby.flavor.zinger.upset_alley' => [
            'pg' => 'Back a dog that wins outright and collect the bonus.',
            'pg13' => 'Dogs that bite pay extra here.',
            'r' => 'Chalk is for cowards. Dogs that win outright get paid.',
        ],

        'lobby.flavor.zinger.back_porch' => [
            'pg' => "The founders' game at a small table — no hiding.",
            'pg13' => 'Ten seats. Small table, long memories.',
            'r' => 'Ten seats, nowhere to hide, and the Bear smells fear at close range.',
        ],

        'lobby.flavor.zinger.conference' => [
            'pg' => ':conference pride, settled on Saturday.',
            'pg13' => ':conference Saturdays are a family argument with kickoff times.',
            'r' => "A full :conference Saturday: twelve grudges and somebody's coach on the hot seat by midnight.",
        ],

        /*
         * The upset kicker's house rule, said plainly over the slate — an
         * instruction first, so every register keeps the mechanics
         * (cover AND win outright, +:points) intact.
         */
        'picks.kicker.underdog_note' => [
            'pg' => 'Upset kicker: a dog pick that covers AND wins outright pays :points extra.',
            'pg13' => 'Upset kicker: your dog covers and wins the game, you bank +:points on top.',
            'r' => 'Upset kicker: dog covers, dog wins outright — +:points and permanent bragging rights.',
        ],

        /*
         * The invite landing — /join/{CODE}, the URL a group travels by.
         * The preview card's facts stay plain; these lines are the mood
         * around the one decision on the screen.
         */
        'join.pitch' => [
            'pg' => 'Pick against the spread with :group every week — one slate, everyone in.',
            'pg13' => 'One slate a week, everyone on the record. :group is waiting on you.',
            'r' => ':group wants you on the record. Scared money picks nothing.',
        ],

        'join.miss' => [
            'pg' => "That invite doesn't match a group. Ask your friend for a fresh link — or start your own.",
            'pg13' => 'This link opens nothing. Get a fresh one from whoever sent it — or start your own group and send the links yourself.',
            'r' => 'Dead link. Shake down whoever sent it for a real one — or start your own group and do it right.',
        ],

        'join.room.played' => [
            'pg' => "This week's games are underway. Catch the next room when the new slate posts.",
            'pg13' => "This room's week already kicked. Fresh rooms open when the next slate drops.",
            'r' => 'You missed kickoff — this room is playing without you. Next slate, be early.',
        ],

        /*
         * THE BEAR SPEAKS — one taunt per weekly theme, rendered under his
         * factual theme line (BearPicks::themeLine says WHO he rides; these
         * say it in his voice). He roasts picks and teams, never people —
         * the house's creature keeps the house's rules.
         */
        'picks.bear.tagline.favorites' => [
            'pg' => "I'll take the good teams. You take your chances.",
            'pg13' => 'Chalk never apologizes. Neither do I.',
            'r' => 'Riding every favorite and sleeping like a cub. Fade me, cowards.',
        ],

        'picks.bear.tagline.dogs' => [
            'pg' => 'Every underdog has his day. This week they all do.',
            'pg13' => 'All dogs, all week. Bite me.',
            'r' => 'All dogs this week. When they hit, I want to hear every excuse.',
        ],

        'picks.bear.tagline.home' => [
            'pg' => 'Home cooking all week. The crowd counts for something.',
            'pg13' => "Every home team. 100,000 screaming reasons I'm right.",
            'r' => 'Home teams only. A road favorite is just a point total waiting to die.',
        ],

        'picks.bear.tagline.road' => [
            'pg' => 'Road teams all week. Silence is my favorite sound.',
            'pg13' => 'All road teams. Nothing sweeter than a quiet stadium.',
            'r' => 'Every road team. I live to hear a home crowd shut all the way up.',
        ],

        'picks.bear.tagline.alternating' => [
            'pg' => 'A little chalk, a little chaos — I like balance.',
            'pg13' => 'Chalk, dog, chalk, dog. Balance is a weapon.',
            'r' => 'Half chalk, half chaos, zero mercy. Good luck reading me.',
        ],

        'group.mode_changed' => [
            'pg' => 'Your commissioner changed the group\'s game to :mode. New slates play by its rules.',
            'pg13' => 'The commissioner switched the group to :mode. New week, new rules.',
            'r' => 'Commissioner\'s decree: :mode from here on. Adapt or donate points.',
        ],

        'notify.mode_changed.subject' => [
            'pg' => ':group plays :mode now',
            'pg13' => ':group plays :mode now',
            'r' => ':group plays :mode now',
        ],

        'notify.mode_changed.body' => [
            'pg' => 'Your commissioner changed :group\'s game to :mode. From the next slate, that\'s what you\'re picking.',
            'pg13' => 'The commissioner moved :group to :mode. New rules from the next slate — check the group before you pick.',
            'r' => 'The commissioner moved :group to :mode. New rules, same trash talk. Check the group before you pick.',
        ],

        /*
         * THE WEEKLY LOOP — picks are due, and here is how you did.
         *
         * Every one of these renders from a QUEUED job, so every caller
         * passes `for:` or the reader silently gets the PG-13 line. Subjects
         * carry the FACT and may read the same in all three registers; the
         * body is where the register lives. The instruction survives every
         * rung: a reminder that does not say what is owed and when it locks
         * is a joke wearing a reminder's clothes.
         */
        'notify.reminder.subject' => [
            'pg' => ':owed picks due in :group',
            'pg13' => ':owed picks due in :group',
            'r' => ':owed picks due in :group',
        ],

        'notify.reminder.multi' => [
            'pg' => ':owed picks due across :count cards',
            'pg13' => ':owed picks due across :count cards',
            'r' => ':owed picks due across :count cards',
        ],

        'notify.reminder.body' => [
            'pg' => 'You have :owed of :total picks left in :group. First kickoff is :when, and picks lock game by game from there.',
            'pg13' => ':owed picks still open in :group. First kick is :when, and every game locks the second it starts — there are no extensions.',
            'r' => ':owed picks sitting open in :group. First kick :when. Anything unpicked grades as a zero, which is a decision you are making on purpose.',
        ],

        'notify.reminder.push' => [
            'pg' => ':owed picks left in :group.',
            'pg13' => ':owed picks still open in :group. The clock is running.',
            'r' => ':owed picks open in :group. Zeros are forever.',
        ],

        /* One line, no greeting: an SMS is read from a lock screen. */
        'notify.reminder.sms' => [
            'pg' => ':owed picks due in :group. First kick :when.',
            'pg13' => ':owed picks open in :group, first kick :when. Unpicked games score nothing.',
            'r' => ':owed picks open in :group, first kick :when. Unpicked is a zero, every time.',
        ],

        'notify.last_call.subject' => [
            'pg' => 'Last call — :owed picks in :group',
            'pg13' => 'Last call — :owed picks in :group',
            'r' => 'Last call — :owed picks in :group',
        ],

        'notify.last_call.body' => [
            'pg' => 'First kickoff is :when and you still have :owed picks open in :group.',
            'pg13' => 'Last call: :owed picks open in :group, first kick :when. After that they are zeros.',
            'r' => 'Last call. :owed picks open in :group, kickoff :when, and nobody is going to make them for you.',
        ],

        'notify.last_call.push' => [
            'pg' => 'Last call — :owed picks in :group.',
            'pg13' => 'Last call: :owed picks open in :group, kick at :when.',
            'r' => 'Last call. :owed unpicked in :group, :when.',
        ],

        'notify.results.subject' => [
            'pg' => ':week is official — :group',
            'pg13' => ':week is official — :group',
            'r' => ':week is official — :group',
        ],

        'notify.results.won.body' => [
            'pg' => 'You won :week in :group with :points points — :xp XP and a Beast Latte.',
            'pg13' => 'You took :week in :group. :points points, :xp XP, and a Beast Latte with your name on it.',
            'r' => ':points points, and :week belongs to you. :xp XP, one Beast Latte, and a group chat that has to sit with it.',
        ],

        'notify.results.won.shared' => [
            'pg' => 'You tied with :others for the win, and everyone who tied gets paid in full.',
            'pg13' => 'You and :others tied it. Split week, full payout each.',
            'r' => 'You and :others tied. Both paid, and neither of you gets to gloat cleanly.',
        ],

        'notify.results.lost.body' => [
            'pg' => ':week is official in :group. You finished :place of :field with :points points.',
            'pg13' => ':week is in the books: :place of :field in :group, :points points.',
            'r' => ':week is official. :place of :field in :group on :points points. Somebody had to be.',
        ],

        'notify.results.missed.body' => [
            'pg' => ':group finished :week without you. :winner won it.',
            'pg13' => ':group played :week without you. :winner won, and will be telling people.',
            'r' => ':week happened without you. :winner took it, and you have no receipts to argue with.',
        ],

        'notify.results.exhibition' => [
            'pg' => 'This was a practice week — it does not count toward the season.',
            'pg13' => 'Practice week. It pays, but the season does not remember it.',
            'r' => 'Practice week. Pays the same, counts for nothing, and every brag comes with an asterisk.',
        ],

        /*
         * The nemesis: whoever finished one place away. Not a stored
         * relationship — a weekly pick'em rivalry genuinely IS week to week,
         * and this is the adjacency the settled field already knows. It
         * roasts the RESULT, never the person, which is what keeps it inside
         * the age rating.
         */
        'notify.results.nemesis' => [
            'pg' => ':rival finished one spot ahead of you, by :margin points.',
            'pg13' => ':rival finished one spot ahead — :margin points, and that is the whole gap.',
            'r' => ':rival beat you by :margin points and one place. Remember it next Saturday.',
        ],

        'notify.results.nemesis.won' => [
            'pg' => ':rival finished :margin points behind you.',
            'pg13' => ':rival came up :margin points short. They will mention it.',
            'r' => ':rival missed you by :margin. Enjoy it, briefly.',
        ],

        'notify.results.bear.beat' => [
            'pg' => 'You beat the Bear by :margin, and that is the bonus.',
            'pg13' => 'You beat the Bear by :margin. He does not take it well.',
            'r' => 'Beat the Bear by :margin. He is going to remember your name.',
        ],

        'notify.results.bear.lost' => [
            'pg' => 'The Bear finished :margin points ahead of you this week.',
            'pg13' => 'The Bear got you by :margin. He is insufferable about it.',
            'r' => 'The Bear beat you by :margin without watching a single snap.',
        ],

        'notify.inbox.empty' => [
            'pg' => 'Nothing here yet. Kickoff alerts and your weekly results land here.',
            'pg13' => 'Empty for now. Kickoff alerts, pick reminders and Saturday\'s damage all land here.',
            'r' => 'Nothing yet. Give it one Saturday and this fills with things you would rather not reread.',
        ],

        /*
         * The pick surface — the control the whole product exists for.
         * The lock label itself stays plain ("Locked") because it is a
         * state a reader scans for; everything around it speaks.
         */
        'picks.claim.heading' => [
            'pg' => 'Claim your handle',
            'pg13' => 'Claim your handle',
            'r' => 'Claim your handle',
        ],

        'picks.claim.body' => [
            'pg' => 'Picks need a name on them. Pick a handle so your group knows whose calls are whose.',
            'pg13' => 'Picks need a name on them. Claim your handle — it\'s what the group sees when you\'re right, and when you\'re not.',
            'r' => 'Picks need a name on them. Claim your handle — it\'s what the group screenshots when your calls age badly.',
        ],

        'picks.claim.done' => [
            'pg' => '@:handle it is. Make your picks.',
            'pg13' => '@:handle it is. Now get your picks in.',
            'r' => '@:handle. No hiding now.',
        ],

        'picks.tiebreaker.hint' => [
            'pg' => 'Answer the week\'s tiebreaker question — the closest call wins the ties.',
            'pg13' => 'Call the week\'s tiebreaker number. Closest settles the arguments.',
            'r' => 'Call the number. Closest settles every argument this slate starts.',
        ],

        'picks.tiebreaker.saved' => [
            'pg' => 'Tiebreaker saved: :total points.',
            'pg13' => ':total points, on the record.',
            'r' => ':total points. Bold. It\'s on the record.',
        ],

        // The refusal when an answer is outside what the question could
        // produce. The bounds stay in the line — the instruction survives
        // every register.
        'picks.tiebreaker.invalid' => [
            'pg' => 'Keep it between 0 and :max.',
            'pg13' => 'Between 0 and :max — a number a football game could actually produce.',
            'r' => 'Between 0 and :max. This is football, not pinball.',
        ],

        /*
         * The race a countdown cannot hide: a reader sitting on the slate
         * at kickoff taps a card the very second it locks. Silence there
         * reads as a dead button; this is the honest answer. Roasts the
         * pick that never happened, never the person.
         */
        'picks.locked.notice' => [
            'pg' => 'That game just kicked off — picks lock at kickoff.',
            'pg13' => 'That game kicked. Whatever you were about to pick stays unpicked.',
            'r' => 'Kicked. The line closed while that pick was still a thought.',
        ],

        // The sticky chrome's reason line for the handleless — the claim
        // box below is the action; this names why the cards render locked.
        'picks.claim.reason' => [
            'pg' => 'Claim a handle to make your picks.',
            'pg13' => 'Claim a handle to pick — the group needs a name to argue with.',
            'r' => 'No handle, no picks. Claim one below.',
        ],

        /*
         * The Conversation. A LOUD surface everywhere it appears — including
         * on Game and Team, whose FACTS stay pure: the chrome around the
         * thread speaks, the box score above it never does.
         *
         * The composer's placeholder is deliberately NOT here. It is an
         * affordance, and a joke standing between a writer and the box they
         * type in is friction, not voice.
         */
        'talk.house_rule' => [
            'pg' => 'Roast the pick, the team, the record. Never the person.',
            'pg13' => 'Roast the pick, the team, the record — never the person.',
            'r' => 'Roast the pick, the team, the record. Never the person. That one isn\'t negotiable.',
        ],

        'talk.subheading.game' => [
            'pg' => 'Talk about the game — the call, the clock, the coaching.',
            'pg13' => 'The call, the clock, the coaching. All fair game.',
            'r' => 'Say what you want about this game. The people reading are off limits.',
        ],

        'talk.subheading.team' => [
            'pg' => 'Talk about this team — the season, the schedule, the record.',
            'pg13' => 'The season, the schedule, the record. Say your piece.',
            'r' => 'Season, schedule, record — all of it is fair. Everybody reading is not.',
        ],

        'talk.subheading.group' => [
            'pg' => 'Your group\'s room. Talk about the slate.',
            'pg13' => 'Your group\'s room. Where the bad picks get remembered.',
            'r' => 'Your group\'s room. Nothing said here gets forgotten by November.',
        ],

        'talk.empty.game' => [
            'pg' => 'Nobody has said anything yet. Go first.',
            'pg13' => 'Dead quiet. Somebody has to go first.',
            'r' => 'Nothing here yet. Be the one who starts it.',
        ],

        'talk.empty.team' => [
            'pg' => 'Nobody is talking about this team yet.',
            'pg13' => 'Nobody is talking about this team. Fix that.',
            'r' => 'Silence. Depending on the season, that might be mercy.',
        ],

        'talk.empty.group' => [
            'pg' => 'Your group hasn\'t said anything yet.',
            'pg13' => 'Your group is quiet. Suspiciously quiet.',
            'r' => 'Nobody\'s talking. Everybody\'s sitting on a bad week.',
        ],

        'talk.claim.body' => [
            'pg' => 'Posts need a name on them. Claim a handle and you can join in.',
            'pg13' => 'Posts need a name on them. Claim your handle — anonymous takes are worth what they cost.',
            'r' => 'Posts need a name on them. Claim your handle and own what you say.',
        ],

        'talk.claim.done' => [
            'pg' => '@:handle it is. Say something.',
            'pg13' => '@:handle it is. Start talking.',
            'r' => '@:handle. Now everyone knows who to blame.',
        ],

        'talk.verify_first' => [
            'pg' => 'Verify your email first — then you can join the conversation.',
            'pg13' => 'Verify your email first. The room likes to know who\'s talking.',
            'r' => 'Verify your email first. Nobody mouths off from behind a curtain here.',
        ],

        'talk.not_member' => [
            'pg' => 'Join the group to talk in it.',
            'pg13' => 'You\'re reading someone else\'s room. Join it to say anything.',
            'r' => 'Reading is free. Talking costs a membership.',
        ],

        'talk.guest' => [
            'pg' => 'Sign in to join the conversation.',
            'pg13' => 'Sign in if you want a say in this.',
            'r' => 'Sign in. Lurking is free; talking isn\'t.',
        ],

        'talk.too_fast' => [
            'pg' => 'Give it a moment — :seconds seconds before your next post.',
            'pg13' => 'Easy. :seconds seconds before you go again.',
            'r' => 'Slow down. :seconds seconds before the next one.',
        ],

        'talk.deleted' => [
            'pg' => 'Post removed.',
            'pg13' => 'Gone. Like it never happened.',
            'r' => 'Deleted. We\'ll all pretend we didn\'t read it.',
        ],

        /*
         * The ladder. Rung NAMES are not here on purpose — a rank is a label
         * the reader scans for and compares with somebody else's, so it says
         * the same word in every register. What speaks is the copy around it.
         */
        'rank.to_next' => [
            'pg' => ':remaining XP to :next.',
            'pg13' => ':remaining XP and you\'re a :next.',
            'r' => ':remaining XP from :next. Nobody\'s handing it to you.',
        ],

        'rank.topped_out' => [
            'pg' => 'Top of the ladder. Nothing left to climb.',
            'pg13' => 'Top of the ladder. There is nothing above this.',
            'r' => 'Top of the ladder. Everyone else is still climbing.',
        ],
    ];

    /**
     * The lines a model is shown so a generated recap sounds like the app
     * rather than like a model being funny.
     *
     * Curated by hand and ORDERED, never sampled. A prompt that changes shape
     * between two readers is a prompt nobody can debug, and one reader's email
     * should not swing register week to week because a shuffle came up
     * differently.
     *
     * Every key is a LOUD surface. Nothing from Scores or League is here —
     * those report facts and have no voice to imitate.
     *
     * @var list<string>
     */
    private const EXEMPLARS = [
        'mail.newsletter.intro',
        'mail.newsletter.empty',
        'teams.subheading',
        'home.first_team',
        'home.pickem',
        'picks.screen.pitch',
        'leaderboard.empty',
        'history.empty',
        'talk.empty.team',
        'groups.mine.empty',
    ];

    /**
     * A line at the reader's level, or the closest one below it.
     *
     * @param  array<string, string|int>  $replace
     */
    public static function line(string $key, array $replace = [], ?User $for = null): string
    {
        $rating = ($for ?? auth()->user())?->content_rating ?? ContentRating::Pg13;

        $line = self::variant($key, $rating);

        return $line === '' ? '' : self::fill($line, $replace);
    }

    /**
     * Six to ten lines already written in this register, as few-shot examples.
     *
     * Reads the SAME map the screens read, deliberately: the register is
     * defined in exactly one place, so a line reworded on a screen reworks the
     * model's example with it and the two can never drift into two voices.
     *
     * A line carrying a `:placeholder` is SKIPPED rather than filled. Filling
     * it would need values that do not exist here, and showing it raw teaches
     * the model that emitting `:points` is a thing this app does.
     *
     * @return list<string>
     */
    public static function exemplars(ContentRating $rating, int $limit = 8): array
    {
        $lines = [];

        foreach (self::EXEMPLARS as $key) {
            if (count($lines) >= $limit) {
                break;
            }

            $line = self::variant($key, $rating);

            if ($line === '' || preg_match('/(?<!\\w):[a-z_]{2,}/', $line) === 1) {
                continue;
            }

            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * The raw line for a rating, unfilled — the resolution both readers share.
     *
     * `includes()` runs mildest-first, so the reader's own level is last: walk
     * back from there and take the first line that exists. That is the ladder
     * falling DOWN and never up, which is why a key defining only `pg` is safe
     * to add while one defining only `r` never reaches anybody who did not ask
     * for it.
     */
    private static function variant(string $key, ContentRating $rating): string
    {
        $variants = self::LINES[$key] ?? [];

        if ($variants === []) {
            return '';
        }

        foreach (array_reverse($rating->includes()) as $level) {
            if (isset($variants[$level->value])) {
                return $variants[$level->value];
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

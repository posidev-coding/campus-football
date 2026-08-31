<?php

use App\Ai\Agents\WeeklyRecap;
use App\Enums\AiModel;
use App\Enums\ContentRating;
use App\Jobs\SendWeeklyNewsletter;
use App\Models\AiSpend;
use App\Models\Game;
use App\Models\Season;
use App\Models\Team;
use App\Models\User;
use App\Notifications\WeeklyNewsletter;
use App\Support\AiFailure;
use App\Support\RecapSweep;
use App\Support\RecapWriter;
use App\Support\Voice;
use App\Support\WeeklyDigest;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Support\Facades\Notification;
use Laravel\Ai\Exceptions\RateLimitedException;

/*
 * The recap is the only copy in this app a human never reads before it sends,
 * and it sends to everybody at once. Every guard below is what stands between
 * a model having an off Tuesday and three hundred inboxes.
 *
 * Every rejection returns NULL, and null means the deterministic newsletter —
 * copy that shipped months before any of this and was already good enough. So
 * these tests assert two things each time: that the bad recap died, and that
 * the reader still got an email.
 */

beforeEach(function () {
    config()->set('cfb.ai_enabled', true);
    config()->set('cfb.ai_recaps', true);

    $this->season = Season::factory()->create(['year' => 2026, 'type' => Season::REGULAR]);

    $this->vols = Team::factory()->create([
        'display_name' => 'Tennessee Volunteers',
        'location' => 'Tennessee',
    ]);

    $this->cats = Team::factory()->create([
        'display_name' => 'Kentucky Wildcats',
        'location' => 'Kentucky',
    ]);

    $this->game = Game::factory()->create([
        'season_id' => $this->season->id,
        'home_team_id' => $this->cats->id,
        'away_team_id' => $this->vols->id,
        'home_score' => 17,
        'away_score' => 24,
        'completed' => true,
        'kickoff_at' => now()->subDays(3),
    ]);
});

/** A reader following Tennessee, at the register the test cares about. */
function recapReader(ContentRating $rating = ContentRating::Pg13): User
{
    $user = User::factory()->create([
        'first_name' => 'Taylor',
        'content_rating' => $rating,
        'newsletter_opt_in' => true,
    ]);

    $user->followedTeams()->attach(test()->vols->id, ['position' => 1]);

    return $user;
}

function recapAnswer(array $overrides = []): array
{
    return [
        'headline' => 'The Vols found a way in Lexington',
        'body' => [
            'Tennessee went on the road and left with a 24-17 win. Nobody is calling it art.',
            'Alabama is next, at home. Clear your Saturday and lower your blood pressure now.',
        ],
        ...$overrides,
    ];
}

function writeRecap(User $user): ?array
{
    return app(RecapWriter::class)->for($user, WeeklyDigest::for($user));
}

describe('the calls it never makes', function () {
    it('never prompts while the recap flag is closed', function () {
        // The flag's VALUE, not Feature::active() — Pennant's database driver
        // would persist a row per subscriber every Tuesday and then answer
        // from those rows after the flag was flipped back.
        config()->set('cfb.ai_recaps', false);
        WeeklyRecap::fake();

        expect(writeRecap(recapReader()))->toBeNull();
        WeeklyRecap::assertNeverPrompted();
    });

    it('never prompts while the master switch is off, whatever the feature flag says', function () {
        config()->set('cfb.ai_enabled', false);
        WeeklyRecap::fake();

        expect(writeRecap(recapReader()))->toBeNull();
        WeeklyRecap::assertNeverPrompted();
    });

    it('never prompts once the month is spent', function () {
        config()->set('cfb.ai_monthly_budget', 1);
        AiSpend::create([
            'model' => AiModel::Sonnet5->value,
            'feature' => 'recap',
            'input_tokens' => 1000,
            'output_tokens' => 500,
            'cost' => 1.5,
        ]);
        WeeklyRecap::fake();

        expect(writeRecap(recapReader()))->toBeNull();
        WeeklyRecap::assertNeverPrompted();
    });

    it('never prompts for a week nobody played', function () {
        /*
         * The empty-week email is a DIFFERENT email and it is already written.
         * Paying a model to say "nothing happened" is the one call here with a
         * guaranteed-worse outcome than not making it.
         */
        $quiet = User::factory()->create(['newsletter_opt_in' => true]);
        $quiet->followedTeams()->attach($this->cats->id, ['position' => 1]);
        $this->game->forceFill(['completed' => false])->save();

        WeeklyRecap::fake();

        expect(writeRecap($quiet))->toBeNull();
        WeeklyRecap::assertNeverPrompted();
    });
});

describe('the register', function () {
    it('few-shots the RECIPIENT\'s own lines with nobody signed in', function () {
        /*
         * The bug this exists for is the one Voice::line() already carries a
         * warning about, moved one layer out: a queued job has no auth()->user(),
         * so a register read from the request would silently be PG-13 for
         * everybody. Deliberately does NOT actingAs — acting as the reader
         * would make the wrong lookup resolve correctly by accident.
         */
        expect(auth()->check())->toBeFalse();

        WeeklyRecap::fake([recapAnswer()]);

        writeRecap(recapReader(ContentRating::R));

        WeeklyRecap::assertPrompted(function ($prompt): bool {
            $instructions = (string) $prompt->agent->instructions();

            return str_contains($instructions, "Here's the damage. Read it standing up.")
                && str_contains($instructions, 'No Mercy')
                && ! str_contains($instructions, "Here's how your teams got on. No editorializing. Much.");
        });
    });

    it('hands a PG reader only the PG rungs of the ladder', function () {
        WeeklyRecap::fake([recapAnswer()]);

        writeRecap(recapReader(ContentRating::Pg));

        WeeklyRecap::assertPrompted(function ($prompt): bool {
            $instructions = (string) $prompt->agent->instructions();

            return str_contains($instructions, "Here's how your teams got on this week.")
                && ! str_contains($instructions, "Here's the damage. Read it standing up.");
        });
    });

    it('reads its examples out of the same map the screens read', function () {
        // One definition of the register, so a line reworded on a screen
        // reworks the model's example with it and the two cannot drift.
        $r = Voice::exemplars(ContentRating::R);
        $pg = Voice::exemplars(ContentRating::Pg);

        expect($r)->toContain("Here's the damage. Read it standing up.")
            ->and($pg)->toContain("Here's how your teams got on this week.")
            ->and(count($r))->toBeGreaterThanOrEqual(6)
            ->and(count($r))->toBeLessThanOrEqual(10);
    });

    it('skips an exemplar carrying an unfilled placeholder', function () {
        // Showing `:points` raw teaches the model that emitting it is a thing
        // this app does, and there are no values here to fill it with.
        foreach (ContentRating::cases() as $rating) {
            foreach (Voice::exemplars($rating) as $line) {
                expect($line)->not->toMatch('/(?<!\w):[a-z_]{2,}/');
            }
        }
    });
});

describe('the facts', function () {
    it('hands over the real score and nothing it could have invented', function () {
        WeeklyRecap::fake([recapAnswer()]);

        writeRecap(recapReader());

        WeeklyRecap::assertPrompted(fn ($prompt): bool => str_contains($prompt->prompt, 'Won 24-17 at Kentucky')
            && str_contains($prompt->prompt, 'Tennessee Volunteers')
            && str_contains($prompt->prompt, 'every true thing you may say'));
    });

    it('dates every result, so the model never has to guess where it sat', function () {
        /*
         * The first real recap called a bowl defeat and a rivalry finale
         * "both open with losses", because the next fixtures were in
         * September and nothing said when the games had been played. A
         * missing fact is an invitation to invent one, and no sweep can
         * catch a wrong characterization of a real score.
         */
        WeeklyRecap::fake([recapAnswer()]);

        writeRecap(recapReader());

        WeeklyRecap::assertPrompted(fn ($prompt): bool => str_contains(
            $prompt->prompt,
            'Won 24-17 at Kentucky, '.$this->game->kickoff_at->setTimezone(config('cfb.timezone'))->format('M j, Y'),
        ));
    });

    it('states an absence rather than substituting a default', function () {
        /*
         * `null` means no data and callers skip — they never substitute. A
         * team with no next game must reach the model as "nothing scheduled",
         * never as a zero or an invented opponent, because the model cannot
         * tell the difference and the reader cannot either.
         */
        $user = recapReader();
        $user->followedTeams()->attach($this->cats->id, ['position' => 2]);

        WeeklyRecap::fake([recapAnswer()]);
        writeRecap($user);

        WeeklyRecap::assertPrompted(fn ($prompt): bool => str_contains($prompt->prompt, 'nothing scheduled yet'));
    });
});

describe('the sweep', function () {
    $reject = function (array $recap, ContentRating $rating = ContentRating::Pg13, string $facts = '') {
        return app(RecapSweep::class)->reasons($recap, $rating, $facts);
    };

    it('passes copy that is doing its job', function () use ($reject) {
        expect($reject(recapAnswer()))->toBe([]);
    });

    it('kills a roast aimed at the reader instead of the football', function () use ($reject) {
        // One pronoun apart, and the whole App Store age rating sits on it.
        expect($reject(recapAnswer(['body' => ['Your defense is a dumpster fire.']])))->toBe([])
            ->and($reject(recapAnswer(['body' => ["You're an idiot for following them."]])))
            ->not->toBe([]);
    });

    it('kills a joke about the reader\'s life rather than their Saturday', function () use ($reject) {
        expect($reject(recapAnswer(['body' => ['Your marriage can survive one more Saturday like that.']])))
            ->not->toBe([]);
    });

    it('bans profanity at EVERY register, R included', function () use ($reject) {
        /*
         * A deliberate product line: these registers differ in attitude, not
         * in vocabulary. Every `r` line in Voice is clean, so generated copy
         * that swore would be louder than anything a human wrote here.
         */
        foreach (ContentRating::cases() as $rating) {
            expect($reject(recapAnswer(['headline' => 'What a shitshow in Lexington']), $rating))
                ->not->toBe([]);
        }
    });

    it('lets PG-13 keep the mild words PG cannot have', function () use ($reject) {
        $mild = recapAnswer(['body' => ['That was one hell of a fourth quarter.']]);

        expect($reject($mild, ContentRating::Pg13))->toBe([])
            ->and($reject($mild, ContentRating::Pg))->not->toBe([]);
    });

    it('catches the British spellings a football writer reaches for', function () use ($reject) {
        expect($reject(recapAnswer(['body' => ['The defence held on third down.']])))
            ->not->toBe([])
            ->and($reject(recapAnswer(['body' => ['The defense held on third down.']])))
            ->toBe([]);
    });

    it('lets Georgia through only as live data', function () use ($reject) {
        // The pilot audience is Tennessee alumni. Georgia may reach a screen
        // as a fact and never as a joke, an example or an aside.
        $named = recapAnswer(['body' => ['At least you are not Georgia this week.']]);

        expect($reject($named))->not->toBe([])
            ->and($reject($named, ContentRating::Pg13, 'Lost 10-31 vs Georgia'))->toBe([]);
    });

    it('kills an essay, a heading, a link and an emoji', function () use ($reject) {
        expect($reject(recapAnswer(['body' => [str_repeat('long ', 200)]])))->not->toBe([])
            ->and($reject(recapAnswer(['body' => ['## Week in review']])))->not->toBe([])
            ->and($reject(recapAnswer(['body' => ['Read more at https://espn.com']])))->not->toBe([])
            ->and($reject(recapAnswer(['headline' => 'What a win 🏈'])))->not->toBe([]);
    });

    it('kills an empty shape before it reads a word of it', function () use ($reject) {
        expect($reject(['headline' => '', 'body' => ['ok']]))->not->toBe([])
            ->and($reject(['headline' => 'ok', 'body' => []]))->not->toBe([])
            ->and($reject(['headline' => 'ok', 'body' => ['']]))->not->toBe([]);
    });
});

describe('what a rejection costs', function () {
    it('returns null and charges the call anyway', function () {
        /*
         * The tokens were spent whatever the sweep then decided. A budget that
         * only counts the calls it liked undercounts exactly when something is
         * going wrong.
         */
        WeeklyRecap::fake([recapAnswer(['headline' => 'What a shitshow in Lexington'])]);

        expect(writeRecap(recapReader()))->toBeNull()
            ->and(AiSpend::where('feature', 'recap')->count())->toBe(1);
    });

    it('returns null rather than throwing when the API fails', function () {
        WeeklyRecap::fake(fn () => throw new RuntimeException('gateway exploded'));

        expect(writeRecap(recapReader()))->toBeNull();
    });

    it('tells our own spend limit apart from the tier cap', function () {
        /*
         * Both are walls, both route here, and neither says "you are out of
         * money" — the tier cap in particular arrives dressed as ordinary rate
         * limiting, which is a thing you wait out. It is not.
         */
        $ours = new RequestException(new ClientResponse(new Psr7Response(400, [], json_encode([
            'type' => 'error',
            'error' => [
                'type' => 'invalid_request_error',
                'message' => 'You have reached your specified API usage limits.',
            ],
        ]))));

        $tier = RateLimitedException::forProvider('anthropic', 429, new RequestException(
            new ClientResponse(new Psr7Response(429, [], json_encode([
                'type' => 'error',
                'error' => [
                    'type' => 'rate_limit_error',
                    'details' => ['error_code' => 'enforced_spend_limit_reached'],
                ],
            ])))
        ));

        expect(AiFailure::classify($ours))->toBe(AiFailure::OUR_LIMIT)
            ->and(AiFailure::classify($tier))->toBe(AiFailure::TIER_CAP)
            ->and(AiFailure::classify(new RuntimeException('boom')))->toBe(AiFailure::FAILED)
            ->and(AiFailure::describe($tier))->toContain('No retry clears');
    });
});

describe('the email itself', function () {
    it('renders the recap above the rows when there is one', function () {
        $user = recapReader();
        $mail = (new WeeklyNewsletter(WeeklyDigest::for($user), recapAnswer()))->toMail($user);

        expect((string) $mail->render())
            ->toContain('The Vols found a way in Lexington')
            ->toContain('Nobody is calling it art')
            // The deterministic intro steps aside rather than doubling up.
            ->not->toContain('No editorializing. Much.');
    });

    it('carries the recap out through the job that already sends the email', function () {
        // The wiring, not the writer: the call belongs inside the per-user job
        // so one reader's failure costs one reader their recap.
        Notification::fake();
        WeeklyRecap::fake([recapAnswer()]);

        $user = recapReader();
        (new SendWeeklyNewsletter($user->id))->handle();

        Notification::assertSentTo(
            $user,
            WeeklyNewsletter::class,
            fn (WeeklyNewsletter $notification): bool => $notification->recap['headline'] === 'The Vols found a way in Lexington',
        );
    });

    it('still sends, with no recap at all, while the flag is closed', function () {
        // The feature being off has to look like Monday rather than an outage.
        config()->set('cfb.ai_recaps', false);
        Notification::fake();
        WeeklyRecap::fake();

        $user = recapReader();
        (new SendWeeklyNewsletter($user->id))->handle();

        Notification::assertSentTo(
            $user,
            WeeklyNewsletter::class,
            fn (WeeklyNewsletter $notification): bool => $notification->recap === null,
        );
    });

    it('falls back to the copy that shipped first when there is not', function () {
        $user = recapReader();
        $mail = (new WeeklyNewsletter(WeeklyDigest::for($user)))->toMail($user);

        expect((string) $mail->render())
            ->toContain('No editorializing. Much.')
            // Real content either way: the rows are never what fails here.
            ->toContain('Won 24-17 at Kentucky');
    });
});

<?php

use App\Actions\RecordClientError;
use App\Models\ClientError;
use App\Models\User;

/** A well-formed report, overridable per assertion. */
function clientError(array $overrides = []): array
{
    return [
        'kind' => 'error',
        'message' => "Cannot read properties of undefined (reading 'games')",
        'source' => 'https://campusfootball.test/build/assets/app-D3Kf9x2b.js',
        'line' => 412,
        'col' => 17,
        'stack' => "TypeError: ...\n    at swiper (app-D3Kf9x2b.js:412:17)",
        'path' => '/scoreboard',
        'viewport' => 390,
        'standalone' => true,
        ...$overrides,
    ];
}

describe('reporting', function () {
    it('takes a report from a guest', function () {
        // The whole point of leaving this endpoint open: Home, a game, the
        // lobby and the invite landing all render without a session, and a
        // broken public page is the report worth having most.
        $this->postJson(route('client-errors.store'), clientError())->assertNoContent();

        $error = ClientError::sole();

        expect($error->kind)->toBe('error')
            ->and($error->line)->toBe(412)
            ->and($error->viewport)->toBe(390)
            ->and($error->standalone)->toBeTrue()
            ->and($error->user_id)->toBeNull()
            ->and($error->reports)->toBe(1);
    });

    it('attributes a report to the signed-in reader', function () {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson(route('client-errors.store'), clientError())->assertNoContent();

        expect(ClientError::sole()->user_id)->toBe($user->id);
    });

    it('keeps the page path and drops its query string', function () {
        // A query string is where a signed link or an invite code rides in.
        $this->postJson(route('client-errors.store'), clientError([
            'path' => '/join/ABC123?invite=secret-token',
        ]))->assertNoContent();

        expect(ClientError::sole()->path)->toBe('/join/ABC123');
    });

    it('ignores a fingerprint or a user the payload tries to choose', function () {
        $this->postJson(route('client-errors.store'), clientError([
            'fingerprint' => str_repeat('a', 40),
            'user_id' => 99,
        ]))->assertNoContent();

        expect(ClientError::sole()->fingerprint)->toBe(RecordClientError::fingerprint(clientError()))
            ->and(ClientError::sole()->user_id)->toBeNull();
    });

    it('refuses a kind it does not know', function () {
        // The app renders validation as redirects everywhere but api/*
        // (bootstrap's shouldRenderJsonWhen), so a bad payload bounces with
        // errors — and, either way, stores nothing.
        $this->post(route('client-errors.store'), clientError(['kind' => 'console']))
            ->assertRedirect()
            ->assertSessionHasErrors('kind');

        expect(ClientError::count())->toBe(0);
    });

    it('truncates rather than rejecting an oversized message', function () {
        // A stack longer than expected is still a bug report; a 422 throws
        // away the only witness the bug has.
        $this->postJson(route('client-errors.store'), clientError([
            'message' => str_repeat('x', 1_500),
        ]))->assertNoContent();

        expect(mb_strlen(ClientError::sole()->message))->toBe(500);
    });

    it('caps an open endpoint at thirty reports a minute', function () {
        foreach (range(1, 30) as $i) {
            $this->postJson(route('client-errors.store'), clientError(['line' => $i]))->assertNoContent();
        }

        $this->postJson(route('client-errors.store'), clientError(['line' => 31]))->assertStatus(429);
    });
});

describe('the Redis dedupe', function () {
    it('writes one row for a repeated error, not one per report', function () {
        // Called twice on purpose. The window is only real on the second
        // report — a single-call test proves nothing about a cache at all.
        $this->postJson(route('client-errors.store'), clientError())->assertNoContent();
        $this->postJson(route('client-errors.store'), clientError())->assertNoContent();

        expect(ClientError::count())->toBe(1);
    });

    it('still separates two different errors', function () {
        $this->postJson(route('client-errors.store'), clientError())->assertNoContent();
        $this->postJson(route('client-errors.store'), clientError(['line' => 900]))->assertNoContent();

        expect(ClientError::count())->toBe(2);
    });

    it('carries the count forward at powers of ten', function () {
        // "This fired 4,000 times" and "this fired once" are different bugs.
        // The row is useless if the dedupe leaves it unable to tell them apart.
        $record = app(RecordClientError::class);

        foreach (range(1, 9) as $ignored) {
            $record->handle(clientError());
        }

        expect(ClientError::sole()->reports)->toBe(1);

        $record->handle(clientError());

        expect(ClientError::sole()->fresh()->reports)->toBe(10);
    });

    it('reopens the window once it has expired', function () {
        $this->postJson(route('client-errors.store'), clientError())->assertNoContent();

        $this->travel(RecordClientError::WINDOW_SECONDS + 1)->seconds();

        $this->postJson(route('client-errors.store'), clientError())->assertNoContent();

        expect(ClientError::count())->toBe(2);
    });
});

describe('the fingerprint', function () {
    it('groups messages that differ only by an id', function () {
        expect(RecordClientError::fingerprint(clientError(['message' => 'game 4210 not found'])))
            ->toBe(RecordClientError::fingerprint(clientError(['message' => 'game 91 not found'])));
    });

    it('survives a deploy renaming the bundle', function () {
        // Without collapsing the content hash, every deploy turns an unfixed
        // bug into a brand new one and the advisor re-proposes it forever.
        expect(RecordClientError::fingerprint(clientError(['source' => '/build/assets/app-D3Kf9x2b.js'])))
            ->toBe(RecordClientError::fingerprint(clientError(['source' => '/build/assets/app-Zq81mn40.js'])));
    });

    it('does not collapse two different scripts', function () {
        expect(RecordClientError::fingerprint(clientError(['source' => '/build/assets/app-D3Kf9x2b.js'])))
            ->not->toBe(RecordClientError::fingerprint(clientError(['source' => '/build/assets/sw-D3Kf9x2b.js'])));
    });
});

describe('retention', function () {
    it('prunes at a month and not before', function () {
        $old = ClientError::create([...clientError(), 'fingerprint' => str_repeat('a', 40)]);
        $old->forceFill(['created_at' => now()->subDays(31)])->save();

        $recent = ClientError::create([...clientError(), 'fingerprint' => str_repeat('b', 40)]);
        $recent->forceFill(['created_at' => now()->subDays(29)])->save();

        $this->artisan('model:prune', ['--model' => [ClientError::class]])->assertSuccessful();

        expect(ClientError::pluck('id')->all())->toBe([$recent->id]);
    });
});

describe('the reporter that ships to the browser', function () {
    it('registers both handlers and reads the endpoint from the page', function () {
        // The layer a test can hold: the automated tab produces no rendering
        // frames, so nothing here can be driven by an interaction test.
        $js = file_get_contents(resource_path('js/app.js'));

        expect($js)->toContain("window.addEventListener('error'")
            ->and($js)->toContain("window.addEventListener('unhandledrejection'")
            ->and($js)->toContain('meta[name=cfb-error-endpoint]');
    });

    it('publishes the endpoint in the head of every page', function () {
        $this->get('/')->assertOk()->assertSee('name="cfb-error-endpoint"', false);
    });
});

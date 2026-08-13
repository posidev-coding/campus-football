<?php

use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;

/**
 * The install signal: `standalone_seen_at`, stamped once by the beacon the
 * first time a signed-in session runs standalone. The stamp is the only
 * install fact a browser tab can read (the web clip inherits the session
 * cookie but no client state), and the post-verify landing branches on it.
 */
describe('the stamp', function () {
    it('stamps once, and first stamp wins', function () {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('standalone.seen'))->assertNoContent();

        $first = $user->fresh()->standalone_seen_at;

        expect($first)->not->toBeNull();

        $this->travel(1)->hours();

        // Over-calling is the client's prerogative; the timestamp is not.
        $this->actingAs($user)->post(route('standalone.seen'))->assertNoContent();

        expect($user->fresh()->standalone_seen_at->equalTo($first))->toBeTrue();
    });

    it('rejects guests', function () {
        $this->post(route('standalone.seen'))->assertRedirect(route('login'));
    });

    it('cannot be mass-assigned', function () {
        // A privileged stamp: reachable only through the beacon route (or a
        // factory state), never through a fillable path — and this app runs
        // strict, so the guarded write THROWS rather than silently dropping.
        $user = User::factory()->create();

        expect(fn () => $user->update(['standalone_seen_at' => now()]))
            ->toThrow(MassAssignmentException::class);

        expect($user->fresh()->standalone_seen_at)->toBeNull();
    });
});

describe('the beacon', function () {
    it('rides the app layout for members, with the retrying session guard', function () {
        $html = $this->actingAs(User::factory()->create())
            ->get(route('home'))
            ->assertOk()
            ->content();

        // sessionStorage on purpose — a failed write retries next session,
        // and a shared phone's second user is not answered for. The guard
        // is only set on a 2xx; the server's null-check is the real
        // idempotence.
        // Path fragment, not route(): @js() JSON-escapes the URL's slashes.
        expect($html)->toContain('data-standalone-beacon')
            ->and($html)->toContain('cfb.standalone.seen')
            ->and($html)->toContain('standalone-seen')
            ->and($html)->toContain('sessionStorage');
    });

    it('renders for no guest and on no auth screen', function () {
        $this->get(route('home'))->assertOk()->assertDontSee('data-standalone-beacon');

        $this->get(route('login'))->assertOk()->assertDontSee('data-standalone-beacon');
    });
});

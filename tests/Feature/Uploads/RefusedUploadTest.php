<?php

use App\Models\User;
use App\Support\Voice;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

/*
 * THE UPLOAD THAT LEFT AND NEVER LANDED.
 *
 * `$wire.upload(name, file, finish, error, progress)` takes an error
 * callback, and the control was calling it with two arguments. A failed
 * round trip therefore changed NOTHING a reader could see: the picker went
 * quiet, the property was never set, the `updated...` hook never ran, and
 * Livewire's own promise rejected with a plain object nobody caught — which
 * reached window.onunhandledrejection, where the reporter could only file it
 * as "[object Object]" with a null source and a null stack. Five of those in
 * a day off /groups/52 and /groups/53, one on a 440px installed session.
 *
 * The server half of that upload is settled (CFB-41 and the mounted-disk
 * work: `R2Writes::harden()` sets `throw => true` so a refused write is a
 * 500 rather than a silent success). This is the browser half — the half
 * that turns the 500 into a sentence.
 *
 * Both halves are held here, and the second one is EVALUATED rather than
 * swept: a `str_contains($html, '$wire.upload(')` would have passed happily
 * on the broken call, and asserting source instead of behavior is how this
 * shipped.
 */

/** The control's own `x-data` object, decoded out of the rendered screen. */
function uploadControlXData(): string
{
    [$commissioner, $group] = pickemContest();

    $html = Livewire::actingAs($commissioner)->test('group', ['group' => $group])->html();

    // The control's JS carries no double quote, so the attribute's own
    // delimiter is a safe boundary.
    preg_match_all('/x-data="([^"]*)"/s', $html, $matches);

    $control = array_values(array_filter(
        $matches[1],
        fn (string $body) => str_contains($body, 'rejectOversizedImage'),
    ));

    expect($control)->toHaveCount(1, 'The guarded file control did not render on the group screen.');

    return html_entity_decode($control[0], ENT_QUOTES);
}

/**
 * Run one scenario against the real rendered control.
 *
 * @return array{calls: list<list<mixed>>, uploads: list<list<mixed>>, errorCallback: ?string}
 */
function uploadControl(string $scenario): array
{
    $source = tempnam(sys_get_temp_dir(), 'cfb-x-data');
    file_put_contents($source, uploadControlXData());

    $result = Process::run(['node', base_path('tests/upload-control-harness.mjs'), $scenario, $source]);

    unlink($source);

    expect($result->successful())->toBeTrue($result->errorOutput());

    return json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);
}

it('hands Livewire an error callback, in the position Livewire reads it from', function () {
    /*
     * Fourth argument, not third and not "somewhere in there": Livewire
     * calls the third on SUCCESS. A callback one slot out would announce a
     * refusal on every upload that worked.
     */
    $run = uploadControl('accepted');

    expect($run['errorCallback'])->toBe('function')
        ->and($run['uploads'])->toHaveCount(1)
        ->and($run['uploads'][0][0])->toBe('iconFile')
        ->and($run['uploads'][0][2])->toBe('function')
        ->and($run['uploads'][0][3])->toBe('function')
        // Nothing is said while the upload is still in flight.
        ->and($run['calls'])->toBe([]);
});

it('knocks on the component when the upload is refused', function () {
    $run = uploadControl('refused');

    expect($run['calls'])->toBe([['reportRefusedUpload', 'iconFile']]);
});

it('still refuses an oversized file without uploading it at all', function () {
    // The gate that already worked, held while the second one is added
    // beside it — one control, two refusals, and neither may eat the other.
    $run = uploadControl('oversized');

    expect($run['calls'])->toBe([['rejectOversizedImage', 'iconFile']])
        ->and($run['uploads'])->toBe([]);
});

it('renders the refusal on the icon\'s own line, where the size gate speaks', function () {
    [$commissioner, $group] = pickemContest();

    Livewire::actingAs($commissioner)->test('group', ['group' => $group])
        ->assertHasNoErrors()
        ->call('reportRefusedUpload', 'iconFile')
        ->assertHasErrors('iconFile')
        ->assertSee(Voice::line('uploads.refused', for: $commissioner));

    expect($group->refresh()->icon)->toBeNull();
});

it('guards the account photo through the same door', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('account')
        ->call('reportRefusedUpload', 'photo')
        ->assertHasErrors('photo')
        ->assertSee(Voice::line('uploads.refused', for: $user));

    expect($user->refresh()->avatar)->toBeNull();
});

it('ignores a property the component does not have', function () {
    // The property name arrives from the client on a public Livewire method.
    [$commissioner, $group] = pickemContest();

    Livewire::actingAs($commissioner)->test('group', ['group' => $group])
        ->call('reportRefusedUpload', 'notAProperty')
        ->assertHasNoErrors();
});

it('names no cause it could not know', function () {
    /*
     * The browser knows the upload did not finish and nothing else. A
     * guessed reason — the connection, the file, the storage — is a default
     * written where there is no data, and the reader cannot act on a guess.
     * What they can act on is the picker, so every register says so.
     */
    foreach (['pg', 'pg13', 'r'] as $register) {
        $line = Voice::line('uploads.refused', for: User::factory()->make(['content_rating' => $register]));

        expect($line)->not->toBe('')
            ->and($line)->not->toMatch('/connection|internet|offline|too big|storage/i')
            ->and($line)->toMatch('/again/i');
    }
});

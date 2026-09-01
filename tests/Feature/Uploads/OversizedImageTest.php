<?php

use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use App\Support\ImageUpload;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Symfony\Component\Finder\Finder;

/*
 * THE CLIFF UNDER EVERY IMAGE UPLOAD.
 *
 * PHP throws away a request body bigger than `post_max_size` and the CSRF
 * token goes with it, so an oversized upload never reaches the validation
 * that would have explained itself: the endpoint answers with an HTML error
 * page, Livewire fails to JSON.parse it, and the reader gets a browser alert
 * reading "The page has expired" with no mention of a file at all. Reported
 * from production on 2026-09-01 against a 22MB PNG, and reproduced on a
 * stock checkout — the limit is PHP's own default, so the cliff is in every
 * environment, and no amount of server-side `max:` can move it.
 *
 * The cap therefore has to be measured in the BROWSER, before the file is a
 * request. These hold the two halves of that: nothing uploads unmeasured,
 * and the number the browser measures against is the same number the server
 * rule states.
 */

it('has no file input bound straight to wire:model anywhere in the views', function () {
    /*
     * wire:model on a file input uploads whatever the picker hands it the
     * moment it is chosen — which is the bug itself, not a style problem.
     * The sweep is repo-wide on purpose: the next screen to want an upload
     * will copy the nearest example, and this decides which example that is.
     */
    $offenders = [];

    foreach (Finder::create()->files()->in(resource_path('views'))->name('*.blade.php') as $file) {
        preg_match_all('/<input\b[^>]*type=["\']file["\'][^>]*>/s', $file->getContents(), $matches);

        foreach ($matches[0] as $tag) {
            if (str_contains($tag, 'wire:model')) {
                $offenders[] = str_replace(resource_path('views').'/', '', $file->getPathname());
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('measures in the browser against the very number the server rule states', function () {
    [$commissioner, $group] = pickemContest();

    $html = Livewire::actingAs($commissioner)->test('group', ['group' => $group])->html();

    // What the control will compare a chosen file's byte size against.
    preg_match('/max:\s*(\d+)\s*\*\s*1024/', $html, $matches);

    expect($matches[1] ?? null)->not->toBeNull()
        ->and((int) $matches[1])->toBe(ImageUpload::MAX_KB)
        ->and(ImageUpload::rules())->toContain('max:'.ImageUpload::MAX_KB);
});

it('offers the guarded control on both upload surfaces', function () {
    [$commissioner, $group] = pickemContest();

    Livewire::actingAs($commissioner)->test('group', ['group' => $group])
        ->assertSee('rejectOversizedImage')
        ->assertSee('Upload a group icon');

    Livewire::actingAs($commissioner)->test('account')
        ->assertSee('rejectOversizedImage')
        ->assertSee('Upload a profile photo');
});

it('reports the browser\'s refusal through the same error bag the rule uses', function () {
    [$commissioner, $group] = pickemContest();

    Livewire::actingAs($commissioner)->test('group', ['group' => $group])
        ->assertHasNoErrors()
        ->call('rejectOversizedImage', 'iconFile')
        ->assertHasErrors('iconFile')
        ->assertSee(ImageUpload::oversizedMessage());

    expect($group->refresh()->icon)->toBeNull();
});

it('says a size in the refusal, because "too big" is not an instruction', function () {
    expect(ImageUpload::oversizedMessage())->toContain('1MB');
});

it('ignores a property the component does not have', function () {
    [$commissioner, $group] = pickemContest();

    Livewire::actingAs($commissioner)->test('group', ['group' => $group])
        ->call('rejectOversizedImage', 'notAProperty')
        ->assertHasNoErrors();
});

it('leaves the server rule standing as the backstop', function () {
    [$commissioner, $group] = pickemContest();
    $member = User::factory()->create();
    GroupMember::factory()->create(['group_id' => $group->id, 'user_id' => $member->id]);

    // Nothing about the browser gate loosens what reaches PHP anyway.
    Livewire::actingAs($commissioner)->test('group', ['group' => $group])
        ->set('iconFile', UploadedFile::fake()->image('speck.jpg', 32, 32))
        ->assertHasErrors('iconFile');

    expect($group->refresh()->icon)->toBeNull()
        ->and(ImageUpload::rules())->toContain('image');
});

it('guards the account photo the same way, which is where the pattern came from', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('account')
        ->call('rejectOversizedImage', 'photo')
        ->assertHasErrors('photo')
        ->assertSee(ImageUpload::oversizedMessage());

    expect($user->refresh()->avatar)->toBeNull();
});

it('renders a group with no icon as initials, untouched by any of this', function () {
    $group = Group::factory()->create(['name' => 'Rocky Top', 'icon' => null]);

    expect($group->iconUrl())->toBeNull()
        ->and($group->initials())->toBe('RT');
});

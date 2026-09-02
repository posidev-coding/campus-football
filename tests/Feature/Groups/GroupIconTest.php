<?php

use App\Actions\SetGroupIcon;
use App\Enums\ContestMode;
use App\Exceptions\NotGroupCommissioner;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use App\Support\ImageUpload;
use App\Support\Voice;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Livewire\Livewire;

/*
 * THE CLUBHOUSE MARK — a commissioner's one piece of group identity.
 *
 * Two disciplines these hold. The seat is the gate and it lives in the
 * Action, so a public Livewire method cannot be talked past the @if that
 * hides the control. And NULL IS THE NORMAL STATE: a group without an icon
 * renders initials, and nothing on any path writes a stand-in path to make
 * the column look populated.
 */

beforeEach(function () {
    Storage::fake(config('cfb.upload_disk'));
});

it('stores a commissioner\'s icon and serves it off the upload disk', function () {
    [$commissioner, $group] = pickemContest();

    app(SetGroupIcon::class)->handle(
        $commissioner,
        $group,
        UploadedFile::fake()->image('clubhouse.jpg', 256, 256),
    );

    $group->refresh();

    expect($group->icon)->not->toBeNull()
        ->and($group->iconUrl())->toContain($group->icon);

    Storage::disk(config('cfb.upload_disk'))->assertExists($group->icon);
});

it('renders initials rather than inventing an icon when there is none', function () {
    $group = Group::factory()->create(['name' => 'Rocky Top Regulars', 'icon' => null]);

    expect($group->iconUrl())->toBeNull()
        ->and($group->initials())->toBe('RT');
});

it('forgets the previous file only AFTER the new path is committed', function () {
    [$commissioner, $group] = pickemContest();

    app(SetGroupIcon::class)->handle($commissioner, $group, UploadedFile::fake()->image('first.jpg', 256, 256));
    $first = $group->refresh()->icon;

    app(SetGroupIcon::class)->handle($commissioner, $group, UploadedFile::fake()->image('second.jpg', 256, 256));
    $second = $group->refresh()->icon;

    expect($second)->not->toBe($first);

    Storage::disk(config('cfb.upload_disk'))->assertExists($second);
    Storage::disk(config('cfb.upload_disk'))->assertMissing($first);
});

it('clears back to null, and to initials with it', function () {
    [$commissioner, $group] = pickemContest();

    app(SetGroupIcon::class)->handle($commissioner, $group, UploadedFile::fake()->image('icon.jpg', 256, 256));
    $path = $group->refresh()->icon;

    app(SetGroupIcon::class)->clear($commissioner, $group);

    expect($group->refresh()->icon)->toBeNull()
        ->and($group->iconUrl())->toBeNull();

    Storage::disk(config('cfb.upload_disk'))->assertMissing($path);
});

it('refuses a plain member, whatever the screen showed them', function () {
    [, $group] = pickemContest();
    $member = User::factory()->create();
    GroupMember::factory()->create(['group_id' => $group->id, 'user_id' => $member->id]);

    expect(fn () => app(SetGroupIcon::class)->handle(
        $member,
        $group,
        UploadedFile::fake()->image('mine.jpg', 256, 256),
    ))->toThrow(NotGroupCommissioner::class);

    expect(fn () => app(SetGroupIcon::class)->clear($member, $group))
        ->toThrow(NotGroupCommissioner::class);

    expect($group->refresh()->icon)->toBeNull();
});

it('refuses a stranger to the group entirely', function () {
    [, $group] = pickemContest();

    expect(fn () => app(SetGroupIcon::class)->handle(
        User::factory()->create(),
        $group,
        UploadedFile::fake()->image('mine.jpg', 256, 256),
    ))->toThrow(NotGroupCommissioner::class);
});

it('uploads from the clubhouse and shows the commissioner the way back', function () {
    [$commissioner, $group] = pickemContest();

    Livewire::actingAs($commissioner)->test('group', ['group' => $group])
        ->assertSee('Upload a group icon')
        ->assertDontSee('Remove icon')
        ->set('iconFile', UploadedFile::fake()->image('clubhouse.jpg', 256, 256))
        ->assertHasNoErrors()
        ->assertSee('Remove icon');

    expect($group->refresh()->icon)->not->toBeNull();
});

it('rejects an image too small to read at icon size, and writes nothing', function () {
    [$commissioner, $group] = pickemContest();

    Livewire::actingAs($commissioner)->test('group', ['group' => $group])
        ->set('iconFile', UploadedFile::fake()->image('speck.jpg', 32, 32))
        ->assertHasErrors('iconFile');

    expect($group->refresh()->icon)->toBeNull();
});

it('offers no control to a plain member', function () {
    [, $group] = pickemContest();
    $member = User::factory()->create();
    GroupMember::factory()->create(['group_id' => $group->id, 'user_id' => $member->id]);

    Livewire::actingAs($member)->test('group', ['group' => $group])
        ->assertDontSee('Upload a group icon')
        ->assertDontSee('Remove icon');
});

it('offers no control in a public room, which has no commissioner seat', function () {
    $group = Group::factory()->create(['kind' => Group::KIND_LOBBY]);
    $visitor = User::factory()->create();

    Livewire::actingAs($visitor)->test('group', ['group' => $group])
        ->assertDontSee('Upload a group icon');
});

it('wears the uploaded mark on the My Picks card and in the switcher, in place of the mode tile', function () {
    /*
     * The same picture everywhere the group is named (CFB-38): the card,
     * the switcher's menu row and the hero. And the fallbacks are the ones
     * each surface already had — the card keeps the mode tile, the menu row
     * wears initials — never a stand-in image.
     */
    $this->travelTo('2026-09-02 12:00:00');
    [$season, $week] = pickemSeasonWeek();
    pickemGame($season, $week);

    [$commissioner, $group] = pickemContest();
    app(SetGroupIcon::class)->handle($commissioner, $group, UploadedFile::fake()->image('clubhouse.jpg', 256, 256));
    $url = $group->refresh()->iconUrl();

    $html = Livewire::actingAs($commissioner)->test('pickem-home')->html();
    $switcher = (string) str($html)->before('wire:key="picks-view-week"');
    $cards = (string) str($html)->after('wire:key="picks-view-week"');

    expect($switcher)->toContain('data-group-switcher')
        ->toContain('src="'.$url.'"')
        ->and($cards)->toContain('src="'.$url.'"')
        ->not->toContain(ContestMode::Classic->palette()['tile']);

    app(SetGroupIcon::class)->clear($commissioner, $group);

    $html = Livewire::actingAs($commissioner)->test('pickem-home')->html();

    expect($html)->not->toContain('src="'.$url.'"')
        ->and((string) str($html)->before('wire:key="picks-view-week"'))->toMatch('/>\s*'.$group->initials().'\s*</')
        ->and((string) str($html)->after('wire:key="picks-view-week"'))->toContain(ContestMode::Classic->palette()['tile']);
});

/*
 * The kind chip, held HERE because the mark is what moved it. At 390px the
 * hero's title row now carries a mark, a chip and three controls, and the
 * h1 was losing to two characters — so the chip is sm-and-up and the kind
 * moves to the head of the meta line below that. It is still said on both
 * sides at every width, which is the part the rule pins.
 */
it('says the kind on both sides of the pair, at every width', function () {
    $private = Group::factory()->create(['kind' => Group::KIND_PRIVATE]);
    $room = Group::factory()->create(['kind' => Group::KIND_LOBBY]);
    $reader = User::factory()->create();
    GroupMember::factory()->create(['group_id' => $private->id, 'user_id' => $reader->id]);

    $band = fn (Group $group) => Livewire::actingAs($reader)
        ->test('group', ['group' => $group])
        ->html();

    // Twice each: the sm-and-up chip, and the base-width meta lead.
    expect(substr_count($band($private), 'Private'))->toBeGreaterThanOrEqual(2)
        ->and(substr_count($band($room), 'Public'))->toBeGreaterThanOrEqual(2);
});

/*
 * CFB-41's two failure modes, held here so the icon can never again fail
 * SILENTLY: a disk that refuses the write, and a file the rules disagree
 * about.
 */
it('says so on the icon\'s own line when the disk refuses, instead of a 500', function () {
    [$commissioner, $group] = pickemContest();

    // The shape R2 produced for months: the adapter throws (NotImplemented
    // behind `throw => true`). A mock disk, so no fake filesystem answers
    // for it.
    // Livewire's temporary file stores through put(), not putFileAs().
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('put')->once()->andThrow(new RuntimeException('NotImplemented'));
    Storage::set(config('cfb.upload_disk'), $disk);

    Livewire::actingAs($commissioner)->test('group', ['group' => $group])
        ->set('iconFile', UploadedFile::fake()->image('clubhouse.jpg', 256, 256))
        ->assertHasErrors('iconFile')
        ->assertSee(Voice::line('groups.icon.failed', for: $commissioner))
        ->assertSet('iconFile', null);

    expect($group->refresh()->icon)->toBeNull();
});

it('refuses a HEIC by name, with the mime message and nothing else', function () {
    /*
     * An iPhone answers accept="image/*" with a HEIC. Laravel 13's `image`
     * rule accepts it and `dimensions` then cannot read it, so the reader
     * was told the picture was "too small" — a lie they could not fix by
     * cropping. `bail` + the mime rule name the real reason, once.
     */
    $heic = UploadedFile::fake()->createWithContent(
        'photo.heic',
        "\x00\x00\x00\x18ftypheic\x00\x00\x00\x00mif1heic".str_repeat("\x00", 64),
    );

    $errors = Validator::make(['icon' => $heic], ['icon' => ImageUpload::rules()], [
        'icon.mimes' => ImageUpload::mimeMessage(),
        'icon.dimensions' => 'That image is too small to read at icon size.',
    ])->errors()->get('icon');

    expect($errors)->toBe([ImageUpload::mimeMessage()]);

    // And the picker steers before the rule refuses.
    expect(ImageUpload::accept())->toBe('image/jpeg,image/png,image/gif,image/webp')
        ->and(file_get_contents(resource_path('views/components/image-file-input.blade.php')))->not->toContain('accept="image/*"');
});

it('refuses a write the disk answered with false, instead of blanking the mark', function () {
    /*
     * A disk with `throw => false` reports a refused write by RETURNING
     * FALSE, and false written into the column would blank the group's mark
     * while reporting success. The Action refuses it. Note what this does
     * NOT cover, and why R2Writes::harden() forces `throw`: Livewire's
     * TemporaryUploadedFile::storeAs() discards what put() returned and
     * hands back the path it meant to write, so on the path production takes
     * a refusal is invisible to any caller check — the disk has to raise.
     */
    [$commissioner, $group] = pickemContest();

    app(SetGroupIcon::class)->handle($commissioner, $group, UploadedFile::fake()->image('first.jpg', 256, 256));
    $existing = $group->refresh()->icon;

    expect($existing)->not->toBeNull();

    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('putFileAs')->once()->andReturnFalse();
    Storage::set(config('cfb.upload_disk'), $disk);

    expect(fn () => app(SetGroupIcon::class)->handle(
        $commissioner,
        $group,
        UploadedFile::fake()->image('second.jpg', 256, 256),
    ))->toThrow(RuntimeException::class);

    // The mark it had is still the mark it has.
    expect($group->refresh()->icon)->toBe($existing);
});

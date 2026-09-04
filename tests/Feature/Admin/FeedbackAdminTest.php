<?php

use App\Enums\FeedbackKind;
use App\Enums\WorkbookCategory;
use App\Enums\WorkbookSeverity;
use App\Filament\Resources\Feedback\FeedbackResource;
use App\Filament\Resources\Feedback\Pages\ManageFeedback;
use App\Models\Feedback;
use App\Models\User;
use App\Models\WorkbookEvent;
use App\Models\WorkbookItem;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

/*
 * The notes readers sent, as a triage table.
 *
 * Read-only on the note itself — a person wrote it — with two stamps beside
 * it. The one that matters is "file as issue": it goes through the board's
 * human doorway, the admin owns the title, and NOTHING about the reader
 * crosses with it, because open workbook titles reach the advisor.
 */

beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->admin->forceFill(['admin' => true])->save();
});

it('is closed to anyone who is not an admin', function () {
    $reader = User::factory()->create();

    $this->actingAs($reader)->get(FeedbackResource::getUrl())->assertForbidden();
});

it('renders a note with its kind, its page and who sent it', function () {
    $user = User::factory()->create(['first_name' => 'Peyton', 'last_name' => 'Manning']);

    Feedback::factory()->create([
        'user_id' => $user->id,
        'kind' => FeedbackKind::Bug,
        'body' => 'The lock button did nothing.',
        'path' => '/picks',
    ]);

    Livewire::actingAs($this->admin)
        ->test(ManageFeedback::class)
        ->assertOk()
        ->assertSee('Peyton Manning')
        ->assertSee('The lock button did nothing.')
        ->assertSee('/picks')
        ->assertSee('Bug');
});

it('says when the account behind a note is gone, rather than leaving it blank', function () {
    $note = Feedback::factory()->create();
    $note->user()->delete();

    Livewire::actingAs($this->admin)
        ->test(ManageFeedback::class)
        ->assertOk()
        ->assertSee('Account gone');

    expect($note->fresh()->user_id)->toBeNull();
});

it('counts only the notes nobody has looked at on the sidebar badge', function () {
    Feedback::factory()->count(2)->create();
    Feedback::factory()->handled()->create();

    expect(FeedbackResource::getNavigationBadge())->toBe('2');

    Feedback::query()->update(['handled_at' => now()]);

    expect(FeedbackResource::getNavigationBadge())->toBeNull();
});

it('opens on the notes waiting, and flips to the handled ones', function () {
    $waiting = Feedback::factory()->create(['body' => 'Still waiting on somebody.']);
    $handled = Feedback::factory()->handled()->create(['body' => 'Somebody already looked.']);

    Livewire::actingAs($this->admin)
        ->test(ManageFeedback::class)
        ->assertCanSeeTableRecords([$waiting])
        ->assertCanNotSeeTableRecords([$handled])
        ->filterTable('handled_at', true)
        ->assertCanSeeTableRecords([$handled])
        ->assertCanNotSeeTableRecords([$waiting]);
});

it('marks a note handled, and offers nothing more once it is', function () {
    $note = Feedback::factory()->create();

    Livewire::actingAs($this->admin)
        ->test(ManageFeedback::class)
        ->assertActionVisible(TestAction::make('handled')->table($note))
        ->callAction(TestAction::make('handled')->table($note))
        ->assertNotified();

    expect($note->fresh()->handled_at)->not->toBeNull();

    Livewire::actingAs($this->admin)
        ->test(ManageFeedback::class)
        ->filterTable('handled_at', true)
        ->assertActionHidden(TestAction::make('handled')->table($note));
});

it('files a note as an issue through the human doorway, and the sender stays behind', function () {
    $user = User::factory()->create([
        'first_name' => 'Peyton',
        'last_name' => 'Manning',
        'email' => 'peyton@example.com',
        'handle' => 'peyton18',
    ]);

    $note = Feedback::factory()->create([
        'user_id' => $user->id,
        'kind' => FeedbackKind::Bug,
        'body' => "The lock button did nothing.\nTapped it twice on the Tennessee card.",
        'path' => '/picks',
        'release' => 'v4.0.0-beta.11',
        'viewport' => 390,
        'standalone' => true,
    ]);

    // The first line is only a START for the title; the admin writes it.
    Livewire::actingAs($this->admin)
        ->test(ManageFeedback::class)
        ->assertActionVisible(TestAction::make('file')->table($note))
        ->mountAction(TestAction::make('file')->table($note))
        ->assertSchemaStateSet([
            'title' => 'The lock button did nothing.',
            'category' => 'bug',
            'severity' => 'medium',
        ], 'mountedActionSchema0');

    Livewire::actingAs($this->admin)
        ->test(ManageFeedback::class)
        ->callAction(TestAction::make('file')->table($note), [
            'title' => 'Lock button does nothing on the pick card',
            'category' => 'bug',
            'severity' => 'high',
        ])
        ->assertNotified();

    $item = WorkbookItem::query()->sole();

    expect($item->source)->toBe(WorkbookItem::SOURCE_HUMAN)
        ->and($item->key)->toStartWith('human-')
        ->and($item->title)->toBe('Lock button does nothing on the pick card')
        ->and($item->category)->toBe(WorkbookCategory::Bug)
        ->and($item->severity)->toBe(WorkbookSeverity::High)
        ->and($item->body)->toContain('The lock button did nothing.')
        ->toContain('Tapped it twice on the Tennessee card.')
        ->toContain('Kind: Bug')
        ->toContain('Page: /picks')
        ->toContain('Release: v4.0.0-beta.11')
        ->toContain('Viewport: 390px, installed')
        // THE IDENTITY BOUNDARY: the title reaches the advisor, so nothing
        // about the person may ride along with it.
        ->not->toContain('Peyton')
        ->not->toContain('peyton@example.com')
        ->not->toContain('peyton18');

    expect(WorkbookEvent::query()->where('workbook_item_id', $item->id)->where('kind', WorkbookEvent::FILED)->count())->toBe(1);

    $note->refresh();

    expect($note->workbook_item_id)->toBe($item->id)
        ->and($note->handled_at)->not->toBeNull();
});

it('offers no file action for praise, nor for a note that already became a card', function () {
    $praise = Feedback::factory()->create(['kind' => FeedbackKind::Praise]);
    $bug = Feedback::factory()->create(['kind' => FeedbackKind::Bug]);
    $filed = Feedback::factory()->create([
        'kind' => FeedbackKind::Idea,
        'workbook_item_id' => WorkbookItem::factory()->create()->id,
    ]);

    Livewire::actingAs($this->admin)
        ->test(ManageFeedback::class)
        ->assertActionVisible(TestAction::make('file')->table($bug))
        ->assertActionHidden(TestAction::make('file')->table($praise))
        ->assertActionHidden(TestAction::make('file')->table($filed));
});

it('offers no create, no edit and no delete', function () {
    $note = Feedback::factory()->create();

    Livewire::actingAs($this->admin)
        ->test(ManageFeedback::class)
        ->assertActionDoesNotExist('create')
        ->assertActionDoesNotExist(TestAction::make('edit')->table($note))
        ->assertActionDoesNotExist(TestAction::make('delete')->table($note));
});

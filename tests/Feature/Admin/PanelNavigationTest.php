<?php

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Support\Enums\Width;

/*
 * The panel's navigation shell: a compact grouped rail, and — more load-bearing
 * than it looks — a way OUT. The installed PWA strips every piece of browser
 * chrome, so without its own exit /admin is a dead end an admin can only leave
 * by relaunching the app. The product's "every dead end keeps a built-in exit"
 * rule, applied to the panel.
 */

beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->admin->forceFill(['admin' => true])->save();
});

it('renders the back-to-app chip on every panel page', function () {
    // The chip rides TOPBAR_START, which renders on phone AND desktop. A plain
    // anchor to route('home') — never wire:navigate, which would drag the
    // panel's Livewire across the Flux front end's asset bundle.
    $this->actingAs($this->admin)->get('/admin')
        ->assertOk()
        ->assertSee('Back to app')
        ->assertSee(route('home'));
});

it('offers the exit from the user menu as a second door', function () {
    // Authed, because Filament resolves its own default profile item into this
    // list and that item reads the current user's name.
    $this->actingAs($this->admin);

    $labels = collect(Filament::getPanel('admin')->getUserMenuItems())
        ->map(fn ($item): string => $item->getLabel());

    expect($labels)->toContain('Back to app');
});

it('registers the groups in taxonomy order', function () {
    $groups = collect(Filament::getPanel('admin')->getNavigationGroups())
        ->map(fn (NavigationGroup $group): string => $group->getLabel())
        ->values()
        ->all();

    expect($groups)->toBe([
        "Pick'em",
        'Community',
        'College Football',
        'Content',
        'Work',
        'Configuration',
        'Operations',
    ]);
});

it('gives every group an icon, because the collapsed rail renders them as triggers', function () {
    foreach (Filament::getPanel('admin')->getNavigationGroups() as $group) {
        expect($group->getIcon())->not->toBeNull();
    }
});

it('collapses to an icon rail at a compact width', function () {
    $panel = Filament::getPanel('admin');

    expect($panel->isSidebarCollapsibleOnDesktop())->toBeTrue()
        ->and($panel->getSidebarWidth())->toBe('15rem')
        ->and($panel->getCollapsedSidebarWidth())->toBe('3.5rem')
        ->and($panel->getMaxContentWidth())->toBe(Width::Full);
});

it('adopts Pulse into Operations, in a new tab', function () {
    // Pulse was an orphan admin surface with no door anywhere in the product.
    // New tab, because it is the OTHER asset bundle.
    $pulse = collect(Filament::getPanel('admin')->getNavigationItems())
        ->sole(fn ($item): bool => $item->getLabel() === 'Pulse');

    expect($pulse->getUrl())->toBe('/pulse')
        ->and($pulse->shouldOpenUrlInNewTab())->toBeTrue()
        ->and($pulse->getGroup())->toBe('Operations');
});

it('still keeps non-admins out', function () {
    $this->actingAs(User::factory()->create())
        ->get('/admin')
        ->assertForbidden();
});

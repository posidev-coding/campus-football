<?php

use App\Actions\MakePick;
use App\Actions\PublishSlate;
use App\Models\GroupMember;
use App\Models\User;
use Livewire\Livewire;
use Symfony\Component\Finder\Finder;

/*
 * THE A11Y BATCH's pins: focus that survives a team-colored fill, state
 * that screen readers can hear, and motion that honors the OS setting.
 */

/** @return array<string, string> */
function a11yViews(): array
{
    $views = [];

    foreach (Finder::create()->files()->in(resource_path('views'))->exclude('filament')->name('*.blade.php') as $file) {
        $views[str_replace(resource_path('views').'/', '', $file->getPathname())] = $file->getContents();
    }

    return $views;
}

describe('reduced motion', function () {
    it('carries the one global reduce block, and the focus ring beside it', function () {
        $css = file_get_contents(resource_path('css/app.css'));

        expect($css)->toContain('@media (prefers-reduced-motion: reduce)')
            ->and($css)->toContain('scroll-behavior: auto !important')
            ->and($css)->toContain('.focus-ring:focus-visible');
    });

    it('never ships a bare scroll-smooth — motion-safe or nothing', function () {
        $violations = [];

        foreach (a11yViews() as $path => $contents) {
            if (preg_match('/(?<!motion-safe:)\bscroll-smooth/', $contents)) {
                $violations[] = $path;
            }
        }

        expect($violations)->toBe([], implode(', ', $violations)
            .' — smooth scrolling must be motion-safe: gated.');
    });
});

describe('the pick sides', function () {
    it('reads as a one-of-two choice: aria-pressed on BOTH sides, focus in currentColor', function () {
        /*
         * Only the picked side carried aria-pressed, so a screen reader
         * heard two unrelated buttons — and the UA's blue focus ring
         * vanished against a team-colored fill, which is what .focus-ring's
         * currentColor outline fixes.
         */
        $this->travelTo('2026-09-02 12:00:00');

        [$commissioner, $group, $contest] = pickemContest();
        $slate = pickemDraftSlate($contest);
        app(PublishSlate::class)->handle($commissioner, $slate);

        $member = User::factory()->create(['handle' => 'a11y', 'admin' => true]);
        GroupMember::factory()->create(['group_id' => $group->id, 'user_id' => $member->id]);

        $slateGame = $slate->fresh()->games()->with('game')->first();
        app(MakePick::class)->handle($member, $slateGame, $slateGame->game->home_team_id);

        $html = Livewire::actingAs($member)->test('group', ['group' => $group])->html();

        expect(substr_count($html, 'aria-pressed="true"'))->toBeGreaterThanOrEqual(1)
            ->and(substr_count($html, 'aria-pressed="false"'))->toBeGreaterThanOrEqual(19)
            ->and($html)->toContain('focus-ring');
    });
});

describe('spoken state', function () {
    it('says You on the viewer standings row, out loud', function () {
        $source = file_get_contents(resource_path('views/components/standings-table.blade.php'));

        expect($source)->toContain('<span class="sr-only">You — </span>');
    });

    it('gives the possession dot words', function () {
        $source = file_get_contents(resource_path('views/livewire/game.blade.php'));

        expect($source)->toContain('<span class="sr-only">has possession</span>');
    });

    it('renders disclosures with server-side aria-expanded and aria-controls', function () {
        $source = file_get_contents(resource_path('views/components/mode-rules.blade.php'));

        expect($source)->toContain('aria-expanded="{{ $open ? \'true\' : \'false\' }}"')
            ->and($source)->toContain('aria-controls="mode-rules-{{ $mode->value }}"');
    });
});

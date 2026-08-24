<?php

use App\Models\User;
use Filament\Facades\Filament;

/*
 * The panel's own compiled Tailwind.
 *
 * Before this existed, a Tailwind class written in an admin view had no
 * definition behind it and silently did nothing — Filament's shipped
 * stylesheet carries only the utilities its own components use. The first Sync
 * Health page laid itself out with `grid grid-cols-2 gap-4` and rendered as one
 * unaligned column, which reads as bad design rather than a missing stylesheet.
 *
 * That is why every admin surface until now was built from Filament's own
 * widgets and tables, and it is the prerequisite the Workbook board needed.
 */

it('registers a compiled theme for the panel', function () {
    expect(Filament::getPanel('admin')->getViteTheme())
        ->toBe('resources/css/filament/admin/theme.css');
});

it('scans both trees an admin view can live in', function () {
    // The @source lines ARE the feature. A custom admin view written outside
    // these two trees compiles to no CSS at all and renders unstyled — the
    // exact failure the theme exists to end, arriving silently.
    $theme = file_get_contents(resource_path('css/filament/admin/theme.css'));

    expect($theme)
        ->toContain("@source '../../../../app/Filament/**/*'")
        ->toContain("@source '../../../../resources/views/filament/**/*'");
});

it('is in the Vite input array, or it is never compiled', function () {
    // Registered on the panel but absent from the build is the worst of both:
    // the panel asks Vite for a file the manifest does not have, and every
    // admin page 500s.
    expect(file_get_contents(base_path('vite.config.js')))
        ->toContain('resources/css/filament/admin/theme.css');
});

it('keeps the product stylesheet out of the panel', function () {
    // app.css carries Flux's bundle, the brand variables and the phone-first
    // chrome. None of it belongs to an admin table, and the separation is what
    // lets the two evolve without a shared regression surface.
    // Comments stripped: the file explains this rule in prose, and the
    // assertion is about what it actually imports.
    $directives = preg_replace('#/\*.*?\*/#s', '', file_get_contents(resource_path('css/filament/admin/theme.css')));

    expect($directives)->not->toContain('app.css')
        ->and(file_get_contents(app_path('Providers/Filament/AdminPanelProvider.php')))
        ->not->toContain('->viteTheme(\'resources/css/app.css');
});

it('serves the compiled theme on a real admin page', function () {
    // The behavioral half: Filament resolves the Vite theme into the layout.
    // This one needs `npm run build` to have run — the same requirement two
    // existing admin page tests already carry.
    $admin = User::factory()->create();
    $admin->forceFill(['admin' => true])->save();

    $this->actingAs($admin)->get('/admin')
        ->assertOk()
        ->assertSee('build/assets/theme-', false);
});

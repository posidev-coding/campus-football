<?php

namespace App\Models;

use App\Jobs\RenderBrandSplashes;
use App\Support\Brand;
use Illuminate\Database\Eloquent\Model;

/**
 * The single row of brand overrides behind App\Support\Brand.
 *
 * Nothing reads this model directly except the admin page that edits it and
 * the resolver that caches it — every renderer talks to Brand, so the front
 * end, the manifest, the favicon and the Filament panel's own logo cannot
 * disagree about what the brand is.
 *
 * @property ?string $name
 * @property ?string $short_name
 * @property ?string $tagline
 * @property ?string $wordmark_lead
 * @property ?string $wordmark_main
 * @property ?string $color_ink
 * @property ?string $color_cream
 * @property ?string $color_lager
 * @property ?array<string, string> $assets
 */
class BrandSetting extends Model
{
    protected $guarded = [];

    /**
     * The one row, created on first read.
     *
     * A blank row is the same thing as no row — every column is nullable and
     * null resolves to the shipped default — so creating it eagerly costs
     * nothing and saves the admin page a null check on every field.
     */
    public static function current(): self
    {
        return static::query()->firstOrCreate([]);
    }

    protected static function booted(): void
    {
        /* Brand memoizes in a static property on top of the cache, so an edit
           that only cleared the cache would still serve the old brand for the
           rest of the request that made it — including the redirect the admin
           page lands on, which is precisely where the change is looked for. */
        static::saved(function (BrandSetting $setting): void {
            Brand::flush();

            if ($setting->changedSplashInputs()) {
                RenderBrandSplashes::dispatch();
            }
        });
        static::deleted(fn () => Brand::flush());
    }

    /**
     * Did this save change what a launch screen is drawn from: the ink, or
     * the icon it centers?
     *
     * Nothing else. A tagline, a favicon or an og card changes none of the
     * fourteen images, and re-rendering them for it is the waste CFB-88 took
     * off the request path.
     */
    public function changedSplashInputs(): bool
    {
        if ($this->wasChanged('color_ink')) {
            return true;
        }

        if (! $this->wasChanged('assets')) {
            return false;
        }

        $before = json_decode((string) ($this->getPrevious()['assets'] ?? ''), true);

        return ($before['icon-512'] ?? null) !== ($this->assets['icon-512'] ?? null);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'assets' => 'array',
        ];
    }
}

<?php

namespace App\Models;

use App\Enums\ViewportBucket;
use Database\Factories\PageViewDailyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One day's attention on one route, in one cell — the persisted rollup, and
 * never the view itself. See the migration for the cell's dimensions and for
 * why `facet` defaults to an empty string rather than null.
 *
 * `visitors` is NON-ADDITIVE. It is a distinct count computed inside this one
 * cell, so summing it across viewports, audiences or days counts the same
 * person once per cell they appear in. Views and navigate_views add; visitors
 * has to be recomputed from `activity_events` for any wider window, which is
 * the other reason the raw table keeps thirty days.
 */
#[Fillable([
    'day', 'route', 'facet', 'audience', 'viewport_bucket', 'installed',
    'views', 'visitors', 'navigate_views',
])]
class PageViewDaily extends Model
{
    /** @use HasFactory<PageViewDailyFactory> */
    use HasFactory;

    /** 0 unknown, 1 browser, 2 standalone. "Not reported" is its own state. */
    public const UNKNOWN = 0;

    public const BROWSER = 1;

    public const STANDALONE = 2;

    /** The pluralizer would say `page_view_dailies`; the table is a day grain. */
    protected $table = 'page_views_daily';

    protected function casts(): array
    {
        return [
            'day' => 'date',
            'audience' => 'integer',
            'viewport_bucket' => ViewportBucket::class,
            'installed' => 'integer',
            'views' => 'integer',
            'visitors' => 'integer',
            'navigate_views' => 'integer',
        ];
    }
}

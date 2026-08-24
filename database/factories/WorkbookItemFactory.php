<?php

namespace Database\Factories;

use App\Enums\WorkbookCategory;
use App\Enums\WorkbookSeverity;
use App\Enums\WorkbookStatus;
use App\Models\WorkbookItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WorkbookItem> */
class WorkbookItemFactory extends Factory
{
    protected $model = WorkbookItem::class;

    public function definition(): array
    {
        return [
            // `key` is unique in the schema, so the draws must be too — the
            // SeasonFactory lesson: a collision here fails one run in N and
            // passes under --filter.
            'key' => 'item-'.fake()->unique()->numberBetween(1, 999_999),
            'title' => 'The picks screen N+1s on slate.games.team',
            'body' => 'Measured over the last week of slow queries.',
            'category' => WorkbookCategory::Performance,
            'severity' => WorkbookSeverity::High,
            'status' => WorkbookStatus::Inbox,
            'evidence' => ['hits' => 214, 'worst_ms' => 2_400],
            'prompt' => 'Add the missing eager load to pickem-home and prove it with a query-count test.',
            'source' => WorkbookItem::SOURCE_ADVISOR,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ];
    }

    public function dismissed(): static
    {
        return $this->state(fn (): array => ['status' => WorkbookStatus::Dismissed]);
    }
}

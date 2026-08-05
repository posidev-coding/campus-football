<?php

namespace Database\Factories;

use App\Models\Season;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Season> */
class SeasonFactory extends Factory
{
    protected $model = Season::class;

    public function definition(): array
    {
        return [
            'year' => fake()->numberBetween(2015, 2026),
            'type' => Season::REGULAR,
            'is_current' => false,
        ];
    }

    /**
     * Fill the name and the date range from the season's FINAL year and type.
     *
     * These used to be computed in `definition()` from the random year, which
     * meant `Season::factory()->create(['year' => 2025])` kept whatever year
     * the faker had picked in its DATES. A 2025 row carrying 2026 dates is not
     * a cosmetic mismatch: `CfbCalendar` resolves the current season from date
     * ranges, never from the `year` column, so such a row becomes "the season
     * we are heading into" and drags `scoreboardYear()` back a year.
     *
     * That is exactly how it failed — Home's featured games served last
     * season's bowls about one run in twelve, whenever faker rolled the year
     * whose August was nearest. `numberBetween(2015, 2026)` is a 1-in-12
     * chance, which is frequent enough to notice and rare enough to blame on
     * anything else. Deriving here, after overrides are applied, makes the
     * disagreement unrepresentable.
     *
     * Anything the caller pins is left alone, so a test can still build a
     * deliberately odd season.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (Season $season): void {
            $year = (int) $season->year;

            // ESPN's own shapes, verified live: the postseason runs past New
            // Year into the following calendar year, and the offseason is the
            // short bridge after it.
            [$name, $start, $end] = match ((int) $season->type) {
                Season::PRESEASON => ['Preseason', "{$year}-02-01", "{$year}-08-23"],
                Season::POSTSEASON => ['Postseason', "{$year}-12-13", ($year + 1).'-01-21'],
                Season::OFFSEASON => ['Off Season', ($year + 1).'-01-21', ($year + 1).'-02-01'],
                default => ['Regular Season', "{$year}-08-23", "{$year}-12-13"],
            };

            $season->name ??= "{$year} {$name}";
            $season->start_date ??= $start;
            $season->end_date ??= $end;
        });
    }
}

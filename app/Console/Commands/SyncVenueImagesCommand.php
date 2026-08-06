<?php

namespace App\Console\Commands;

use App\Console\Concerns\TracksFeedRun;
use App\Models\Venue;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Stadium photos for the game screen's information card.
 *
 * ESPN has these on its CDN but hands them to no feed a pregame screen can
 * reach: `gameInfo.venue.images` lives in the summary payload, and an
 * unplayed game has no summary. The URL is not derivable either — measured
 * across six venues, three answer only under `day/interior`, one only under
 * `day`, two under both, and one has no photo at all.
 *
 * So each venue is probed once with a HEAD against the CDN — not the API, so
 * not against the 240/min ceiling — and only a 200 is stored. `image_checked_at`
 * records that we ASKED, which is what stops a venue with no photo being
 * re-probed on every run. There are 242 venues in total, so a full pass is
 * trivial and a re-run is nearly free.
 */
#[Signature('cfb:venues {--force : Re-probe venues already checked}')]
#[Description('Probe ESPN\'s CDN for stadium photos, storing only what answers 200')]
class SyncVenueImagesCommand extends Command
{
    use TracksFeedRun;

    /** Both live patterns, most common first. */
    private const PATTERNS = [
        'https://a.espncdn.com/i/venues/college-football/day/interior/%d.jpg',
        'https://a.espncdn.com/i/venues/college-football/day/%d.jpg',
    ];

    public function handle(): int
    {
        $venues = Venue::query()
            ->when(! $this->option('force'), fn ($q) => $q->whereNull('image_checked_at'))
            ->orderBy('id')
            ->get(['id', 'name']);

        if ($venues->isEmpty()) {
            $this->info('Every venue has been checked.');

            return self::SUCCESS;
        }

        $found = $this->trackRun('venues:images', null, function () use ($venues): int {
            $found = 0;

            foreach ($venues as $venue) {
                if ($this->probe($venue)) {
                    $found++;
                }
            }

            return $found;
        });

        $this->line(sprintf(
            '  <fg=green>✓</> %d of %d venues have a photo',
            $found,
            $venues->count(),
        ));

        return self::SUCCESS;
    }

    private function probe(Venue $venue): bool
    {
        foreach (self::PATTERNS as $pattern) {
            $url = sprintf($pattern, $venue->id);

            try {
                if (Http::timeout(5)->head($url)->successful()) {
                    $venue->update(['image_url' => $url, 'image_checked_at' => now()]);

                    return true;
                }
            } catch (\Throwable) {
                // A CDN hiccup is not worth failing the pass over, and leaving
                // image_checked_at null is what makes the next run try again.
                return false;
            }
        }

        // Asked, and there is genuinely nothing — recorded so we stop asking.
        $venue->update(['image_checked_at' => now()]);

        return false;
    }
}

<?php

namespace App\Services\Nil;

use App\Models\Team;
use App\Services\Espn\EspnClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * NIL news by keyword, filtered out of the team news feed.
 *
 * This is the honest answer to a gap, not a workaround pretending to be a
 * feature: ESPN publishes no NIL data, so the best available signal is its own
 * reporting. The team news feed is one request and already useful on the team
 * page, so filtering it costs nothing extra.
 *
 * What this deliberately does NOT do is invent valuations. If real numbers
 * matter later, a paid provider implements the same interface and this class
 * is simply no longer bound.
 */
class KeywordNilNewsProvider implements NilNewsProvider
{
    /**
     * Matched case-insensitively against headline and description.
     *
     * "NIL" is matched as a whole word only — otherwise it hits "Nil" inside
     * ordinary words and, more annoyingly, surnames.
     */
    private const KEYWORDS = [
        'collective', 'revenue sharing', 'revenue-sharing',
        'name, image', 'endorsement', 'valuation',
        'transfer portal', 'buyout', 'compensation',
    ];

    public function __construct(private EspnClient $espn) {}

    public function forTeam(Team $team, int $limit = 10): Collection
    {
        $body = $this->espn->site('news', [
            'limit' => 50,
            'team' => $team->id,
        ], ttl: config('espn.cache.schedule'));

        if ($body === null || empty($body['articles'])) {
            return collect();
        }

        return collect($body['articles'])
            ->filter(fn (array $article) => $this->mentionsNil($article))
            ->map(fn (array $article) => [
                'headline' => $article['headline'] ?? '',
                'description' => $article['description'] ?? null,
                'url' => $article['links']['web']['href'] ?? null,
                'published' => $article['published'] ?? null,
            ])
            ->take($limit)
            ->values();
    }

    private function mentionsNil(array $article): bool
    {
        $haystack = Str::lower(($article['headline'] ?? '').' '.($article['description'] ?? ''));

        if (preg_match('/\bNIL\b/i', ($article['headline'] ?? '').' '.($article['description'] ?? ''))) {
            return true;
        }

        foreach (self::KEYWORDS as $keyword) {
            if (str_contains($haystack, $keyword)) {
                return true;
            }
        }

        return false;
    }
}

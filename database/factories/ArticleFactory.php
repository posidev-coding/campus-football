<?php

namespace Database\Factories;

use App\Models\Article;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Article> */
class ArticleFactory extends Factory
{
    protected $model = Article::class;

    public function definition(): array
    {
        $espnId = fake()->unique()->numberBetween(40_000_000, 49_999_999);

        return [
            'espn_id' => $espnId,
            'headline' => rtrim(fake()->sentence(6), '.'),
            'description' => fake()->sentence(14),
            'byline' => fake()->name(),
            'type' => 'HeadlineNews',
            'image_url' => 'https://a.espncdn.com/photo/'.$espnId.'.jpg',
            'url' => 'https://www.espn.com/college-football/story/_/id/'.$espnId,
            'premium' => false,
            /*
             * Anchored to now rather than left to a floating faker window. The
             * news feed is ordered by this column, so a random date reshuffles
             * a fixture's feed between runs — the same trap GameFactory's
             * random kickoff already sprang once.
             */
            'published_at' => now()->subHours(fake()->numberBetween(1, 100)),
        ];
    }

    /**
     * A video or photo post. ESPN publishes no story for these at all, so they
     * are the case where the app has to keep linking out.
     */
    public function media(): static
    {
        return $this->state(['type' => Article::MEDIA]);
    }

    /**
     * An article whose body we already hold, so no fetch should happen.
     *
     * @param  list<array<string, mixed>>  $images
     */
    public function withStory(string $story = '<p>The Vols won.</p>', array $images = []): static
    {
        return $this->state([
            'story' => $story,
            'story_images' => $images,
            'story_fetched_at' => now(),
        ]);
    }
}

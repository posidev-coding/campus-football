<?php

use App\Jobs\FetchArticleStory;
use App\Models\Article;
use App\Models\Team;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    config()->set('espn.http.rate_limit', 0);
});

it('renders the body in the app rather than sending the reader to ESPN', function () {
    Http::fake();

    $article = Article::factory()
        ->withStory('<p>Cristobal agreed to an extension.</p>')
        ->create(['headline' => 'Cristobal, Miami agree on extension']);

    $this->get(route('article', $article))
        ->assertOk()
        ->assertSee('Cristobal, Miami agree on extension')
        ->assertSee('Cristobal agreed to an extension.', escape: false)
        // Attribution is not optional: the words are ESPN's.
        ->assertSee('Read it on ESPN');

    // Already stored, so the page is a pure database read.
    Http::assertNothingSent();
});

it('fills a body it does not have yet in behind the page, once', function () {
    /*
     * Used to be fetched inline in mount() — and when ESPN's `now` host sat
     * at its 5s ceiling, the page waited out nearly all of it (CFB-96). Now
     * the render is a database read, `wire:init` queues the fetch, and the
     * body arrives on the next poll. Still one request per article, ever.
     */
    Http::fake(['*now.core.api.espn.com*' => Http::response([
        'headlines' => [['story' => '<p>Fetched behind the first view.</p>', 'images' => []]],
    ])]);

    $article = Article::factory()->create();

    $this->get(route('article', $article))
        ->assertOk()
        ->assertDontSee('Fetched behind the first view.', escape: false);

    Http::assertNothingSent();

    // The browser's `wire:init`. The test queue is sync, so the job runs here.
    Livewire::test('article', ['article' => $article])->call('requestStory');

    $this->get(route('article', $article))
        ->assertOk()
        ->assertSee('Fetched behind the first view.', escape: false);

    // A second reader finds it stored and asks for nothing.
    Livewire::test('article', ['article' => $article->fresh()])->call('requestStory');

    Http::assertSentCount(1);
});

describe('a body that is not here yet', function () {
    it('renders without waiting on ESPN, and asks it nothing on the render', function () {
        // ESPN at its worst. The page must not notice, because it never asks.
        Http::fake(['*now.core.api.espn.com*' => Http::response(null, 500)]);

        $article = Article::factory()->create(['description' => 'Heupel on the bye week.']);

        $this->get(route('article', $article))
            ->assertOk()
            ->assertSee('data-story="pending"', escape: false)
            ->assertSee('wire:init="requestStory"', escape: false)
            ->assertSee('Heupel on the bye week.')
            ->assertSee($article->url, escape: false)
            // Not asked is not "no body" — the page must not claim a finding.
            ->assertDontSee('has not published a readable body');

        Http::assertNothingSent();
    });

    it('queues one fetch for a page full of readers', function () {
        Queue::fake();

        $article = Article::factory()->create();

        foreach (range(1, 5) as $reader) {
            Livewire::test('article', ['article' => $article])->call('requestStory');
        }

        Queue::assertPushed(FetchArticleStory::class, 1);
    });

    it('queues nothing for a story it already holds, or a video with none to find', function () {
        Queue::fake();
        Http::fake();

        $stored = Article::factory()->withStory('<p>Already here.</p>')->create();
        $media = Article::factory()->media()->create();

        foreach ([$stored, $media] as $article) {
            $this->get(route('article', $article))
                ->assertOk()
                ->assertDontSee('data-story="pending"', escape: false);

            Livewire::test('article', ['article' => $article])->call('requestStory');
        }

        Queue::assertNothingPushed();
        Http::assertNothingSent();
    });

    it('stops waiting on a fetch that failed, and does not call it a missing body', function () {
        /*
         * A failed request writes neither `story` nor `story_fetched_at`, so
         * "it landed" can never arrive. Past the ceiling the reader gets the
         * link and an honest line, and the poll stops.
         */
        Http::fake(['*now.core.api.espn.com*' => Http::response(null, 500)]);

        $article = Article::factory()->create();

        $page = Livewire::test('article', ['article' => $article])->call('requestStory');

        expect($article->fresh()->story_fetched_at)->toBeNull();

        $page->assertSee('data-story="pending"', escape: false);

        $this->travel(31)->seconds();

        $page->call('$refresh')
            ->assertDontSee('data-story="pending"', escape: false)
            ->assertDontSee('wire:poll', escape: false)
            ->assertSee('The story has not come through from ESPN yet.')
            ->assertDontSee('has not published a readable body');
    });
});

it('says what a video post is instead of showing an empty page', function () {
    Http::fake();

    $media = Article::factory()->media()->create();

    $this->get(route('article', $media))
        ->assertOk()
        ->assertSee('video on ESPN')
        ->assertSee('Open on ESPN')
        ->assertSee($media->url);

    // A Media post has no body to find, so the page must not go asking.
    Http::assertNothingSent();
});

it('keeps Home lit while reading, the way a game keeps Scores lit', function () {
    Http::fake();

    $article = Article::factory()->withStory()->create();

    $this->get(route('article', $article))
        ->assertOk()
        ->assertSee('aria-current="page"', escape: false);
});

describe('the article card', function () {
    it('links inward when there is something to read', function () {
        Http::fake();

        $article = Article::factory()->withStory()->create();

        $this->get(route('news'))
            ->assertOk()
            ->assertSee(route('article', $article), escape: false);
    });

    it('still links out for a video post', function () {
        Http::fake();

        $media = Article::factory()->media()->create();

        $this->get(route('news'))
            ->assertOk()
            ->assertSee($media->url, escape: false)
            ->assertDontSee(route('article', $media), escape: false);
    });

    it('links inward before the first fetch, because asking per card is 50 requests', function () {
        // Optimistic on purpose: knowing for certain would mean one request per
        // CARD. The article page absorbs the rare miss.
        Http::fake();

        $fresh = Article::factory()->create();

        expect($fresh->isReadable())->toBeTrue();

        $this->get(route('news'))
            ->assertOk()
            ->assertSee(route('article', $fresh), escape: false);

        Http::assertNothingSent();
    });
});

it('does not lazy-load team chips on the article page', function () {
    /*
     * Lazy loading is disabled app-wide, so a missing eager load is a 500
     * rather than an N+1. The fixture must actually ATTACH a team, or the
     * render path being tested is never reached.
     */
    Http::fake();

    $team = Team::factory()->create(['abbreviation' => 'TENN']);
    $article = Article::factory()->withStory()->create();
    $article->teams()->attach($team);

    $this->get(route('article', $article))
        ->assertOk()
        ->assertSee('TENN');
});

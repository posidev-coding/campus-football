<?php

use App\Models\Article;
use App\Models\Team;
use Illuminate\Support\Facades\Http;

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

it('fetches a body it does not have yet, once', function () {
    Http::fake(['*now.core.api.espn.com*' => Http::response([
        'headlines' => [['story' => '<p>Fetched on first view.</p>', 'images' => []]],
    ])]);

    $article = Article::factory()->create();

    $this->get(route('article', $article))
        ->assertOk()
        ->assertSee('Fetched on first view.', escape: false);

    expect($article->fresh()->story)->not->toBeNull();

    $this->get(route('article', $article))->assertOk();

    Http::assertSentCount(1);
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

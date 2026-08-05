<?php

use App\Models\Article;
use App\Models\Athlete;
use App\Models\Team;
use App\Services\Espn\Sync\SyncArticleStory;
use App\Support\ArticleStory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('espn.http.rate_limit', 0);
});

/**
 * The shape ESPN actually returns, verified live: an envelope with a
 * `headlines` array whose first entry carries the story and its images.
 */
function fakeStory(string $story, array $images = []): void
{
    Http::fake(['*now.core.api.espn.com*' => Http::response([
        'headlines' => [[
            'id' => 49525737,
            'story' => $story,
            'images' => $images,
        ]],
    ])]);
}

describe('fetching a body', function () {
    it('stores the story and its images, and never asks twice', function () {
        fakeStory('<p>Cristobal signed.</p>', [
            ['url' => 'https://a.espncdn.com/lead.jpg', 'caption' => 'Lead', 'credit' => 'AP'],
        ]);

        $article = Article::factory()->create();

        expect(app(SyncArticleStory::class)->fill($article))->toBeTrue();

        $article->refresh();

        expect($article->story)->toBe('<p>Cristobal signed.</p>')
            ->and($article->story_images)->toHaveCount(1)
            ->and($article->story_images[0]['credit'])->toBe('AP')
            ->and($article->story_fetched_at)->not->toBeNull();

        // A published story cannot change, so a second view is a pure read.
        app(SyncArticleStory::class)->fill($article->fresh());

        Http::assertSentCount(1);
    });

    it('never asks for a video post, which has no body to find', function () {
        Http::fake();

        $media = Article::factory()->media()->create();

        expect(app(SyncArticleStory::class)->fill($media))->toBeFalse();

        Http::assertNothingSent();
    });

    it('records an empty answer so it does not ask again forever', function () {
        /*
         * A third of articles genuinely have no body. If "story is null" meant
         * "not fetched yet", every view of every one of them would be a
         * request — which is the failure this column exists to prevent.
         */
        fakeStory('');

        $article = Article::factory()->create();

        expect(app(SyncArticleStory::class)->fill($article))->toBeFalse();

        $article->refresh();

        expect($article->story)->toBeNull()
            ->and($article->story_fetched_at)->not->toBeNull()
            ->and($article->storyIsWorthFetching())->toBeFalse();

        Cache::flush();
        app(SyncArticleStory::class)->fill($article);

        Http::assertSentCount(1);
    });

    it('does not record a failed request as "no body"', function () {
        // Never write a default when a feed returns nothing. A transient 500
        // must not permanently demote an article to a link.
        Http::fake(['*now.core.api.espn.com*' => Http::response(null, 500)]);

        $article = Article::factory()->create();

        expect(app(SyncArticleStory::class)->fill($article))->toBeFalse();

        $article->refresh();

        expect($article->story_fetched_at)->toBeNull()
            ->and($article->storyIsWorthFetching())->toBeTrue();
    });

    it('throttles on the ARTICLE, not the viewer', function () {
        // A hundred people opening the same story is one request. The lock is
        // never released, only allowed to expire.
        fakeStory('');

        $article = Article::factory()->create();

        app(SyncArticleStory::class)->fill($article);

        $second = Article::factory()->make(['espn_id' => $article->espn_id]);
        $second->exists = false;

        expect(Cache::lock("espn:story:{$article->espn_id}", 60)->get())->toBeFalse();
    });
});

describe('rendering it', function () {
    it('refuses script, event handlers and javascript: urls', function () {
        /*
         * This is third-party HTML rendered UNESCAPED, which is the exact shape
         * of a stored XSS. The allowlist denies by default, so a tag ESPN adds
         * next season is dropped rather than trusted.
         */
        $html = ArticleStory::render(<<<'HTML'
            <p>Safe</p>
            <script>alert(1)</script>
            <p onclick="alert(2)" class="x">Attributes go</p>
            <p><a href="javascript:alert(3)">bad</a></p>
            <p><a href="//evil.test/x">scheme relative</a></p>
            <p><img src="javascript:alert(4)" onerror="alert(5)"></p>
            <iframe src="https://evil.test"></iframe>
            HTML);

        expect($html)
            ->not->toContain('<script')
            ->not->toContain('onclick')
            ->not->toContain('onerror')
            ->not->toContain('javascript:')
            ->not->toContain('<iframe')
            ->not->toContain('class="x"')
            // Unwrapped, not deleted: an unsafe link loses its href, never its
            // sentence.
            ->toContain('bad')
            ->toContain('scheme relative')
            ->toContain('<p>Safe</p>');
    });

    it('keeps the text inside a tag it does not recognize', function () {
        // Unwrap rather than delete, or one unexpected wrapper eats a
        // paragraph of the article silently.
        expect(ArticleStory::render('<p><marquee>Still here</marquee></p>'))
            ->toContain('Still here')
            ->not->toContain('marquee');
    });

    it('sends ESPN team and player links to our own pages', function () {
        $team = Team::factory()->create(['id' => 2390, 'slug' => 'miami-hurricanes']);
        Athlete::create(['id' => 4688380, 'display_name' => 'Cam Ward']);

        $html = ArticleStory::render(<<<'HTML'
            <p><a href="https://www.espn.com/college-football/team/_/id/2390/miami-hurricanes">Miami</a>
            beat <a href="https://www.espn.com/college-football/player/_/id/4688380/cam-ward">Cam Ward</a>
            and <a href="https://www.espn.com/college-football/team/_/id/99999/nobody">a team we do not know</a>.</p>
            HTML);

        expect($html)
            ->toContain('href="'.route('team', $team).'"')
            ->toContain('href="'.route('player', 4688380).'"')
            // An id we do not hold stays external rather than 404ing inward.
            ->toContain('id/99999/nobody')
            ->toContain('rel="noopener noreferrer nofollow"');
    });

    it('forces every outbound link into a new tab', function () {
        expect(ArticleStory::render('<p><a href="https://espn.com/x" target="_self">out</a></p>'))
            ->toContain('target="_blank"')
            ->not->toContain('_self');
    });

    it('resolves a photo placeholder against the story images', function () {
        /*
         * `<photo1>` means images[1] — index 0 is the lead image the page
         * renders itself. Verified against three live articles carrying one to
         * three placeholders.
         */
        $html = ArticleStory::render(
            '<p>Before</p><p><photo1></p><p>After</p>',
            [
                ['url' => 'https://a.espncdn.com/lead.jpg', 'caption' => 'Lead', 'credit' => null],
                ['url' => 'https://a.espncdn.com/inline.jpg', 'caption' => 'Lanning', 'credit' => 'Icon'],
            ]
        );

        expect($html)
            ->toContain('<figure>')
            ->toContain('https://a.espncdn.com/inline.jpg')
            ->toContain('Lanning')
            ->toContain('Icon')
            ->not->toContain('lead.jpg');
    });

    it('drops a placeholder it cannot resolve rather than printing it', function () {
        expect(ArticleStory::render('<p>Text</p><p><photo9></p>'))
            ->toBe('<p>Text</p>');
    });

    it('removes ESPN cross-promotion and the empty paragraph it leaves', function () {
        /*
         * `alsosee` and `inlineN` are promos back to espn.com — precisely what
         * an in-app reader is here to avoid. Removing the tag alone leaves a
         * blank gap in the prose; one live roundup had seven of them.
         */
        $html = ArticleStory::render(
            '<p>One</p><p><alsosee></p><p><inline1></p><p><a name="sec"></a></p><p>Two</p>'
        );

        expect($html)->toBe('<p>One</p><p>Two</p>');
    });

    it('renders nothing at all for an article with no body', function () {
        expect(ArticleStory::render(null))->toBe('')
            ->and(ArticleStory::render('   '))->toBe('');
    });

    it('does not mangle UTF-8', function () {
        // libxml reads bytes as Latin-1 without an explicit charset, which
        // turns every curly quote and accented name into mojibake.
        expect(ArticleStory::render('<p>Peña’s “big” day — really</p>'))
            ->toContain('Peña')
            ->toContain('’')
            ->toContain('—');
    });
});

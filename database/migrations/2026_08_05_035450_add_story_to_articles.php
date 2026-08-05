<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Article bodies, so a reader stays in the app instead of bouncing to ESPN.
 *
 * `mediumtext` rather than `text`: measured across a sample, stories run 1.6 KB
 * to 28 KB, and `text` tops out at 64 KB — close enough to a long ranked-list
 * feature that a silent truncation is a real risk, and the wider column costs
 * nothing until it is used.
 *
 * `story_fetched_at` is the half that stops the fetching. Roughly a third of
 * articles are `Media` — video and photo posts that carry NO story at all — so
 * "story is null" cannot mean "not fetched yet", or every view of every video
 * post would ask ESPN again forever.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->mediumText('story')->nullable()->after('description');
            /*
             * A story's inline photos, in ESPN's own order — `<photo1>` in the
             * body means index 1 here, index 0 being the lead image the page
             * renders itself. Stored as a JSON ARRAY because the ORDER is the
             * meaning; a keyed object comes back from MySQL reordered.
             */
            $table->json('story_images')->nullable()->after('story');
            $table->timestamp('story_fetched_at')->nullable()->after('story_images');
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropColumn(['story', 'story_images', 'story_fetched_at']);
        });
    }
};

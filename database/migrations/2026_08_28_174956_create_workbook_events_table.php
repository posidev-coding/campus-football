<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The activity trail — what happened to an issue, who did it, and why.
 *
 * Five things could write `workbook_items.status` before this table existed and
 * four of them recorded nothing. A trail with holes is WORSE than no trail,
 * because it reads as a complete record; so every status write now goes through
 * `MoveWorkbookItem` and every insert here goes through `RecordWorkbookEvent`,
 * the same one-doorway shape as `GrantWalletEntry`.
 *
 * There is no prune. `FeedRun` prunes at fourteen days because it writes a row
 * a minute all Saturday; this writes maybe eight rows per issue, ever, and it
 * IS the audit — a Prunable here would delete the thing the table exists for.
 *
 * And no user identity, ever. `actor` holds a ROLE and an instance
 * ('human', 'advisor', 'agent:local', 'cloud:nightly'), because the telemetry
 * snapshot a third-party routine reads is asserted to carry no user identifiers
 * at all, and this column is the one that would quietly break that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workbook_events', function (Blueprint $table) {
            $table->id();
            // foreignId() IS correct here: workbook_items.id is a bigint from
            // ->id(). The data-model rule against it covers the ESPN-keyed
            // tables, whose ids are mediumint/int — teams, games, venues.
            $table->foreignId('workbook_item_id')->constrained()->cascadeOnDelete();
            // App\Models\WorkbookEvent's constants:
            // filed|readied|moved|claimed|released|started|pr_opened|commented|sized|labeled|linked.
            // `kind` rather than `event` or `type` — `client_errors` already
            // uses it, so it matches the house.
            $table->string('kind', 20);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->string('actor', 80);
            $table->text('note')->nullable();
            // {branch, pr_url, relation, labels_added} — whatever this kind of
            // event needs to be readable a month later.
            $table->json('context')->nullable();
            // No `updated_at`: an event is immutable, and the model says so
            // with `const UPDATED_AT = null`.
            $table->timestamp('created_at')->useCurrent();

            // The only query is one item's trail, so the index LEADS with the
            // item. `id` is the in-query tiebreak — two events written inside
            // one transaction share a second.
            $table->index(['workbook_item_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workbook_events');
    }
};

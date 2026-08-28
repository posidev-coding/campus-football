<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A workbook item becomes an issue: something with a reference, a branch, a
 * size and a claim, so a Claude Code session can be handed one and work it.
 *
 * There is deliberately NO `number` column. The reference is `CFB-{id}` derived
 * from this table's own auto-increment: minting a sequential number would have
 * to happen inside `WorkbookItem::propose()`'s read-then-write, where two
 * advisor passes overlapping either race the counter or take a lock on the one
 * write path that has to stay fast. InnoDB already solved this.
 *
 * The obligation that follows: `branch` is the DURABLE copy of the reference.
 * It gets externalized into git as `CFB-12-picks-n-plus-one` and lives there
 * forever, outliving this row — so it is unique, and it is NEVER rewritten once
 * stored. A later title edit does not move a branch.
 *
 * The other line this draws is ownership. The advisor recomputes title, body,
 * category, severity, evidence, prompt and source every week and owns them
 * outright; everything added here is a human's answer and a weekly routine
 * cannot reach any of it. `WorkbookItem::ADVISOR_OWNED` is where that is
 * enforced.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workbook_items', function (Blueprint $table) {
            // App\Enums\WorkbookEffort — s/m/l. A string rather than a MySQL
            // enum for the reason the create migration states: adding a case
            // is a deploy, not a migration. NULL means NOT SIZED, which is a
            // real answer and never defaults to medium.
            $table->string('effort', 1)->nullable()->after('severity');
            // Free-form, normalized by the model's mutator. A JSON column
            // rather than a pivot: the only two queries are "show them on a
            // card" and "filter to one", and `evidence` already sets the
            // JSON-on-the-row precedent. NULL means no labels, never `[]`.
            $table->json('labels')->nullable()->after('evidence');
            // The durable copy of the reference. Unique — a nullable unique
            // permits many NULLs, which is right, and is what makes
            // branch -> issue inference unambiguous. Never rewritten.
            $table->string('branch', 120)->nullable()->unique()->after('source');
            // Filled when a session opens the pull request. Wide, because it
            // is neither indexed nor sorted.
            $table->string('pr_url', 255)->nullable()->after('branch');
            // NOT the same fact as `status = planned`. Planned means we intend
            // to do this; ready means the brief is complete enough that an
            // agent can start without asking a human a question. Conflating
            // them is how a half-written card gets claimed at 3am.
            $table->timestamp('ready_at')->nullable()->after('last_seen_at');
            $table->timestamp('started_at')->nullable()->after('ready_at');
            // Distinct from `updated_at`, which any edit moves.
            $table->timestamp('completed_at')->nullable()->after('started_at');
            $table->timestamp('claimed_at')->nullable()->after('completed_at');
            // A ROLE and an instance — 'human', 'advisor', 'agent:local',
            // 'cloud:nightly'. NEVER a user id, a name or an email: the
            // telemetry snapshot carries no user identifiers at all, and this
            // column is the one that would quietly break that.
            $table->string('claimed_by', 80)->nullable()->after('claimed_at');
            // The lease. Without it a routine that dies mid-run parks an issue
            // forever, and freeing it needs a reaper nobody writes.
            $table->timestamp('claim_expires_at')->nullable()->after('claimed_by');

            // The claim query filters on status and narrows on ready_at. The
            // existing (status, position) index leads correctly but cannot
            // serve `ready_at is not null`. Nothing on claimed_at alone — it is
            // only ever read for one already-located row.
            $table->index(['status', 'ready_at']);
        });
    }

    public function down(): void
    {
        Schema::table('workbook_items', function (Blueprint $table) {
            $table->dropIndex(['status', 'ready_at']);
            $table->dropUnique(['branch']);
            $table->dropColumn([
                'effort', 'labels', 'branch', 'pr_url', 'ready_at',
                'started_at', 'completed_at', 'claimed_at', 'claimed_by', 'claim_expires_at',
            ]);
        });
    }
};

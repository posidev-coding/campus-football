<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The workbook: proposed work, with the evidence that proposed it and the
 * prompt to hand a Claude Code session.
 *
 * Filed by the maintenance advisor, a scheduled routine that re-reads real
 * telemetry every week. That cadence is the whole design constraint — it will
 * propose the same thing every single run, so **`key` is the idempotency**,
 * exactly as `GrantWalletEntry`'s keyed wallet entries work: `updateOrCreate`
 * on a stable slug bumps `last_seen_at` and refreshes the evidence, and never
 * duplicates.
 *
 * And it must never resurrect a `dismissed` row. Dismissing an item is how a
 * human says "we know, and no"; an advisor that re-opens it next Monday makes
 * the board a treadmill and teaches everyone to ignore it.
 *
 * Two surfaces read this, one model: a Filament Resource for triage — search,
 * filters, bulk edits — and a Kanban page for moving work along. The same
 * "one object, two surfaces" shape as CoverageReport → cfb:doctor + the
 * DataCoverage widget.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workbook_items', function (Blueprint $table) {
            $table->id();
            // The stable slug the advisor re-proposes under. UNIQUE, and it is
            // the only thing standing between a weekly routine and a board of
            // five hundred copies of the same finding.
            $table->string('key', 120)->unique();
            $table->string('title', 200);
            $table->text('body')->nullable();
            // App\Enums\WorkbookCategory / Severity / Status. Strings rather
            // than MySQL enums: adding a case is a deploy, not a migration.
            $table->string('category', 20);
            $table->string('severity', 20);
            $table->string('status', 20)->default('inbox');
            // What the advisor was looking at — telemetry excerpts, file:line
            // anchors, counts. Aggregate only; the snapshot it reads carries
            // no user identifiers, so neither can this.
            $table->json('evidence')->nullable();
            // The scaffolded Claude Code prompt. The reason the advisor is
            // repo-aware rather than telemetry-only: "the picks screen is
            // slow" is a complaint, this is a task.
            $table->text('prompt')->nullable();
            // Who filed it — 'advisor' today, a human tomorrow.
            $table->string('source', 40)->default('advisor');
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            // Order WITHIN a status column, for the board's drag. Ordering is
            // per-column, so this only ever compares against siblings.
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            // The board's query: one column, in order.
            $table->index(['status', 'position']);
            // The table's default sort and its two filters.
            $table->index(['category', 'severity']);
            $table->index('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workbook_items');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "This one blocks that one", "these two are the same finding".
 *
 * ONE DIRECTED ROW, never a mirrored pair. A mirror doubles every write and
 * every delete, and the first caller that does one half leaves a half-link no
 * unique index can even describe as broken — the argument `.ai/rules/support.md`
 * already makes for `FollowTeam`. The inverse is a pure function of the type,
 * so a mirror would carry no information either.
 *
 * Only THREE relations are storable: `blocks`, `relates_to`, `duplicates`.
 * `A blocked_by B` is written as `B blocks A`, and `relates_to` is stored with
 * the lower id first — it is symmetric, so without that rule `A relates_to B`
 * and `B relates_to A` are two rows the unique index happily accepts. That is
 * the detail a mirrored design hides and a single-row design has to say out loud.
 *
 * No cycle check. A recursive CTE on every write is cost a board of dozens does
 * not earn, and a three-hop cycle is a human problem, not a data-integrity one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workbook_links', function (Blueprint $table) {
            $table->id();
            // foreignId() is correct here — workbook_items.id is a bigint from
            // ->id(). The rule against it covers the ESPN-keyed tables.
            $table->foreignId('from_item_id')->constrained('workbook_items')->cascadeOnDelete();
            $table->foreignId('to_item_id')->constrained('workbook_items')->cascadeOnDelete();
            // App\Enums\WorkbookLinkType, storable cases only.
            $table->string('relation', 20);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['from_item_id', 'to_item_id', 'relation']);
            // The unique index LEADS with from_item_id, so it cannot answer
            // "what points at me" — which is half of every rendered link list.
            $table->index('to_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workbook_links');
    }
};

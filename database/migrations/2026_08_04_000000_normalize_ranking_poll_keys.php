<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Clear rankings so they can be re-synced from the core API.
 *
 * Two things changed at once, and neither is reconcilable with the old rows:
 *
 * 1. The poll key. The old sync stored ESPN's `type`, which is not unique —
 *    AFCA Division II and Division III both report `afca`, so two distinct
 *    polls were merged under one key. Poll keys are now derived from ESPN's
 *    unique numeric ranking id.
 *
 * 2. The source. The site endpoint never returns the CFP rankings at all, so
 *    the sync moved to the core API — which numbers its weeks differently.
 *    Mixing the two produced real corruption: AP week 2 held 36 teams with a
 *    maximum rank of 25, two different teams sharing rank 11.
 *
 * Rankings are cheap to re-sync (about 90 requests for a season), so the
 * correct move is to drop them rather than attempt a mapping that cannot be
 * made correct.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('rankings')->delete();
    }

    public function down(): void
    {
        // Nothing to restore; re-run `cfb:sync --only=rankings`.
    }
};

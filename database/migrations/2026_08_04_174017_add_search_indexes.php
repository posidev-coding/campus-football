<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Athletes are the one searchable table with real weight (34,836 rows), and
 * search prefix-matches them on `display_name` and `last_name`. The former was
 * already indexed; without this, every keystroke that touched `last_name` was
 * a full scan.
 *
 * Deliberately no FULLTEXT indexes anywhere. An InnoDB full-text index cannot
 * see rows inserted inside an uncommitted transaction — which is every row a
 * RefreshDatabase test creates — so a full-text search arm would pass in
 * production and be unprovable in the suite. At these table sizes (854 teams,
 * ~7k games), contains-LIKE scans cost single-digit milliseconds and match
 * mid-string, which "Alabama at Georgia" and bowl names actually need.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('athletes', function (Blueprint $table) {
            $table->index('last_name');
        });
    }

    public function down(): void
    {
        Schema::table('athletes', function (Blueprint $table) {
            $table->dropIndex(['last_name']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The activity rollup folds five truth tables into `user_days` every hour. Each
 * fold filters one table on a timestamp range and groups by the person, and
 * none of the five had an index that led with that timestamp. So every pass
 * walked the whole table, and these tables keep every row forever.
 * `conversation_posts` was the first to cross Pulse's one-second line.
 *
 * `(stamp, person)` puts the range filter on the leading column, so the query
 * can seek straight to the day. It also covers the whole select list, so
 * min/max and the group by are answered from the index without reading a row.
 * That matters most on `conversation_posts`, whose rows carry `body`.
 * Measured on 200k rows spread over 180 days: a full walk of 200k rows
 * (190-380ms) became a range read of about 1.1k (2-3ms), `Using index`.
 *
 * The columns match `ActivityRollup::TRUTH_SOURCES`, and
 * ActivitySchemaTest pins the two together.
 */
return new class extends Migration
{
    /** @var array<string, array{0: string, 1: string}> table => [stamp, person] */
    private const INDEXES = [
        'picks' => ['updated_at', 'user_id'],
        'conversation_posts' => ['created_at', 'user_id'],
        'team_follows' => ['created_at', 'user_id'],
        'group_members' => ['created_at', 'user_id'],
        'group_invites' => ['created_at', 'inviter_id'],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $columns) {
            Schema::table($table, function (Blueprint $table) use ($columns) {
                $table->index($columns);
            });
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $columns) {
            Schema::table($table, function (Blueprint $table) use ($columns) {
                $table->dropIndex($columns);
            });
        }
    }
};

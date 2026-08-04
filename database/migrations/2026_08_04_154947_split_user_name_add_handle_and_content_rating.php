<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name')->after('id');
            $table->string('last_name')->after('first_name');

            /*
             * The name a user picks for themselves, shown on leaderboards and
             * in group chat. Unique — and case-insensitively so, because the
             * column's utf8mb4_unicode_ci collation makes the index treat
             * `@Taylor` and `@taylor` as the same. Two accounts reading as one
             * person is exactly the confusion a handle exists to prevent.
             */
            $table->string('handle', 20)->unique()->after('last_name');
        });

        /*
         * Split what is already stored before the old column goes. Everything
         * after the first space becomes the surname — wrong for some names,
         * right for most, and only ever applied to rows that predate this,
         * since registration collects the two separately from here on.
         */
        foreach (DB::table('users')->select('id', 'name', 'email')->get() as $user) {
            $parts = preg_split('/\s+/', trim((string) $user->name), 2);

            DB::table('users')->where('id', $user->id)->update([
                'first_name' => $parts[0] ?: 'Fan',
                'last_name' => $parts[1] ?? '',
                // Seeded from the email local part, which is already unique.
                'handle' => Str::of($user->email)->before('@')->slug('')->limit(20, '')->lower()->toString(),
            ]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('name');
        });

        /*
         * Same axis as `trash_talk_intensity`, borrowed vocabulary: "Mild /
         * Locker Room / No Holds Barred" needed explaining, a film rating does
         * not. Renamed and remapped in place rather than dropped and recreated,
         * so nobody's chosen level is silently reset to the default.
         */
        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('trash_talk_intensity', 'content_rating');
        });

        foreach (['mild' => 'pg', 'locker_room' => 'pg13', 'no_holds_barred' => 'r'] as $was => $now) {
            DB::table('users')->where('content_rating', $was)->update(['content_rating' => $now]);
        }

        DB::statement("ALTER TABLE users ALTER COLUMN content_rating SET DEFAULT 'pg13'");
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('name')->after('id');
        });

        foreach (DB::table('users')->select('id', 'first_name', 'last_name')->get() as $user) {
            DB::table('users')->where('id', $user->id)->update([
                'name' => trim($user->first_name.' '.$user->last_name),
            ]);
        }

        foreach (['pg' => 'mild', 'pg13' => 'locker_room', 'r' => 'no_holds_barred'] as $was => $now) {
            DB::table('users')->where('content_rating', $was)->update(['content_rating' => $now]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('content_rating', 'trash_talk_intensity');
            $table->dropColumn(['first_name', 'last_name', 'handle']);
        });

        DB::statement("ALTER TABLE users ALTER COLUMN trash_talk_intensity SET DEFAULT 'locker_room'");
    }
};

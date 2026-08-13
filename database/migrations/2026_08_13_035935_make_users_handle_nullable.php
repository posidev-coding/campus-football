<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The handle leaves registration. Nothing consumes it yet — leaderboards and
 * group chat are future phases, and its only display site is Account — so
 * asking for it up front was a signup toll for a feature that does not exist.
 * Null means "never claimed"; claiming happens on Account (and later, at the
 * first Pick'em or chat moment). The unique index stays: MySQL permits any
 * number of NULLs under UNIQUE, and claimed handles must still collide
 * case-insensitively.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // change() drops any modifier not restated, so the full column
            // shape rides along. Indexes are untouched by change(); the
            // ci-collation unique index survives.
            $table->string('handle', 20)->nullable()->change();
        });
    }

    public function down(): void
    {
        /*
         * Restoring NOT NULL needs every row filled first. Same seed the
         * original handle migration used — the email local part, uniquified
         * with the row id on collision since two locals can slug identically.
         */
        foreach (DB::table('users')->whereNull('handle')->select('id', 'email')->get() as $user) {
            $handle = Str::of($user->email)->before('@')->slug('')->limit(20, '')->lower()->padRight(3, 'x')->toString();

            if (DB::table('users')->where('handle', $handle)->exists()) {
                $handle = Str::of($handle)->limit(20 - strlen((string) $user->id), '')->append((string) $user->id)->toString();
            }

            DB::table('users')->where('id', $user->id)->update(['handle' => $handle]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('handle', 20)->nullable(false)->change();
        });
    }
};

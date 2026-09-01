<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A commissioner's uploaded clubhouse icon — the path on the upload disk,
 * never a URL, because `config('cfb.upload_disk')` decides what a stored
 * path resolves to and a baked URL would outlive the disk that served it.
 *
 * Nullable is the normal state and stays the normal state: a group without
 * an icon renders its initials, and nothing anywhere writes a stand-in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->string('icon')->nullable()->after('kind');
        });
    }

    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->dropColumn('icon');
        });
    }
};

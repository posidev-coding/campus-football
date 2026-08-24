<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The inbox's real filter is (who, unread) ordered by recency, and every
 * asker uses exactly that shape: the unread dot's COUNT, the inbox screen,
 * and Filament's 30-second badge poll over the same table. The framework's
 * default index stops at (notifiable_type, notifiable_id), so each of
 * those filesorted the whole morph's rows. The leading columns match the
 * real filter — the data-model rule this table was violating.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->index(
                ['notifiable_type', 'notifiable_id', 'read_at', 'created_at'],
                'notifications_reader_unread_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex('notifications_reader_unread_index');
        });
    }
};

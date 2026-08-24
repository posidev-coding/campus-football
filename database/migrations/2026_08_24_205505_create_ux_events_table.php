<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The product funnel: one row per (day, signal), and never one row per event.
 *
 * This is the "where does it get hard" sensor, and it is the one telemetry
 * surface no off-the-shelf APM can produce — Pulse watches requests, queries
 * and exceptions, none of which know what onboarding is. Nothing else in the
 * app can answer "how many people opened an invite and never joined".
 *
 * COUNTED IN REDIS ON THE REQUEST PATH, persisted here by a nightly rollup.
 * The two flows being measured — picking and onboarding — are the two most
 * latency-sensitive in the product, and a row per event would put a MySQL
 * write in both of them to learn a number that is only ever read in
 * aggregate. See App\Actions\RecordUxEvent.
 *
 * Aggregate only. No user id, no session, no free text, by design and not by
 * omission: the advisor reads this snapshot, and a funnel that carries
 * identity is a funnel that cannot be handed to anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ux_events', function (Blueprint $table) {
            $table->id();
            // The day in the league's timezone, not UTC — a Saturday night
            // pick at 01:00 UTC Sunday belongs to Saturday's funnel.
            $table->date('day');
            // An App\Enums\UxSignal value. The vocabulary is bounded in code;
            // this column is a string so a rename is a data migration rather
            // than a schema one.
            $table->string('signal', 40);
            $table->unsignedInteger('count');
            $table->timestamps();

            // The rollup upserts on this pair, so a re-run of a day it has
            // already persisted corrects the number instead of doubling it.
            $table->unique(['day', 'signal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ux_events');
    }
};

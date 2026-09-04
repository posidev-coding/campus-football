<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notes readers send from inside the app.
 *
 * The one table in the telemetry neighborhood that holds a person's own
 * words, which is exactly why it is NOT part of it: nothing here reaches
 * TelemetrySnapshot or the advisor, and a note becomes work only when an
 * admin promotes it to a workbook item by hand — `workbook_item_id` is the
 * link back, so the table can answer "what happened to what I said".
 *
 * NOT PRUNED, and that is a decision rather than an omission. client_errors
 * is operational noise with a thirty-day shelf life; this is product signal
 * somebody typed, and a season's worth of it is the roadmap's best source.
 *
 * The context columns follow client_errors: a PATH and never the URL (a query
 * string is where an invite code rides), the width the reader could see, and
 * whether the app was installed — plus the release stamp, which is the first
 * triage question after the width.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedback', function (Blueprint $table) {
            $table->id();
            // From the SESSION, never the payload. Nullable so a deleted
            // account leaves its notes on the record rather than taking them.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // FeedbackKind, as a string: adding a case is a deploy, not a
            // migration.
            $table->string('kind', 20);
            $table->text('body');
            $table->string('path', 255)->nullable();
            $table->string('release', 20)->nullable();
            $table->unsignedSmallInteger('viewport')->nullable();
            $table->boolean('standalone')->default(false);
            $table->string('user_agent', 255)->nullable();
            // Triage. Null means nobody has looked yet; a timestamp says when
            // somebody did. There is deliberately no status column — read,
            // filed and set aside are all "handled", and the link below says
            // which of those it was.
            $table->timestamp('handled_at')->nullable();
            // The card this note became, when an admin promoted it.
            $table->foreignId('workbook_item_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            // "What has nobody looked at, newest first" is the only question
            // the triage table asks. A kind filter on a table this size earns
            // nothing from an index of its own.
            $table->index(['handled_at', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback');
    }
};

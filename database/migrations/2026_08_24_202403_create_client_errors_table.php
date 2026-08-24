<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * JavaScript errors, reported by the browsers they actually happened in.
 *
 * No APM covers this, Pulse included: everything else in the telemetry layer
 * watches the SERVER. A 390px PWA ships a broken swiper, a dead x-init or a
 * rejected fetch with a fully green test suite and a 200 in every log — the
 * automated tab produces no rendering frames, so no feature test can see it
 * either. The browser is the only witness this class of bug has.
 *
 * Rows are DEDUPED IN REDIS before they reach here: one bad deploy is
 * thousands of identical window.onerror posts, and the interesting fact about
 * them is that there were thousands, not each one. A fingerprint gets at most
 * one row per window and carries its own count — see App\Actions\RecordClientError.
 *
 * Pruned at thirty days: the value is operational, and the advisor that reads
 * it runs weekly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_errors', function (Blueprint $table) {
            $table->id();
            // sha1 of kind + normalized message + source + position. Computed
            // on the SERVER — a fingerprint the client could choose is a
            // fingerprint an extension can fragment.
            $table->char('fingerprint', 40);
            // 'error' (window.onerror) or 'unhandledrejection'.
            $table->string('kind', 24);
            $table->string('message', 500);
            // The script, not the page — `/build/assets/app-D3Kf9.js`.
            $table->string('source', 500)->nullable();
            $table->unsignedInteger('line')->nullable();
            // NOT `column`: reserved in MySQL, and a backticked reserved word
            // is a trap waiting for the first person who writes raw SQL.
            $table->unsignedInteger('col')->nullable();
            $table->text('stack')->nullable();
            // The PATH of the page, never the full URL — a query string is
            // where a signed link or an invite code would ride in.
            $table->string('path', 255)->nullable();
            $table->string('user_agent', 255)->nullable();
            // What the reader could actually see. The whole product is
            // designed at 390px, so "which width" is the first triage question.
            $table->unsignedSmallInteger('viewport')->nullable();
            $table->boolean('standalone')->default(false);
            // How many times this fingerprint fired inside its dedupe window,
            // updated at powers of ten. One row saying 4,000 and one row
            // saying 1 are different bugs.
            $table->unsignedInteger('reports')->default(1);
            // Taken from the SESSION, never from the payload. Nullable
            // because a guest hitting a broken public page is the report
            // that matters most.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            // "What is breaking, and is it still breaking" — the only two
            // questions asked of this table.
            $table->index(['fingerprint', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_errors');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Articles attached to a GAME, the way article_team attaches them to teams.
 *
 * The summary payload we already fetch carries the game's recap article and
 * a handful of related stories, previously discarded. `role` separates the
 * one recap from the reading list, so the game page can lead with the story
 * OF the game rather than a story near it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('article_game', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('article_id');
            $table->unsignedInteger('game_id');
            $table->string('role', 10)->default('related');
            $table->timestamps();

            $table->foreign('article_id')->references('id')->on('articles')->cascadeOnDelete();
            $table->foreign('game_id')->references('id')->on('games')->cascadeOnDelete();
            $table->unique(['article_id', 'game_id']);
            $table->index(['game_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_game');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A DIRECT invite: one member asking one named person into one private group.
 *
 * The 2026-08-13 pickem migration wrote "the invite IS the code — no invites
 * table in v1", and that stays true of the LINK: a code handed out by text
 * needs no row, because the code is the credential and the join is the record.
 * A direct invite is the other thing. It names a recipient, so the sender's
 * screen has to answer "did I already ask them?" and the answer cannot be
 * read out of the recipient's notifications — those are the recipient's rows,
 * and `data` is a text column nothing can query.
 *
 * So this table records the SEND and nothing else. There is deliberately no
 * accepted_at / declined_at: whether an invite was taken is derived from the
 * membership it produced, the same discipline as Pick::visibleTo — a stored
 * status column is a second truth that drifts the first time somebody joins
 * by the link instead.
 *
 * The unique index is the dedupe (one standing ask per person per group) and
 * the invitee index is the "who asked me" read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inviter_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('invitee_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['group_id', 'invitee_id']);
            $table->index('invitee_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_invites');
    }
};

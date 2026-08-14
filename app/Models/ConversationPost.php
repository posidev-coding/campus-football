<?php

namespace App\Models;

use Database\Factories\ConversationPostFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One message in The Conversation.
 *
 * A conversation IS its (topic_type, topic_id) pair — there is no parent
 * table, because a parent row would add a firstOrCreate race and hold
 * nothing. Topics are enforced-morph-mapped to exactly three scopes (game,
 * team, group) in AppServiceProvider; a fourth scope is a product decision,
 * not a string.
 *
 * Posts are immutable rows, the ledger shape: no updated_at, and
 * moderation deletes — it never edits, so a quote can never be made to lie
 * about what was said.
 */
#[Fillable(['topic_type', 'topic_id', 'user_id', 'body'])]
class ConversationPost extends Model
{
    /** @use HasFactory<ConversationPostFactory> */
    use HasFactory;

    /** A post is written once and never touched again. */
    public const UPDATED_AT = null;

    public function topic(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

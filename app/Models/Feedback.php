<?php

namespace App\Models;

use App\Enums\FeedbackKind;
use Database\Factories\FeedbackFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One note a reader sent. See the migration for why this is not telemetry
 * and is not pruned, and App\Actions\SendFeedback for the gates in front of
 * it.
 *
 * Only what the reader's own send may write is fillable. Triage — the
 * handled stamp and the card a note became — is the action layer's, written
 * with forceFill, so a mass-assigned "handled" cannot arrive from a form.
 */
#[Fillable(['user_id', 'kind', 'body', 'path', 'release', 'viewport', 'standalone', 'user_agent'])]
class Feedback extends Model
{
    /** @use HasFactory<FeedbackFactory> */
    use HasFactory;

    /**
     * Uncountable, so the pluralizer would land here on its own — said
     * anyway, so nobody has to check.
     */
    protected $table = 'feedback';

    protected function casts(): array
    {
        return [
            'kind' => FeedbackKind::class,
            'viewport' => 'integer',
            'standalone' => 'boolean',
            'handled_at' => 'datetime',
        ];
    }

    /** Nobody has looked yet. */
    public function scopeUnhandled(Builder $query): Builder
    {
        return $query->whereNull('handled_at');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function workbookItem(): BelongsTo
    {
        return $this->belongsTo(WorkbookItem::class);
    }
}

<?php

namespace App\Models;

use Database\Factories\WalletEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line in the wallet ledger: what a user earned or spent, and why.
 *
 * Rows are immutable — corrections are new rows, the way a bank does it, so
 * the history always explains the balance. Totals are SUMs over this table
 * (see User::walletTotals()); there is deliberately no balance column to
 * drift out of sync with its own history.
 *
 * All writes go through App\Actions\GrantWalletEntry — never create rows
 * directly, or a one-time grant loses its idempotency key.
 */
#[Fillable(['user_id', 'xp', 'credits', 'reason', 'key'])]
class WalletEntry extends Model
{
    /** @use HasFactory<WalletEntryFactory> */
    use HasFactory;

    /** A ledger row is written once and never touched again. */
    public const UPDATED_AT = null;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }
}

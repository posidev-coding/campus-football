<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Notifications\DatabaseNotification;

/**
 * The inbox row, surfaced as a prunable model — the framework's own
 * DatabaseNotification with a retirement policy on top.
 *
 * READ rows older than ninety days go: the inbox is a season-scoped
 * surface ("you won Week 3" matters in October, not next August), the
 * table grows by every settled slate times every member, and Filament
 * polls it every 30 seconds. Unread rows stay whatever their age — an
 * unread result is still news to its reader. This closes the roadmap's
 * open retention item and rides the same model:prune schedule as
 * FeedRun and User.
 */
class StoredNotification extends DatabaseNotification
{
    use MassPrunable;

    public function prunable(): Builder
    {
        return static::query()
            ->whereNotNull('read_at')
            ->where('created_at', '<', now()->subDays(90));
    }
}

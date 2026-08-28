<?php

namespace App\Models;

use App\Enums\WorkbookStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One line of an issue's activity trail. Immutable, and never written directly
 * — `RecordWorkbookEvent` is the only class that inserts one.
 *
 * `actor` is a ROLE, never a person: 'human', 'advisor', 'agent:local',
 * 'cloud:nightly'. If it ever held a name or an email, and events ever reached
 * `TelemetrySnapshot`, the snapshot's no-identity guarantee would be one commit
 * from being false. If the panel ever wants the real name, that is a nullable
 * `actor_user_id` that is never serialized — with a test.
 */
class WorkbookEvent extends Model
{
    /** An event is a fact about a moment. There is nothing to update. */
    public const UPDATED_AT = null;

    public const FILED = 'filed';

    public const READIED = 'readied';

    public const MOVED = 'moved';

    public const CLAIMED = 'claimed';

    public const RELEASED = 'released';

    public const STARTED = 'started';

    public const PR_OPENED = 'pr_opened';

    public const COMMENTED = 'commented';

    public const SIZED = 'sized';

    public const LABELED = 'labeled';

    public const LINKED = 'linked';

    /** A human at a keyboard — the panel, and `cfb:issue --as=human`. */
    public const ACTOR_HUMAN = 'human';

    /** The weekly maintenance routine. */
    public const ACTOR_ADVISOR = 'advisor';

    /** A Claude Code session on this machine. A cloud routine names itself. */
    public const ACTOR_AGENT = 'agent:local';

    /** `actor` is 80 characters, and a trail row is not the place to fail a write. */
    public const ACTOR_MAX_LENGTH = 80;

    /**
     * An automated actor — `agent:local`, `cloud:nightly`, anything a routine
     * names itself.
     *
     * The one thing this gates is Done. If a session could close its own work,
     * In review is decorative and the trail fills with sessions marking
     * themselves complete; merging earns Done, and merging is a human's.
     */
    public static function isAgent(string $actor): bool
    {
        return Str::startsWith($actor, ['agent:', 'cloud:']);
    }

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'from_status' => WorkbookStatus::class,
            'to_status' => WorkbookStatus::class,
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<WorkbookItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(WorkbookItem::class, 'workbook_item_id');
    }
}

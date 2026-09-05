<?php

namespace App\Models;

use Database\Factories\GroupInviteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One member asked one named person into one private group.
 *
 * The row is the SEND, not a state machine. Whether the ask was taken is
 * answered by `group_members`, never by a column here — see the migration.
 *
 * @property int $id
 * @property int $group_id
 * @property int $inviter_id
 * @property int $invitee_id
 */
class GroupInvite extends Model
{
    /** @use HasFactory<GroupInviteFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'group_id',
        'inviter_id',
        'invitee_id',
    ];

    /** @return BelongsTo<Group, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /** @return BelongsTo<User, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inviter_id');
    }

    /** @return BelongsTo<User, $this> */
    public function invitee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invitee_id');
    }
}

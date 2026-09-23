<?php

namespace App\Notifications;

use App\Enums\ContestMode;
use App\Support\Brand;
use App\Support\Voice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Arr;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * "The private groups are one group now" — the announcement half of
 * MergeGroups, one per member of the group that survived.
 *
 * TRANSACTIONAL, like GroupModeChanged: nobody asked to be moved, so this
 * is not a list anybody opted into and it rides no budget or unsubscribe.
 * But it MAILS only a proven address — a seat in a private group can belong
 * to an account that never verified (JoinGroup), and SlateSettled draws the
 * same line. Everybody gets the inbox row, and a push where a device said
 * yes.
 *
 * Scalars only at construction: by the time the queued job runs, the groups
 * named in `former` no longer exist, so there is nothing a model could be
 * re-read from. `for:` is passed on every line, because inside the job there
 * is no authenticated user for Voice to fall back on.
 */
class GroupsMerged extends Notification implements ShouldQueue
{
    use Queueable;

    /** The reader's group was folded into the survivor. */
    public const MOVED = 'moved';

    /** The reader was already in the survivor. */
    public const STAYED = 'stayed';

    /**
     * @param  string  $mode  the survivor's ContestMode backing value
     * @param  int  $size  the survivor's own slate size
     * @param  list<string>  $former  the retired groups this reader sat in
     * @param  list<string>  $ran  the retired groups this reader ran
     * @param  int  $count  how many groups were folded in
     * @param  string  $deadline  the league's slate deadline, as a label
     */
    public function __construct(
        public readonly string $variant,
        public readonly int $groupId,
        public readonly string $group,
        public readonly string $url,
        public readonly string $mode,
        public readonly int $size,
        public readonly bool $pivoted,
        public readonly bool $freshStart,
        public readonly array $former,
        public readonly array $ran,
        public readonly bool $runsIt,
        public readonly int $count,
        public readonly string $deadline,
    ) {}

    /** @return list<string|class-string> */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if ($notifiable->email_verified_at !== null) {
            $channels[] = 'mail';
        }

        // The subscription IS the consent — a push_subscriptions row can
        // only exist through a grant on a device.
        if ($notifiable->pushSubscriptions()->exists()) {
            $channels[] = WebPushChannel::class;
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(Voice::line("notify.groups_merged.subject.{$this->variant}", $this->replace(), for: $notifiable).' — '.Brand::name())
            ->greeting('Hey, '.$notifiable->first_name.'.')
            ->line(Voice::line("notify.groups_merged.{$this->variant}.body", $this->replace(), for: $notifiable));

        foreach ($this->asides($notifiable) as $aside) {
            $mail->line($aside);
        }

        return $mail
            ->action('Open the group', $this->url)
            ->salutation('— '.Brand::name());
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        return (new WebPushMessage)
            ->title(Voice::line("notify.groups_merged.subject.{$this->variant}", $this->replace(), for: $notifiable))
            ->body(Voice::line($this->inboxKey(), $this->replace(), for: $notifiable))
            ->icon(Brand::asset('icon-192'))
            ->badge(Brand::asset('icon-192'))
            ->tag('groups-merged-'.$this->groupId)
            ->data(['url' => $this->url]);
    }

    /**
     * The inbox row. STRUCTURED, never rendered, so the line resolves in the
     * reader's register at read time rather than the one they had today.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'groups-merged',
            'key' => $this->inboxKey(),
            'replace' => $this->replace(),
            'url' => $this->url,
        ];
    }

    private function inboxKey(): string
    {
        return "notify.groups_merged.inbox.{$this->variant}";
    }

    /**
     * Everything under the opening line, each render-guarded so a reader
     * the merge did not touch in some way simply hears less: the thanks for
     * running a group, what left History, the pivot, the rules, the fresh
     * table, and — for the survivor's commissioner — whose slate is next.
     *
     * @return list<string>
     */
    private function asides(object $notifiable): array
    {
        $lines = [];

        // Named by the groups this reader RAN, which may be fewer than the
        // groups they sat in.
        if ($this->ran !== []) {
            $lines[] = Voice::line('notify.groups_merged.ran', $this->replace(['former' => $this->names($this->ran)]), for: $notifiable);
        }

        if ($this->former !== []) {
            $lines[] = Voice::line('notify.groups_merged.retired', $this->replace(), for: $notifiable);
        }

        if ($this->pivoted) {
            $lines[] = Voice::line('notify.groups_merged.pivot', $this->replace(), for: $notifiable);
        }

        $lines[] = Voice::line('notify.groups_merged.rules', $this->replace(), for: $notifiable);

        foreach (ContestMode::from($this->mode)->ruleLines($this->size) as $rule) {
            $lines[] = $rule;
        }

        if ($this->freshStart) {
            $lines[] = Voice::line('notify.groups_merged.fresh_start', $this->replace(), for: $notifiable);
        }

        if ($this->runsIt) {
            $lines[] = Voice::line('notify.groups_merged.runs_it', $this->replace(), for: $notifiable);
        }

        return array_values(array_filter($lines));
    }

    /**
     * The replacements, with the two values people TYPED last: fill() runs
     * in order, so a group named with a colon in it can only ever be the
     * last thing substituted, never something a later token rewrites.
     *
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function replace(array $overrides = []): array
    {
        return array_merge([
            'mode' => ContestMode::from($this->mode)->label(),
            'count' => (string) $this->count,
            'size' => (string) $this->size,
            'deadline' => $this->deadline,
            'former' => $this->names($this->former),
            'group' => $this->group,
        ], $overrides);
    }

    /** @param  list<string>  $names */
    private function names(array $names): string
    {
        return Arr::join($names, ', ', ' and ');
    }
}

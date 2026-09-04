<?php

use App\Actions\SendFeedback;
use App\Enums\FeedbackKind;
use App\Exceptions\FeedbackTooFast;
use App\Support\Voice;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The feedback sheet — mounted ONCE, by the app layout, for every signed-in
 * reader, and opened by name from wherever a door is.
 *
 * One component rather than a form on each screen, for the reason the search
 * palette is one component: a modal declared in four places is four modals
 * to keep in step, and the doors are cheap — Account's row, the foot of My
 * Picks, the rules page and the desktop menu each only dispatch the name.
 * That is also why it carries no state a screen would need to reach in: the
 * page it opened from is read off the request, not handed up by a caller.
 *
 * Under wire:navigate every hop is a real GET of the destination, so the
 * layout — and this — re-mount with the new path. A half-typed note is lost
 * on navigation, the way a half-typed search is; the modal being open is what
 * stops the navigation.
 *
 * NOTHING HERE QUERIES. It is on every signed-in page the day before the
 * first public Saturday, and a mount that reads the database is a sitewide
 * cost paid by readers who will never open it.
 */
new class extends Component
{
    /**
     * The page the sheet was opened from. Read off the REQUEST in mount()
     * rather than handed up by the browser: Locked means a tampered snapshot
     * is a refused request rather than a lie in the table, and the Action
     * still reduces it to a path on the way in.
     */
    #[Locked]
    public ?string $path = null;

    public string $kind = 'idea';

    public string $body = '';

    /** A short answer to the send, rendered near the button — never a toast. */
    public ?string $notice = null;

    public string $noticeTone = 'neutral';

    public function mount(): void
    {
        $this->path = '/'.ltrim(request()->path(), '/');
    }

    /**
     * The viewport and the standalone flag are the browser's word and arrive
     * as `mixed` on purpose: a Livewire action is a public endpoint, and a
     * made-up width is clamped by the Action rather than turned into a
     * TypeError somebody has to read. Every other gate is the Action's too.
     */
    public function send(SendFeedback $action, mixed $viewport = null, mixed $standalone = false): void
    {
        $this->notice = null;
        $this->resetValidation();

        $user = auth()->user();

        if ($user === null) {
            return;
        }

        // The chips can only set what we wrote, but the property is public.
        $kind = FeedbackKind::tryFrom($this->kind);

        if ($kind === null) {
            $this->kind = FeedbackKind::Idea->value;

            return;
        }

        // Plain messages, on purpose: an empty box and a full one are
        // instructions, and an instruction is where a joke eats the point.
        $this->validate(
            ['body' => ['required', 'string', 'max:'.SendFeedback::MAX_LENGTH]],
            [
                'body.required' => 'Write the note first.',
                'body.max' => 'Keep it under '.number_format(SendFeedback::MAX_LENGTH).' characters.',
            ],
        );

        try {
            $action->handle($user, $kind, $this->body, [
                'path' => $this->path,
                'viewport' => $viewport,
                'standalone' => $standalone,
                'user_agent' => request()->userAgent(),
            ]);
        } catch (FeedbackTooFast $e) {
            $minutes = max(1, (int) ceil($e->availableIn / 60));

            $this->addError('body', Voice::line('feedback.too_fast', [
                'wait' => $minutes.' '.Str::plural('minute', $minutes),
            ]));

            return;
        } catch (InvalidArgumentException) {
            // Already validated above; this is the Action's own word that
            // nothing was written, kept as a message rather than a 500.
            $this->addError('body', 'Write the note first.');

            return;
        } catch (Throwable $e) {
            report($e);

            $this->notice = Voice::line('feedback.failed');
            $this->noticeTone = 'error';

            return;
        }

        $this->body = '';
        $this->notice = Voice::line('feedback.sent');
        $this->noticeTone = 'success';
    }
}; ?>

{{-- An unconditional root: a Livewire root born inside a Blade conditional
     corrupts the child record the layout keeps for it. `contents` so the
     wrapper has no box of its own beside the overlays. --}}
<div class="contents" data-help-sheet>
    <flux:modal name="help" class="w-full max-w-md">
        {{-- The two context values ride the submit as JS: what the reader
             could see, and whether they are inside the installed app. Both
             are the browser's word, and the Action treats them that way. --}}
        <form
            wire:submit="send(innerWidth, matchMedia('(display-mode: standalone)').matches)"
            class="flex flex-col gap-5"
        >
            <div>
                <flux:heading size="lg">Send feedback</flux:heading>
                <flux:subheading>{{ Voice::line('feedback.subheading') }}</flux:subheading>
            </div>

            {{-- Four chips fit a 390 track in `fill`; the clubhouse strip
                 holds five. The labels are plain — a chip IS its label. --}}
            <x-gutter-tabs
                :items="FeedbackKind::options()"
                :selected="$kind"
                model="kind"
                key-prefix="fb-kind"
                variant="fill"
                label="What kind of note"
            />

            <div class="flex flex-col gap-1">
                {{-- Deferred, not live: a note is a paragraph, and a round
                     trip per keystroke buys nothing here. --}}
                <flux:textarea
                    wire:model="body"
                    rows="4"
                    maxlength="{{ SendFeedback::MAX_LENGTH }}"
                    placeholder="What happened, or what would help?"
                    aria-label="Your note"
                />
                {{-- Below the field on its own row: the too-fast line is a
                     sentence long, and there is no width beside it at 390. --}}
                <flux:error name="body" />
            </div>

            @if ($notice)
                <x-notice :tone="$noticeTone">{{ $notice }}</x-notice>
            @endif

            <div class="flex items-center justify-between gap-3">
                {{-- Says what rides along, because it does: the page and the
                     build are the first two triage questions, and a reader
                     should not learn that from the privacy policy. --}}
                <p class="min-w-0 text-micro text-zinc-500 dark:text-zinc-400">
                    Sent with the page you're on and the app version.
                </p>

                <div class="flex shrink-0 items-center gap-2">
                    <flux:modal.close>
                        <flux:button size="sm" variant="ghost">Cancel</flux:button>
                    </flux:modal.close>

                    <flux:button
                        type="submit"
                        size="sm"
                        variant="primary"
                        wire:loading.attr="disabled"
                        wire:target="send"
                    >
                        Send
                    </flux:button>
                </div>
            </div>
        </form>
    </flux:modal>
</div>

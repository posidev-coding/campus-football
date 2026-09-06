<?php

use App\Actions\RecordActivity;
use App\Actions\SendFeedback;
use App\Enums\ActivityKind;
use App\Enums\FeedbackKind;
use App\Exceptions\FeedbackTooFast;
use App\Support\HelpAnswer;
use App\Support\HelpTopics;
use App\Support\StatAnswer;
use App\Support\Voice;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The help sheet — mounted ONCE, by the app layout, for every signed-in
 * reader, and opened by name from wherever a door is.
 *
 * Two panes on one modal. ASK, while the help flag is open: a question, an
 * explicit tap, and an answer a person wrote for the topic the model named —
 * or a miss that hands the question to the other pane in one tap. FEEDBACK,
 * always: the note to the humans. One component rather than a form on each
 * screen, for the reason the search palette is one component: a modal
 * declared in four places is four modals to keep in step, and the doors are
 * cheap — Account's row, the foot of My Picks, the rules page and the desktop
 * menu each only dispatch the name. That is also why it carries no state a
 * screen would need to reach in: the page it opened from is read off the
 * request, not handed up by a caller.
 *
 * Under wire:navigate every hop is a real GET of the destination, so the
 * layout — and this — re-mount with the new path. A half-typed note is lost
 * on navigation, the way a half-typed search is; the modal being open is what
 * stops the navigation. "Take me there" under an answer navigates on purpose,
 * and the re-mount is what closes the sheet.
 *
 * NOTHING HERE QUERIES ON MOUNT OR RENDER. It is on every signed-in page, and
 * a mount that reads the database is a sitewide cost paid by readers who will
 * never open it. The flag is config, the examples are a constant, the copy
 * reads the loaded user, and the daily cap is a limiter lookup made only
 * inside a tap.
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

    /** `ask` or `feedback`. Ask exists only while the help flag is open. */
    public string $tab = 'feedback';

    public string $q = '';

    /** The exact question `answer` belongs to, printed over the answer. */
    public ?string $asked = null;

    /** @var array<string, mixed>|null */
    public ?array $answer = null;

    /** idle | answered | missed | capped */
    public string $askState = 'idle';

    public string $kind = 'idea';

    public string $body = '';

    /** A short answer to the send, rendered near the button — never a toast. */
    public ?string $notice = null;

    public string $noticeTone = 'neutral';

    public function mount(): void
    {
        $this->path = '/'.ltrim(request()->path(), '/');
        $this->tab = HelpAnswer::available(auth()->user()) ? 'ask' : 'feedback';
    }

    /**
     * `$set('tab', …)` is a public endpoint. Ask while the flag is closed is
     * a tamper, and anything but the two panes is one too — the `$kind`
     * reset in send() is the precedent.
     */
    public function updatedTab(string $value): void
    {
        if ($value === 'ask' && HelpAnswer::available(auth()->user())) {
            return;
        }

        $this->tab = 'feedback';
    }

    /**
     * The ask is THIS tap, never a debounce: `q` is deferred, so a keystroke
     * costs nothing and a question costs one call — which the intent cache
     * usually makes zero. Every gate past the two plain messages is the
     * resolver's.
     */
    public function ask(HelpAnswer $help): void
    {
        $this->resetValidation();

        $user = auth()->user();

        if ($user === null || ! HelpAnswer::available($user)) {
            return;
        }

        $question = trim($this->q);

        // Plain messages: an empty box and a half-typed one are instructions,
        // and an instruction is where a joke eats the point.
        if ($question === '' || mb_strlen($question) > 200 || ! StatAnswer::looksLikeAQuestion($question)) {
            $this->addError('q', 'Ask a whole question.');

            return;
        }

        $this->asked = $question;
        $this->answer = null;

        // The one limiter read, inside the tap — never on render.
        if (HelpAnswer::capped($user)) {
            $this->askState = 'capped';

            return;
        }

        [$this->answer] = $help->for($question, $user);

        $this->askState = $this->answer === null ? 'missed' : 'answered';

        $this->countAsk();
    }

    /**
     * An INDEX, never the text — a Livewire action is a public endpoint, so
     * the button can only ask one of the three we wrote. And no model: the
     * example carries its topic, so a tap is a lookup.
     */
    public function askExample(int $index): void
    {
        $user = auth()->user();

        if ($user === null || ! HelpAnswer::available($user)) {
            return;
        }

        $example = HelpTopics::examples()[$index] ?? null;

        if ($example === null) {
            return;
        }

        $this->resetValidation();

        $this->q = $example['question'];
        $this->asked = $example['question'];
        $this->answer = HelpTopics::answer($example['topic'], $user);
        $this->askState = $this->answer === null ? 'missed' : 'answered';

        $this->countAsk();
    }

    /**
     * The ask, counted — both doors through it, typed and tapped.
     *
     * The facet is whether it landed. A `help_asked` total on its own says
     * people are confused; the split says whether the help sheet is any use
     * to them, which is the only part anybody can act on. Not counted for a
     * cap or an unavailable flag: neither is a question the reader got to
     * ask.
     */
    private function countAsk(): void
    {
        app(RecordActivity::class)->action(
            ActivityKind::HelpAsked,
            request(),
            $this->answer === null ? 'unanswered' : 'answered',
        );
    }

    /** The miss, handed to the humans: the question becomes the note. */
    public function sendAsFeedback(): void
    {
        $this->tab = 'feedback';
        $this->kind = FeedbackKind::Confused->value;
        $this->body = (string) $this->asked;
        $this->notice = null;
        $this->resetValidation();
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
        @php($helpOpen = HelpAnswer::available(auth()->user()))
        @php($asking = $helpOpen && $tab === 'ask')

        <div class="flex flex-col gap-5">
            <div>
                <flux:heading size="lg">{{ HelpAnswer::doorLabel(auth()->user()) }}</flux:heading>
                <flux:subheading>{{ $asking ? Voice::line('help.subheading') : Voice::line('feedback.subheading') }}</flux:subheading>
            </div>

            @if ($helpOpen)
                {{-- Outside both forms: a strip inside a form is a strip that
                     submits nothing and confuses everyone. Two stops fit the
                     track in `block`. --}}
                <x-gutter-tabs
                    :items="['ask' => 'Ask', 'feedback' => 'Feedback']"
                    :selected="$tab"
                    model="tab"
                    key-prefix="help-tab"
                    variant="block"
                    label="Help or feedback"
                />
            @endif

            {{-- Each pane is keyed: the two forms submit to different methods,
                 and a morph that reused one `<form>` for the other would carry
                 the first pane's submit into the second. --}}
            <div wire:loading.class="opacity-60 pointer-events-none" wire:target="tab">
                @if ($asking)
                    <div wire:key="help-pane-ask">
                        {{-- A DELIBERATE DEVIATION from the stat answers'
                             staleness rule. There, `q` is live and a keystroke
                             retires the answer. Here `q` is deferred — the ask
                             is the tap, never a debounce, and there is no
                             search to re-render — so typing retires nothing;
                             the question printed over the answer is what keeps
                             an old answer from sitting under a new one, and the
                             next tap replaces both. --}}
                        <form wire:submit="ask" class="flex flex-col gap-4">
                            <div class="flex flex-col gap-1">
                                <div class="flex items-center gap-2">
                                    <flux:input
                                        wire:model="q"
                                        maxlength="200"
                                        placeholder="What are you stuck on?"
                                        aria-label="Your question"
                                        class="flex-1"
                                    />

                                    <flux:button
                                        type="submit"
                                        size="sm"
                                        variant="primary"
                                        wire:loading.attr="disabled"
                                        wire:target="ask, askExample"
                                    >
                                        Ask
                                    </flux:button>
                                </div>

                                <flux:error name="q" />
                            </div>

                            @if ($askState === 'answered' && $answer)
                                <div class="flex flex-col gap-2 rounded-xl border border-zinc-200 px-4 py-3 dark:border-zinc-800" wire:key="help-answer">
                                    <p class="text-micro text-zinc-500 dark:text-zinc-400">{{ $asked }}</p>
                                    <p class="font-semibold leading-tight">{{ $answer['title'] }}</p>
                                    <p class="text-sm">{{ $answer['body'] }}</p>
                                    {{-- Navigates on purpose; the page swap re-mounts the sheet closed. --}}
                                    <flux:button :href="$answer['href']" wire:navigate size="sm" variant="primary" class="self-start">
                                        {{ $answer['cta'] }}
                                    </flux:button>
                                </div>
                            @elseif ($askState === 'missed')
                                <div class="flex flex-col gap-2 rounded-xl border border-dashed border-zinc-300 px-4 py-3 dark:border-zinc-700" wire:key="help-missed">
                                    <p class="text-micro text-zinc-500 dark:text-zinc-400">{{ $asked }}</p>
                                    <p class="text-sm">{{ Voice::line('help.none') }}</p>
                                    <flux:button type="button" wire:click="sendAsFeedback" size="sm" variant="ghost" class="self-start">
                                        Send this as feedback
                                    </flux:button>
                                </div>
                            @elseif ($askState === 'capped')
                                <x-notice>{{ Voice::line('help.capped', ['cap' => HelpAnswer::DAILY_CAP]) }}</x-notice>
                            @else
                                {{-- THE DISCOVERY SURFACE: real questions, one
                                     tap each, and the shape is learned by having
                                     used it once. The INDEX rides the click. --}}
                                <div class="flex flex-col gap-2 rounded-xl border border-dashed border-zinc-300 px-4 py-3 dark:border-zinc-700" wire:key="help-idle">
                                    <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ Voice::line('help.idle') }}</p>

                                    <div class="flex flex-col gap-1">
                                        @foreach (HelpTopics::examples() as $i => $example)
                                            <button
                                                type="button"
                                                wire:click="askExample({{ $i }})"
                                                wire:loading.attr="disabled"
                                                wire:target="askExample"
                                                wire:key="help-eg-{{ $i }}"
                                                class="flex items-center gap-2 rounded-lg border border-zinc-200 px-3 py-2 text-start text-sm transition-colors hover:border-zinc-300 disabled:opacity-60 dark:border-zinc-800 dark:hover:border-zinc-700"
                                            >
                                                <span class="min-w-0 flex-1">{{ $example['question'] }}</span>
                                                <flux:icon.chevron-right variant="micro" class="shrink-0 text-zinc-400" />
                                            </button>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        </form>
                    </div>
                @else
                    <div wire:key="help-pane-feedback">
                        {{-- The two context values ride the submit as JS: what
                             the reader could see, and whether they are inside
                             the installed app. Both are the browser's word, and
                             the Action treats them that way. --}}
                        <form
                            wire:submit="send(innerWidth, matchMedia('(display-mode: standalone)').matches)"
                            class="flex flex-col gap-5"
                        >
                            {{-- Four chips fit a 390 track in `fill`; the
                                 clubhouse strip holds five. The labels are
                                 plain — a chip IS its label. --}}
                            <x-gutter-tabs
                                :items="FeedbackKind::options()"
                                :selected="$kind"
                                model="kind"
                                key-prefix="fb-kind"
                                variant="fill"
                                label="What kind of note"
                            />

                            <div class="flex flex-col gap-1">
                                {{-- Deferred, not live: a note is a paragraph,
                                     and a round trip per keystroke buys nothing
                                     here. --}}
                                <flux:textarea
                                    wire:model="body"
                                    rows="4"
                                    maxlength="{{ SendFeedback::MAX_LENGTH }}"
                                    placeholder="What happened, or what would help?"
                                    aria-label="Your note"
                                />
                                {{-- Below the field on its own row: the
                                     too-fast line is a sentence long, and there
                                     is no width beside it at 390. --}}
                                <flux:error name="body" />
                            </div>

                            @if ($notice)
                                <x-notice :tone="$noticeTone">{{ $notice }}</x-notice>
                            @endif

                            <div class="flex items-center justify-between gap-3">
                                {{-- Says what rides along, because it does:
                                     the page and the build are the first two
                                     triage questions, and a reader should not
                                     learn that from the privacy policy. --}}
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
                    </div>
                @endif
            </div>
        </div>
    </flux:modal>
</div>

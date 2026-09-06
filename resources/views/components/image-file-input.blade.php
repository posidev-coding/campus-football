{{--
    A FILE INPUT THAT REFUSES AN OVERSIZED IMAGE BEFORE IT IS A REQUEST.

    `wire:model` on a file input uploads whatever the picker hands it, and
    that is the bug: PHP throws away a request body over `post_max_size`
    along with its CSRF token, so a big image never reaches the validation
    that would have explained itself. What the reader gets instead is
    Livewire failing to parse an HTML error page — "The page has expired",
    in a browser alert, with no mention of a file. Production, 2026-09-01.

    So the upload is driven by hand: measure first, then `$wire.upload()`.
    The cap and the message come from App\Support\ImageUpload; the host
    component must use App\Livewire\Concerns\UploadsImages for the knock.

    `accept` names four formats, not `image/*`: an iPhone answers `image/*`
    with a HEIC, which the server's `image` rule accepts and its
    `dimensions` rule then misreports as "too small". The picker steers
    toward JPG/PNG/GIF/WebP and the mime rule refuses the rest by name.

    The body lives in an `x-data` METHOD, never in the `x-on` expression —
    Alpine compiles an attribute expression as `result = <expr>`, so a
    multi-statement body there is a SyntaxError it swallows, and the
    directive goes inert rather than failing loudly (AlpineExpressionsTest).
--}}
@props([
    /** The Livewire property to upload into. */
    'property',
    /** The input's accessible name — it is visually hidden. */
    'label',
])

<input
    type="file"
    accept="{{ \App\Support\ImageUpload::accept() }}"
    class="sr-only"
    aria-label="{{ $label }}"
    x-data="{
        property: @js($property),
        max: {{ \App\Support\ImageUpload::MAX_KB }} * 1024,
        pick(event) {
            const file = event.target.files[0];

            {{-- Cleared either way: picking the SAME file twice has to fire
                 change twice, and a refused file must not sit in the input
                 pretending it was accepted. --}}
            event.target.value = '';

            if (! file) {
                return;
            }

            if (file.size > this.max) {
                this.knock('rejectOversizedImage');

                return;
            }

            {{-- The fourth argument is the one that must never be dropped.
                 `$wire.upload(name, file, finish, error, progress)` called
                 without an error callback fails INVISIBLY: the picker goes
                 quiet, the property is never set, the `updated...` hook
                 never runs, and Livewire's own rejection escapes to the
                 window as a bare object the reporter can only file as
                 "[object Object]" with no stack. Knock instead, on the
                 same error line the size gate uses. --}}
            $wire.upload(
                this.property,
                file,
                () => {},
                () => this.knock('reportRefusedUpload'),
            );
        },

        {{-- The knock itself, which can fail like anything else on a phone.
             A `$wire.call()` made from an Alpine handler is NOT the promise
             Livewire catches for `wire:click`: Livewire rejects an action
             with a plain `{ status, body, json, errors }` object, Alpine
             hands the rejection to nobody, and it escapes to the window —
             where the reporter can only file it as "[object Object]" with no
             stack and no name for the screen it happened on. Named here
             instead, through the one guarded machine in app.js, which is
             optional-chained because a bundle that failed to load must not
             turn a lost knock into a second lost rejection. --}}
        knock(method) {
            $wire.call(method, this.property).catch(
                (error) => window.cfbErrors?.failure(this.property + ` upload knock failed (${method})`, error),
            );
        },
    }"
    x-on:change="pick($event)"
    {{ $attributes }}
>

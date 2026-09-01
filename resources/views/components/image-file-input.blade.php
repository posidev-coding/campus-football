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
    accept="image/*"
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
                $wire.call('rejectOversizedImage', this.property);

                return;
            }

            $wire.upload(this.property, file);
        },
    }"
    x-on:change="pick($event)"
    {{ $attributes }}
>

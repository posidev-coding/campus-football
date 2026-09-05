<?php

namespace App\Livewire\Concerns;

use App\Support\ImageUpload;
use App\Support\Voice;

/**
 * The Livewire half of a guarded image upload: the doors the browser knocks
 * on when a file never became a stored image.
 *
 * Two of them, because the browser refuses for two different reasons — it
 * measured the file and would not send it, or it sent it and the upload did
 * not land. Both write to the property's own error bag so the single
 * `<flux:error>` beside the control speaks for every gate.
 *
 * The cap, the rule and the sentence live on {@see ImageUpload}, which the
 * Blade control reads too — there is one number, so the browser and the
 * server cannot drift apart about what "too big" means.
 */
trait UploadsImages
{
    /**
     * The browser's own refusal, written into the same error bag the server
     * rule writes to, so one `<flux:error>` renders both and the reader
     * never learns there is more than one gate.
     *
     * The property name arrives from the client, so it is checked against
     * the component rather than trusted: the worst this could otherwise do
     * is decorate an unrelated error bag key, but a public Livewire method
     * taking an arbitrary string should say what it accepts.
     */
    public function rejectOversizedImage(string $property): void
    {
        if (! property_exists($this, $property)) {
            return;
        }

        $this->resetErrorBag($property);
        $this->addError($property, ImageUpload::oversizedMessage());
    }

    /**
     * The upload itself was REFUSED — it left the picker and never landed.
     *
     * `$wire.upload()` takes an error callback and, called without one, a
     * failed round trip changes nothing on the screen at all: the picker
     * goes quiet, the property is never set, `updated…` never runs, and the
     * rejection escapes to the window, where the reporter can only record
     * it as "[object Object]" with no stack. A reader watching that has no
     * way to tell it apart from a success.
     *
     * Same error bag key as the size gate above, so the ONE `<flux:error>`
     * beside the control renders every refusal and the reader never learns
     * there are three gates. The message names no cause on purpose: the
     * browser knows the upload did not finish and nothing else, and a
     * guessed reason is a default written where there is no data.
     */
    public function reportRefusedUpload(string $property): void
    {
        if (! property_exists($this, $property)) {
            return;
        }

        $this->resetErrorBag($property);
        $this->addError($property, Voice::line('uploads.refused'));
    }
}

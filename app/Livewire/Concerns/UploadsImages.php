<?php

namespace App\Livewire\Concerns;

use App\Support\ImageUpload;

/**
 * The Livewire half of a guarded image upload: the door the browser knocks
 * on when it has refused a file itself.
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
     * never learns there are two gates.
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
}

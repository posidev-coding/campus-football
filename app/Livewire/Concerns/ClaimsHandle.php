<?php

namespace App\Livewire\Concerns;

use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;

/**
 * The claim moment, shared by every surface that can trigger it.
 *
 * A handle is claimed at the first PARTICIPATION — the first pick or the
 * first post, the seam product.md reserved — which means two unrelated
 * screens raise the same form. The rules live here once so they cannot
 * drift: a regex that tightened on the pick surface but not the
 * conversation would mint handles one Action then rejects.
 *
 * The host owns the copy, because "you can pick now" and "you can talk
 * now" are different sentences, and claiming returns the handle so the
 * host can say its own.
 */
trait ClaimsHandle
{
    public string $handle = '';

    #[Computed]
    public function needsHandle(): bool
    {
        return auth()->user()?->handle === null;
    }

    /**
     * Validate, claim, and hand the host the handle it just took.
     */
    protected function claimHandle(): string
    {
        $validated = $this->validate([
            'handle' => [
                'required', 'string', 'min:3', 'max:20',
                'regex:/^[a-z0-9_]+$/',
                Rule::unique('users')->ignore(auth()->id()),
            ],
        ], [
            'handle.regex' => 'Handles use lowercase letters, numbers and underscores.',
        ]);

        auth()->user()->update(['handle' => $validated['handle']]);

        unset($this->needsHandle);

        return $validated['handle'];
    }
}

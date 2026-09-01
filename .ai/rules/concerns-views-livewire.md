---
paths:
  - 'resources/views/components/image-file-input.blade.php,app/Support/ImageUpload.php,app/Livewire/Concerns/UploadsImages.php,resources/views/livewire/**'
---

# Concerns Views Livewire

## Never bind a file input with wire:model — measure the size in the browser
`wire:model` on a file input uploads whatever the picker hands it, and a server-side `max:` rule CANNOT catch an oversized file. PHP discards a request body over `post_max_size` and the CSRF token goes with it, so the upload endpoint answers with an HTML error page: Livewire fails to JSON.parse it and the reader gets a browser alert reading "The page has expired", with no mention of a file. Hit in production 2026-09-01 on the group icon with a 22MB PNG; reproduced on a stock checkout, because the cliff is PHP's own default and is in every environment.

Every image upload therefore goes through `<x-image-file-input property="..." label="...">`, which measures `file.size` first and only then calls `$wire.upload()`. Oversized files never become a request; the browser knocks on `rejectOversizedImage` (the `UploadsImages` concern) so one `<flux:error>` renders both gates.

ONE NUMBER: `App\Support\ImageUpload::MAX_KB` is what the rule states AND what the browser compares against — a trait constant will not do, PHP 8.4 forbids reading one directly. `ImageUpload::rules()` stays as the backstop for anything reaching PHP anyway.

OversizedImageTest sweeps every Blade view for a file input carrying `wire:model` and reds on any, and asserts the rendered browser threshold equals MAX_KB. Do not "simplify" the control back to `wire:model`.

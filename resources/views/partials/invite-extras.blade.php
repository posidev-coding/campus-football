{{--
    THE REST OF AN INVITE — the square you point a phone at, and the
    message you actually send.

    Included INSIDE x-invite-panel's x-data element by both variants, so it
    reads `copyTemplate()` and `copiedTemplate` off the panel's own scope
    rather than opening a second one. Never include it anywhere else: on
    its own it is a ReferenceError with no element attached, which Alpine
    reports against whatever page the reader happens to be standing on.

    Expects `$url` and `$templates` from the host component.
--}}

@php
    use App\Support\QrCode;
    use App\Support\Voice;
@endphp

<div class="flex flex-col gap-4 border-t border-zinc-100 pt-3 dark:border-zinc-800/60">
    {{-- The white plate is deliberate and stays white in dark mode: many
         phone cameras will not read a light-on-dark QR at all, and the
         failure looks like a broken code rather than a theming choice. --}}
    <div class="flex flex-col items-center gap-2">
        <div class="rounded-lg bg-white p-2">
            <div class="w-36 sm:w-40">{!! QrCode::svg($url) !!}</div>
        </div>
        <p class="text-micro text-zinc-400">{{ Voice::line('groups.invite.qr_hint') }}</p>
    </div>

    @if ($templates !== [])
        <div class="flex flex-col gap-2">
            <p class="text-micro font-medium uppercase tracking-wide text-zinc-400">Ready to send</p>

            @foreach ($templates as $template)
                <div
                    wire:key="invite-template-{{ $template['key'] }}"
                    class="flex flex-col gap-2 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700"
                >
                    <div class="flex items-center gap-2">
                        {{-- min-w-0 or the nowrap hint keeps its whole
                             min-content width and pushes the row sideways. --}}
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm font-bold leading-tight">{{ $template['label'] }}</span>
                            <span class="block truncate pt-0.5 text-xs text-zinc-500 dark:text-zinc-400">{{ $template['hint'] }}</span>
                        </span>

                        {{-- A plain key, never the body: a Blade directive
                             in a component tag's attribute never compiles
                             and the handler would be silently inert. --}}
                        <flux:button x-on:click="copyTemplate('{{ $template['key'] }}')" size="xs" variant="ghost" class="shrink-0">
                            <span x-show="copiedTemplate !== '{{ $template['key'] }}'">Copy</span>
                            <span x-show="copiedTemplate === '{{ $template['key'] }}'" x-cloak>Copied</span>
                        </flux:button>
                    </div>

                    {{-- Scrolls DOWN, never sideways: whitespace-pre-wrap
                         keeps the line breaks the message needs and wraps
                         the long ones. --}}
                    <p class="max-h-28 overflow-y-auto whitespace-pre-wrap break-words text-xs leading-relaxed text-zinc-500 dark:text-zinc-400">{{ $template['body'] }}</p>
                </div>
            @endforeach

            <p class="text-micro text-zinc-400">{{ Voice::line('groups.invite.templates_hint') }}</p>
        </div>
    @endif
</div>

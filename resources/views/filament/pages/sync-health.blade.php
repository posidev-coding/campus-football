<x-filament-panels::page>
    {{-- Request spend: context first, so a red row below is read against the budget. --}}
    <div class="grid grid-cols-2 gap-4">
        <x-filament::section>
            <div class="text-sm text-gray-500 dark:text-gray-400">ESPN requests · 24h</div>
            <div class="text-2xl font-semibold tabular-nums">{{ number_format($spendDay) }}</div>
            <div class="text-xs text-gray-400">budget 240/min — steady state is ~1,600 a week</div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-sm text-gray-500 dark:text-gray-400">ESPN requests · 7d</div>
            <div class="text-2xl font-semibold tabular-nums">{{ number_format($spendWeek) }}</div>
            <div class="text-xs text-gray-400">from the feed-run ledger, 14-day retention</div>
        </x-filament::section>
    </div>

    {{-- Coverage: expected vs actual, the "reported success and wrote nothing" catcher. --}}
    <x-filament::section>
        <x-slot name="heading">Data coverage</x-slot>
        <x-slot name="description">Expected against actual, shared verbatim with <code>php artisan cfb:doctor</code>.</x-slot>

        <div class="divide-y divide-gray-200 dark:divide-white/10">
            @foreach ($checks as $check)
                <div class="flex items-center gap-3 py-2">
                    <x-filament::badge :color="match ($check['status']) {
                        'ok' => 'success',
                        'warn' => 'warning',
                        default => 'danger',
                    }">
                        {{ strtoupper($check['status']) }}
                    </x-filament::badge>

                    <div class="min-w-0 flex-1">
                        <div class="text-sm font-medium">{{ $check['label'] }}</div>
                        <div class="truncate text-xs text-gray-500 dark:text-gray-400">{{ $check['detail'] }}</div>
                    </div>

                    @if ($check['status'] !== 'ok' && $check['remedy'])
                        <code class="shrink-0 text-xs text-gray-500 dark:text-gray-400">php artisan {{ $check['remedy'] }}</code>
                    @endif
                </div>
            @endforeach
        </div>
    </x-filament::section>

    {{-- The schedule, introspected — never a second registry. --}}
    <x-filament::section>
        <x-slot name="heading">Scheduled tasks</x-slot>
        <x-slot name="description">Every cfb entry in the schedule, with its latest ledger row. Gated tasks are outside their season or window.</x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-gray-500 dark:text-gray-400">
                        <th class="py-1.5 pe-3 font-medium">Task</th>
                        <th class="py-1.5 pe-3 font-medium">Cadence</th>
                        <th class="py-1.5 pe-3 font-medium">Last run</th>
                        <th class="py-1.5 pe-3 text-right font-medium">Records</th>
                        <th class="py-1.5 pe-3 text-right font-medium">Requests</th>
                        <th class="py-1.5 text-right font-medium">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                    @foreach ($tasks as $task)
                        <tr @class(['opacity-50' => $task['gated']])>
                            <td class="py-1.5 pe-3 font-mono text-xs">{{ $task['name'] }}</td>
                            <td class="py-1.5 pe-3 whitespace-nowrap text-xs text-gray-500 dark:text-gray-400">{{ $task['cadence'] }}</td>
                            <td class="py-1.5 pe-3 whitespace-nowrap text-xs">
                                {{ $task['run']?->started_at?->diffForHumans() ?? '—' }}
                            </td>
                            <td class="py-1.5 pe-3 text-right tabular-nums text-xs">{{ $task['run']?->records ?? '—' }}</td>
                            <td class="py-1.5 pe-3 text-right tabular-nums text-xs">{{ $task['run']?->requests ?? '—' }}</td>
                            <td class="py-1.5 text-right">
                                @if ($task['gated'])
                                    <x-filament::badge color="gray">gated</x-filament::badge>
                                @elseif ($task['run']?->status === 'failed')
                                    <x-filament::badge color="danger">failed</x-filament::badge>
                                @elseif ($task['overdue'])
                                    <x-filament::badge color="warning">overdue</x-filament::badge>
                                @elseif ($task['run'] === null)
                                    <x-filament::badge color="gray">untracked</x-filament::badge>
                                @else
                                    <x-filament::badge color="success">ok</x-filament::badge>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>

    {{-- Failures verbatim: the error text is the whole point of keeping them. --}}
    <x-filament::section>
        <x-slot name="heading">Recent failures</x-slot>
        <x-slot name="description">
            Failed feed runs from the last fortnight. On Laravel Cloud, failed QUEUE jobs live in the
            Cloud dashboard's Queues tab — this ledger covers the scheduled commands themselves.
        </x-slot>

        @forelse ($failures as $failure)
            <div class="border-b border-gray-200 py-2 last:border-0 dark:border-white/10">
                <div class="flex items-baseline gap-2">
                    <span class="font-mono text-xs">{{ $failure->command }}</span>
                    <span class="text-xs text-gray-500 dark:text-gray-400">{{ $failure->started_at->diffForHumans() }}</span>
                </div>
                <div class="mt-0.5 text-xs text-red-600 dark:text-red-400">{{ Str::limit($failure->error, 300) }}</div>
            </div>
        @empty
            <div class="text-sm text-gray-500 dark:text-gray-400">Nothing has failed in the last fortnight.</div>
        @endforelse
    </x-filament::section>
</x-filament-panels::page>

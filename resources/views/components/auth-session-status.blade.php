@props(['status' => null])

@if ($status)
    <div {{ $attributes->merge(['class' => 'text-sm font-medium text-emerald-600 dark:text-emerald-400']) }}>
        {{ $status }}
    </div>
@endif

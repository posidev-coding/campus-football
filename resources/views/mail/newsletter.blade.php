@php
    use App\Support\Voice;
    use App\Support\WeeklyDigest;
@endphp

<x-mail::message :unsubscribeUrl="$unsubscribeUrl" unsubscribeLabel="Stop the weekly email">
# {{ $user->first_name }},

{{-- The reader's own week, written in their own register — or the copy that
     has always been here. `$recap` is null on every failure the model call can
     have, and null is the DEFAULT rather than the error: this half of the
     email must read as finished whether or not anything generated it. --}}
@if ($recap ?? null)
## {{ $recap['headline'] }}

@foreach ($recap['body'] as $paragraph)
{{ $paragraph }}

@endforeach
@else
{{ $digest['has_results']
    ? Voice::line('mail.newsletter.intro', for: $user)
    : Voice::line('mail.newsletter.empty', for: $user) }}
@endif

@foreach ($digest['teams'] as $row)
@php($team = $row['team'])
## {{ $row['rank'] ? '#'.$row['rank'].' ' : '' }}{{ $team->placeName() }}@if ($row['record']) ({{ $row['record'] }})@endif

@if ($row['result'])
**{{ WeeklyDigest::describe($row['result'], $team) }}**
@endif

@if ($row['next'])
{{-- The date carries the reader's own timezone, not the server's. A kickoff
     stated an hour out is worse than no kickoff at all: it is wrong in a way
     that looks authoritative. --}}
Next: {{ $row['next']->awayTeam?->placeName() ?? 'TBD' }} at {{ $row['next']->homeTeam?->placeName() ?? 'TBD' }},
{{ $row['next']->kickoff_at?->setTimezone($user->timezone)->format('D j M, g:ia') }}
@else
No game scheduled yet.
@endif

@endforeach

<x-mail::button :url="route('home')">
See your teams
</x-mail::button>

@if (! $digest['teams'])
You are not following any teams yet, so there is nothing to report. Pick one and
this email starts being about somebody.
@endif
</x-mail::message>

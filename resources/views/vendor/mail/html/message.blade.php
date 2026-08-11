<x-mail::layout>
{{-- Header --}}
<x-slot:header>
{{-- The slot is unused by our header, which draws the lockup itself, but the
     component still requires one. --}}
<x-mail::header :url="config('app.url')">
{{ App\Support\Brand::name() }}
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
© {{ date('Y') }} {{ App\Support\Brand::name() }}
{{-- Only mail somebody opted into carries an unsubscribe line. A password
     reset must never offer one: it is transactional, it is not a list, and
     an unsubscribe control on it invites turning off the one email that
     gets an account back. --}}
@isset($unsubscribeUrl)
<br><a href="{{ $unsubscribeUrl }}">{{ $unsubscribeLabel ?? 'Stop these emails' }}</a>
@endisset
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>

{{--
    Maintenance mode. Reload is the only honest exit — every in-app link
    answers 503 until the deploy finishes, so offering Home would just be
    this page again with an extra tap in between.
--}}
@extends('errors.layout')

@section('title', 'Be right back')
@section('headline', 'Halftime.')
@section('message', "We're down for quick maintenance and will be back before you miss anything. Sit tight.")

@section('actions')
    <button type="button" class="action" onclick="location.reload()">Try again</button>
@endsection

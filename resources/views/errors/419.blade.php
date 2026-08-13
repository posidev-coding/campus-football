{{--
    The stale-tab page, and in an installed app the likeliest of the five: a
    session that sat on a home screen for days, then submitted a plain form
    (the logout POST). Nothing was lost — the only fix is a fresh page, so
    the exit says exactly that.
--}}
@extends('errors.layout')

@section('title', 'Page expired')
@section('headline', 'This page sat out too long.')
@section('message', 'It expired while you were away — nothing was lost. Head back in and try that again.')

@section('actions')
    <a class="action" href="{{ route('home') }}">Keep going</a>
@endsection

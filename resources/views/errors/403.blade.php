@extends('errors.layout')

@section('title', 'No access')
@section('headline', 'This page is off-limits.')
@section('message', "Your account can't see this one. The rest of the app is still wide open.")

@section('actions')
    <a class="action" href="{{ route('home') }}">Take me home</a>
@endsection

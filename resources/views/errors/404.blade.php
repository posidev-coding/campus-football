@extends('errors.layout')

@section('title', 'Not found')
@section('headline', "This one's out of bounds.")
@section('message', 'Whatever lived at this address has moved, expired or never existed. Everything else is right where you left it.')

@section('actions')
    <a class="action" href="{{ route('home') }}">Take me home</a>
@endsection

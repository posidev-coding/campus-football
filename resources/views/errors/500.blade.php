@extends('errors.layout')

@section('title', 'Something broke')
@section('headline', 'We fumbled that one.')
@section('message', "Something broke on our end, and it's already been flagged. Give it another try in a moment.")

@section('actions')
    <button type="button" class="action" onclick="location.reload()">Try again</button>
    <a class="quiet" href="{{ route('home') }}">or head home</a>
@endsection

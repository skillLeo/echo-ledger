@extends('share.layout')

@section('title', 'Not shared with you')

@section('content')
    <div class="card">
        <h1>This link was not shared with you</h1>
        <p class="muted">You are signed in as {{ $email }}, which is not one of the people it was shared with. Ask the person who shared it to add this email, or sign in with the one they used.</p>
    </div>
@endsection

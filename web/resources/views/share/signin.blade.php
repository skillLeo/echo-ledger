@extends('share.layout')

@section('title', 'Sign in to open this link')

@section('content')
    <div class="card">
        <h1>Sign in to open this link</h1>
        <p class="muted">It was shared with named people. Sign in to EchoLedger with the email it was shared with, and it opens.</p>
        <p class="actions"><a class="button" href="{{ $loginUrl }}">Sign in</a></p>
    </div>
@endsection

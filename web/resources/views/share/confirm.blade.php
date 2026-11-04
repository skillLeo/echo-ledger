@extends('share.layout')

@section('title', 'Confirm your email')

@section('content')
    <div class="card">
        <h1>Confirm your email to open this link</h1>
        <p class="muted">It was shared with {{ $email }}. Before it opens, show that this address is yours: EchoLedger sends a link to it, and opening that link brings you back here.</p>
        <form method="post" action="{{ $action }}">
            @csrf
            <button type="submit">Send me the link</button>
        </form>
    </div>
@endsection

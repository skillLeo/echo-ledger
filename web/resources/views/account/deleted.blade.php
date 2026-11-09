@extends('site.layout')

@section('title', 'Account deletion scheduled · EchoLedger')

@section('content')
    @include('auth.form-styles')
    <div class="account">
        <h1>Your account will be deleted</h1>
        <div class="card">
            <p style="margin: 0;">It is erased on <strong>{{ $date }}</strong>, with everything only it owns. You are signed out everywhere, and the email with this date is on its way.</p>
            <p class="hint">Changed your mind? Sign in before then and choose "Keep my account".</p>
            <div class="actions"><a class="button secondary" href="{{ url('/app/login') }}">Sign in</a></div>
        </div>
    </div>
@endsection

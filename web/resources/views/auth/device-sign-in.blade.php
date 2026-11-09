@extends('site.layout')

@section('title', 'Sign in a device · EchoLedger')

@section('content')
    @include('auth.form-styles')
    <div class="account">
        <h1>Sign in EchoLedger on {{ $params['name'] }}?</h1>
        <form method="post" action="{{ route('device.sign-in.approve') }}" class="card">
            @csrf
            @foreach (['code_challenge', 'code_challenge_method', 'state', 'redirect_uri', 'name', 'platform'] as $field)
                <input type="hidden" name="{{ $field }}" value="{{ $params[$field] }}">
            @endforeach
            <p style="margin: 0;">The EchoLedger app on <strong>{{ $params['name'] }}</strong> ({{ $platform }}) will sign in as <strong>{{ $email }}</strong>@if ($workspace) and record into <strong>{{ $workspace }}</strong>@endif.</p>
            <p class="hint">Approve only if you started this sign-in in the app yourself, just now. You can revoke the device at any time under Account → Devices.</p>
            <div class="actions">
                <button type="submit" class="button">Sign in</button>
                <button type="submit" name="cancel" value="1" class="button secondary">Cancel</button>
            </div>
        </form>
        <form method="post" action="{{ route('filament.app.auth.logout') }}" style="margin-top: 12px;">
            @csrf
            <button type="submit" style="background: none; border: 0; padding: 0; color: var(--accent); font: inherit; cursor: pointer; text-decoration: underline;">Not you? Use another account</button>
        </form>
    </div>
@endsection

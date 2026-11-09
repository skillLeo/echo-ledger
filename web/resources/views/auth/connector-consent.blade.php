@extends('site.layout')

@section('title', 'Connect an app · EchoLedger')

@section('content')
    @include('auth.form-styles')
    <div class="account">
        <h1>Allow {{ $name }} to read your EchoLedger recordings?</h1>
        <div class="card">
            <p style="margin: 0;"><strong>{{ $name }}</strong> will read as <strong>{{ $email }}</strong>@if ($workspace) in <strong>{{ $workspace }}</strong>@endif:</p>
            <ul style="margin: 0; padding-left: 20px;">
                @foreach ($scopes as $scope)
                    <li>{{ $scope }}</li>
                @endforeach
                <li>Nothing of other people, unless they sent it to you.</li>
            </ul>
            @if ($hosts !== [])
                <p class="hint">After you choose, the browser goes back to <strong>{{ implode(', ', $hosts) }}</strong>.</p>
            @endif
            <p class="hint">Allow only if you are adding EchoLedger in Claude or ChatGPT yourself, just now. You can disconnect it at any time under Profile → Connected apps.</p>
            <div class="actions">
                <form method="post" action="{{ route('passport.authorizations.approve') }}">
                    @csrf
                    <input type="hidden" name="auth_token" value="{{ $authToken }}">
                    <button type="submit" class="button">Allow</button>
                </form>
                <form method="post" action="{{ route('passport.authorizations.deny') }}">
                    @csrf
                    @method('DELETE')
                    <input type="hidden" name="auth_token" value="{{ $authToken }}">
                    <button type="submit" class="button secondary">Cancel</button>
                </form>
            </div>
        </div>
        <form method="post" action="{{ route('filament.app.auth.logout') }}" style="margin-top: 12px;">
            @csrf
            <button type="submit" style="background: none; border: 0; padding: 0; color: var(--accent); font: inherit; cursor: pointer; text-decoration: underline;">Not you? Use another account</button>
        </form>
    </div>
@endsection

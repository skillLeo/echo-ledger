@extends('site.layout')

@section('title', 'Delete your account · EchoLedger')

@section('content')
    @include('auth.form-styles')
    <div class="account">
        <h1>Delete your account</h1>
        @if ($user === null)
            <div class="card">
                <p style="margin: 0;">Deleting your EchoLedger account erases, after 7 days, everything only it owns: your recordings with their audio, transcripts and screens, your projects with their files and notes, and your devices. Recordings you sent to teammates stay in their libraries.</p>
                <p class="hint">Until then you can change your mind: sign in and choose "Keep my account". To keep a copy first, use Profile → Export my data in the console.</p>
                <div class="actions"><a class="button" href="{{ route('account.delete', ['sign_in' => 1]) }}">Sign in to continue</a></div>
            </div>
        @elseif ($user->isBeingDeleted())
            <div class="card">
                <p style="margin: 0;">The account <strong>{{ $user->email }}</strong> will be erased on <strong>{{ $date }}</strong>.</p>
                <p class="hint">Changed your mind? Open the console and choose "Keep my account".</p>
                <div class="actions"><a class="button secondary" href="{{ url('/admin') }}">Open the console</a></div>
            </div>
        @else
            <form method="post" action="{{ route('account.delete.store') }}" class="card">
                @csrf
                <p style="margin: 0;">You are signed in as <strong>{{ $user->email }}</strong>. In 7 days this account is erased with its recordings, transcripts, screens, files, projects and devices. You are signed out everywhere now.</p>
                @if ($user->hasTwoFactor())
                    <label>Code from your authenticator app, or a recovery code
                        <input type="text" name="code" autocomplete="one-time-code" required maxlength="64">
                    </label>
                @else
                    <label>Your password
                        <input type="password" name="password" autocomplete="current-password" required>
                    </label>
                @endif
                <label class="check"><input type="checkbox" name="understood" value="1" required> <span>I understand that after 7 days this cannot be undone.</span></label>
                @if (! empty($error))
                    <p class="error" role="alert">{{ $error }}@if (! empty($workspaceUrl)) <a href="{{ $workspaceUrl }}">Open the Workspace page</a>@endif</p>
                @endif
                @if ($errors->any())
                    <p class="error" role="alert">{{ $errors->first() }}</p>
                @endif
                <div class="actions">
                    <button type="submit" class="button danger">Delete my account</button>
                    <a href="{{ url('/admin') }}">Cancel</a>
                </div>
            </form>
        @endif
    </div>
@endsection

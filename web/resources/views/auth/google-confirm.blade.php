@extends('site.layout')

@section('title', 'Confirm it is you · EchoLedger')

@section('content')
    @include('auth.form-styles')
    <div class="account">
        <h1>{{ $link ? 'Link Google to your account' : 'One more step' }}</h1>
        <form method="post" action="{{ route('auth.google.confirm.store') }}" class="card">
            @csrf
            @if ($link)
                <p style="margin: 0;">An EchoLedger account already uses <strong>{{ $email }}</strong>. To sign in with Google from now on, confirm it is yours.</p>
            @else
                <p style="margin: 0;">Your account <strong>{{ $email }}</strong> has two-step sign-in.</p>
            @endif
            @if ($twoFactor)
                <label>Code from your authenticator app, or a recovery code
                    <input type="text" name="code" inputmode="text" autocomplete="one-time-code" required autofocus maxlength="64">
                </label>
            @else
                <label>Your EchoLedger password
                    <input type="password" name="password" autocomplete="current-password" required autofocus>
                </label>
            @endif
            @if (! empty($error))
                <p class="error" role="alert">{{ $error }}</p>
            @endif
            <div class="actions">
                <button type="submit" class="button">{{ $link ? 'Link and sign in' : 'Sign in' }}</button>
                <a href="{{ url('/admin/login') }}">Cancel</a>
            </div>
        </form>
    </div>
@endsection

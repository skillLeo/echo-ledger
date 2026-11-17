{{-- An invite link's page (v1.8 P6.3, JoinController): "Join Acme on EchoLedger". --}}
@extends('site.layout')

@section('title', 'Join '.$workspace.' on '.$brand)

@section('content')
    @include('auth.form-styles')
    <div class="account">
        <h1>Join {{ $workspace }} on {{ $brand }}</h1>
        <div class="card">
            <p style="margin: 0;">
                @if ($inviter)<strong>{{ $inviter }}</strong> invited you to <strong>{{ $workspace }}</strong>@else You were invited to <strong>{{ $workspace }}</strong>@endif
                as {{ $role }}.
            </p>
            <p class="hint">What you record stays yours: nobody in the team sees it unless you share it. Your own workspace stays private too.</p>
            @if ($user && $member)
                <p style="margin: 0;">You are in {{ $workspace }} already, signed in as <strong>{{ $user->email }}</strong>.</p>
                <div class="actions"><a class="button" href="{{ url('/app') }}">Open {{ $workspace }}</a></div>
            @elseif ($user)
                <form method="post" action="{{ $action }}">
                    @csrf
                    <p style="margin: 0;">Signed in as <strong>{{ $user->email }}</strong>.</p>
                    <div class="actions"><button type="submit" class="button">Join {{ $workspace }}</button></div>
                </form>
            @else
                <div class="actions">
                    @if ($signUp)<a class="button" href="{{ $signUp }}">Create your account</a>@endif
                    <a class="button {{ $signUp ? 'secondary' : '' }}" href="{{ $signIn }}">I have an account: sign in</a>
                </div>
            @endif
        </div>
    </div>
@endsection

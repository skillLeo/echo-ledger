@extends('site.layout')

@section('title', 'Join '.$workspace.' · EchoLedger')

@section('content')
    @include('auth.form-styles')
    <div class="account">
        <h1>Join {{ $workspace }}?</h1>
        <form method="post" action="{{ $action }}" class="card">
            @csrf
            <p style="margin: 0;">You were invited to <strong>{{ $workspace }}</strong> as {{ \Illuminate\Support\Str::lower($role) }}, signed in as <strong>{{ $email }}</strong>.</p>
            <p class="hint">What you record stays yours: nobody in the workspace sees it unless you share it.</p>
            <div class="actions">
                <button type="submit" class="button">Join</button>
            </div>
        </form>
    </div>
@endsection

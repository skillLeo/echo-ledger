@extends('site.layout')

@section('title', $title.' · EchoLedger')

@section('content')
    @include('auth.form-styles')
    <div class="account">
        <h1>{{ $title }}</h1>
        <div class="card">
            <p style="margin: 0;">{{ $message }}</p>
            @if (! empty($back))
                <div class="actions"><a class="button secondary" href="{{ $back }}">{{ $backLabel ?? 'Back to sign in' }}</a></div>
            @endif
        </div>
    </div>
@endsection

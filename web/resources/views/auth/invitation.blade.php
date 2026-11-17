@extends('site.layout')

@section('title', 'Join '.$workspace.' on '.\App\Support\Brand::name())

@section('content')
    @include('auth.form-styles')
    <div class="account">
        <h1>Join {{ $workspace }} on {{ \App\Support\Brand::name() }}</h1>
        <form method="post" action="{{ $action }}" class="card">
            @csrf
            <p style="margin: 0;">@if ($inviter ?? null)<strong>{{ $inviter }}</strong> invited you to @else You were invited to @endif<strong>{{ $workspace }}</strong> as {{ \Illuminate\Support\Str::lower($role) }}, signed in as <strong>{{ $email }}</strong>.</p>
            <p class="hint">What you record stays yours: nobody in the workspace sees it unless you share it.</p>
            <div class="actions">
                <button type="submit" class="button">Join {{ $workspace }}</button>
            </div>
        </form>
    </div>
@endsection

@extends('share.layout')

@section('title', 'Password needed')

@section('content')
    <div class="card">
        <h1>This transcript is protected</h1>
        <p class="muted">Enter the password you were given to read it.</p>
        <form method="post" action="{{ url('/s/'.$token) }}">
            @csrf
            <label for="password" class="muted">Password</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required autofocus>
            @if ($error)
                <p class="error" role="alert">{{ $error }}</p>
            @endif
            <button type="submit">Open transcript</button>
        </form>
    </div>
@endsection
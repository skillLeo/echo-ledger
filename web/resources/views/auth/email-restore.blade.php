@extends('site.layout')

@section('title', 'Put your address back · '.\App\Support\Brand::name())

@section('content')
    @include('auth.form-styles')
    <div class="account">
        <h1>Put your address back?</h1>
        <form class="card" method="post" action="{{ $action }}">
            @csrf
            <p style="margin: 0;">The account goes back to {{ $address }}, and every phone, computer and browser signed in to it is signed out. Then choose a new password with "Forgot password?" on the sign-in page.</p>
            <div class="actions"><button type="submit" class="button danger">Put it back and sign out everywhere</button></div>
        </form>
    </div>
@endsection

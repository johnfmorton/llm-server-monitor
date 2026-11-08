@extends('layouts.app')

@section('title', 'Log in')

@section('content')
    <form method="POST" action="{{ route('login.store') }}" class="login">
        @csrf
        <h1>Log in</h1>

        <label for="email">Email</label>
        <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username">

        <label for="password">Password</label>
        <input id="password" name="password" type="password" required autocomplete="current-password">

        <label class="check"><input type="checkbox" name="remember" value="1"> Keep me logged in</label>

        @error('email')
            <p class="error" role="alert">{{ $message }}</p>
        @enderror

        <button type="submit">Log in</button>
    </form>
@endsection

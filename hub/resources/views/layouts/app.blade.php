<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title') · LogWatch</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
</head>
<body>
    <header class="topbar">
        <a href="{{ route('dashboard') }}" class="brand">LogWatch</a>
        @auth
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="link-button">Log out</button>
            </form>
        @endauth
    </header>
    <main class="page">
        @yield('content')
    </main>
</body>
</html>

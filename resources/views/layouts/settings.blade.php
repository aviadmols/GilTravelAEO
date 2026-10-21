<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title')</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Heebo:wght@300;400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('report.css') }}?v={{ filemtime(public_path('report.css')) }}">
</head>
<body>
<header class="masthead">
    <div class="container">
        <div class="eyebrow">@yield('eyebrow')</div>
        <h1 class="display">@yield('heading')</h1>
    </div>
</header>

<main class="container"><div class="settings">
    @if (session('status'))
        <div class="notice notice--ok" role="status">{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div class="notice notice--error" role="status">{{ session('error') }}</div>
    @endif

    @yield('content')
</div></main>
</body>
</html>

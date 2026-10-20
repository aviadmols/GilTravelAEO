<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Report Settings</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Heebo:wght@300;400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('report.css') }}?v={{ filemtime(public_path('report.css')) }}">
</head>
<body>
<header class="masthead">
    <div class="container">
        <div class="eyebrow"><a href="{{ route('report') }}" class="back">← Back to report</a></div>
        <h1 class="display">Settings</h1>
    </div>
</header>

<main class="container"><div class="settings">
    @if (session('status'))
        <div class="notice notice--ok" role="status">{{ session('status') }}</div>
    @endif

    <section class="panel">
        <h2 class="panel__title">HubSpot key</h2>
        <p class="hint">
            A HubSpot Service Key with the scopes <code>marketing.aeo.read</code>, <code>crm.objects.contacts.read</code>,
            <code>crm.objects.deals.read</code>, <code>forms</code> and <code>business-intelligence</code>.
            It is stored encrypted and never shown again.
        </p>

        <p class="key-status">
            @if ($saved)
                Saved key ending in <strong>…{{ $savedTail }}</strong>, updated {{ $saved->updated_at->diffForHumans() }}.
            @elseif ($fromEnv)
                Using the key from the <code>HUBSPOT_TOKEN</code> variable. A key saved here replaces it.
            @else
                No key yet.
            @endif
        </p>

        <form method="post" action="{{ route('settings') }}" class="key-form">
            @csrf
            <label class="field">
                <span class="field__label">{{ $saved ? 'Replace key' : 'Key' }}</span>
                <input type="password" name="hubspot_token" autocomplete="off" spellcheck="false" placeholder="pat-…" required>
            </label>
            @error('hubspot_token')<p class="field__error">{{ $message }}</p>@enderror
            <button type="submit" class="button">Save key</button>
        </form>

        @if ($saved)
            <form method="post" action="{{ route('settings') }}" class="remove-form">
                @csrf
                <input type="hidden" name="remove" value="1">
                <button type="submit" class="link-button" onclick="return confirm('Remove the saved HubSpot key?')">Remove saved key</button>
            </form>
        @endif
    </section>
</div></main>
</body>
</html>

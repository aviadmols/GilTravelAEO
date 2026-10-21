@extends('layouts.settings')

@section('title', 'Report Settings')
@section('eyebrow')<a href="{{ route('report') }}" class="back">← Back to report</a>@endsection
@section('heading', 'Settings')

@section('content')
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

    <section class="panel">
        <h2 class="panel__title">Google Search Console</h2>

        <p class="key-status">
            @if ($gsc['connected'])
                Connected as <strong>{{ $gsc['account'] ?? 'a Google account' }}</strong>,
                @if ($gsc['site'])
                    reading <strong>{{ $gsc['site'] }}</strong>.
                @else
                    no property chosen yet.
                @endif
                <a href="{{ route('google.property') }}">{{ $gsc['site'] ? 'Change property' : 'Choose property' }}</a>
            @elseif ($gsc['fromEnv'])
                Using the service account from the <code>GOOGLE_SERVICE_ACCOUNT_JSON</code> variable. A Google account connected here replaces it.
            @else
                Not connected yet.
            @endif
        </p>

        @if (! $gsc['ready'])
            <p class="hint">
                To connect with Google, create an OAuth client (type <em>Web application</em>) in Google Cloud and set
                <code>GOOGLE_CLIENT_ID</code> and <code>GOOGLE_CLIENT_SECRET</code> in Railway. Its authorized redirect URI:
            </p>
            <label class="field">
                <span class="field__label">Authorized redirect URI</span>
                <input type="text" value="{{ $gsc['callbackUrl'] }}" readonly onclick="this.select()">
            </label>
        @else
            <p class="hint">
                Connect with the Google account that has access to the property, or send the link below to whoever manages
                Search Console. The link works for 7 days and only lets them connect Search Console; it does not open the report.
            </p>
            <label class="field">
                <span class="field__label">Link for the SEO manager</span>
                <input type="text" value="{{ $gsc['connectLink'] }}" readonly onclick="this.select()">
            </label>
            <a href="{{ $gsc['connectLink'] }}" class="button">{{ $gsc['connected'] ? 'Reconnect with Google' : 'Connect with Google' }}</a>
        @endif

        @if ($gsc['connected'])
            <form method="post" action="{{ route('google.disconnect') }}" class="remove-form">
                @csrf
                <button type="submit" class="link-button" onclick="return confirm('Disconnect Google Search Console?')">Disconnect Google</button>
            </form>
        @endif
    </section>
@endsection

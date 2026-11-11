{{-- Title, period and period buttons. Shared by the report and its loading page. --}}
@php
    $embedToken ??= null;
    $reportUrl = fn (array $query) => $embedToken ? route('embed', ['token' => $embedToken] + $query) : route('report', $query);
@endphp
<header class="masthead">
    <div class="container">
        <div class="eyebrow">Gil Travel</div>
        <h1 class="display">SEO AEO Report</h1>
        <p class="period-range">{{ $period->range() }} <span class="muted">compared with {{ $period->prevRange() }}</span></p>
        <nav class="periods" aria-label="Report period">
            @foreach (\App\Reports\ReportPeriod::PRESETS as $key => $label)
                <a href="{{ $reportUrl(['period' => $key]) }}" @class(['btn', 'btn--active' => $key === $period->key])>{{ $label }}</a>
            @endforeach
            @unless ($embedToken)
                <a href="{{ route('report', ['period' => $period->key, 'refresh' => 1]) }}" class="refresh" title="Fetch fresh numbers from HubSpot and Google">Refresh</a>
                <a href="{{ route('settings') }}" class="settings-link">Settings</a>
            @endunless
        </nav>
    </div>
</header>

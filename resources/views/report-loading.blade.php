<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Gil Travel SEO AEO Report</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Heebo:wght@300;400;500;600;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('report.css') }}?v={{ filemtime(public_path('report.css')) }}">
</head>
<body>
@include('partials.report-masthead')

<main class="container">
    <div class="loader" role="status" aria-live="polite">
        <div class="loader__spinner" aria-hidden="true"></div>
        <p class="loader__title">Loading the report…</p>
        <p class="loader__note muted">Fetching live numbers from HubSpot, Google Search Console and Ahrefs. This can take up to a minute.</p>
        <noscript><p><a href="?{{ http_build_query(request()->query() + ['content' => 1]) }}">Open the report</a></p></noscript>
    </div>

    <div class="loader-skeleton" aria-hidden="true">
        <div class="loader-skeleton__tiles"><span></span><span></span><span></span><span></span></div>
        <div class="loader-skeleton__panel"></div>
        <div class="loader-skeleton__panel"></div>
    </div>
</main>

<script>
    // Same URL with ?content=1 returns the full report, which replaces this page.
    (function () {
        const url = new URL(location.href);
        url.searchParams.set('content', '1');
        const fail = (text) => {
            document.querySelector('.loader').innerHTML =
                '<p class="loader__title">' + text + '</p><p><a href="' + location.href + '">Try again</a></p>';
        };
        fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'text/html' } })
            .then((r) => r.ok ? r.text() : Promise.reject(r.status))
            .then((html) => {
                document.open(); document.write(html); document.close();
                // A link like /#seo should still land on its section.
                if (location.hash) document.getElementById(location.hash.slice(1))?.scrollIntoView();
            })
            .catch((status) => fail(status === 401 ? 'Please sign in again.' : 'The report did not load.'));
    })();
</script>
</body>
</html>

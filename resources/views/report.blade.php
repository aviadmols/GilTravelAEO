@php
    use App\Reports\Fmt;
    use App\Reports\ReportPeriod;
    $aeo = $sections['aeo']; $seo = $sections['seo']; $traffic = $sections['traffic'];
    $leads = $sections['leads']; $revenue = $sections['revenue'];
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Gil Travel Report</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Heebo:wght@300;400;500;600;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('report.css') }}?v={{ filemtime(public_path('report.css')) }}">
</head>
<body>
<header class="masthead">
    <div class="container">
        <div class="eyebrow">Gil Travel</div>
        <h1 class="display">Marketing report</h1>
        <p class="period-range">{{ $period->range() }} <span class="muted">compared with {{ $period->prevRange() }}</span></p>
        <nav class="periods" aria-label="Report period">
            @foreach (ReportPeriod::PRESETS as $key => $label)
                <a href="{{ route('report', ['period' => $key]) }}" @class(['btn', 'btn--active' => $key === $period->key])>{{ $label }}</a>
            @endforeach
            <a href="{{ route('report', ['period' => $period->key, 'refresh' => 1]) }}" class="refresh" title="Fetch fresh numbers from HubSpot and Google">Refresh</a>
            <a href="{{ route('settings') }}" class="settings-link">Settings</a>
        </nav>
    </div>
</header>

<nav class="toc" aria-label="Sections">
    <div class="container toc__inner">
        <a href="#aeo">AI visibility</a><a href="#seo">SEO</a><a href="#traffic">Traffic</a><a href="#leads">Leads</a><a href="#revenue">Revenue</a>
    </div>
</nav>

<main>
{{-- ============ 01 AEO ============ --}}
<section id="aeo" class="section">
    <div class="container">
        <header class="section__head">
            <div>
                <h2 class="section__title">AI search visibility</h2>
                <p class="section__lede">How often ChatGPT, Gemini, Perplexity and other AI assistants mention Gil Travel when answering the tracked prompts.</p>
            </div>
        </header>

        @if (! $aeo['ok'])
            <x-section-error :message="$aeo['error']" />
        @elseif ($aeo['data']['runs'] === 0)
            <x-empty>No AI answers were collected in this period yet. HubSpot runs the tracked prompts about once a week, and they are synced here every morning.</x-empty>
        @else
            @php $a = $aeo['data']; $own = $a['own']; $prevOwn = $a['prev_own']; @endphp
            <div class="tiles">
                <x-tile hero label="Brand mention rate" :value="Fmt::pct($own['mention_rate'] ?? null)"
                        :delta="Fmt::delta($own['mention_rate'] ?? null, $prevOwn['mention_rate'] ?? null, 'rate')"
                        :note="'Share of AI answers that name Gil Travel (' . $a['analyzed_runs'] . ' answers)'" />
                <x-tile label="Share of voice" :value="Fmt::pct($own['share_of_voice'] ?? null)"
                        :delta="Fmt::delta($own['share_of_voice'] ?? null, $prevOwn['share_of_voice'] ?? null, 'rate')"
                        note="Gil Travel's share of all tracked-brand mentions" />
                <x-tile label="Rank among tracked brands" :value="isset($own['rank']) ? '#' . $own['rank'] . ' of ' . $a['brands_configured'] : '—'"
                        :delta="Fmt::delta($own['rank'] ?? null, $prevOwn['rank'] ?? null, 'position')" />
                <x-tile label="Citations of our pages" :value="Fmt::num($a['citations']['owned'])"
                        :delta="Fmt::delta($a['citations']['owned'], $a['citations']['prev_owned'])"
                        :note="'of ' . Fmt::num($a['citations']['total']) . ' sources cited in all answers'" />
                <x-tile label="Sentiment" value="—" note="HubSpot's AEO API does not return sentiment yet" />
            </div>

            @if ($a['brands_configured'] < 2)
                <div class="notice">Share of voice and rank need the competitor list. Add the six tracked competitors to <code>AEO_COMPETITORS</code> in Railway, separated by <code>;</code>.</div>
            @endif

            <div class="grid grid--2">
                <div class="panel">
                    <h3 class="panel__title">Tracked brands</h3>
                    @php $maxRate = collect($a['brands'])->max('mention_rate') ?: 0; @endphp
                    <table class="table">
                        <thead><tr><th>#</th><th>Brand</th><th class="num">Mention rate</th><th class="num">Share of voice</th><th class="num">Change</th></tr></thead>
                        <tbody>
                        @foreach (collect($a['brands'])->sortBy('rank') as $b)
                            @php $d = Fmt::delta($b['mention_rate'], $b['prev_mention_rate'], 'rate'); @endphp
                            <tr @class(['is-own' => $b['own']])>
                                <td class="num">{{ $b['rank'] ?? '—' }}</td>
                                <td>{{ $b['name'] }}</td>
                                <td class="num"><x-bar :value="$b['mention_rate'] ?? 0" :max="$maxRate" :label="Fmt::pct($b['mention_rate'])" /> {{ Fmt::pct($b['mention_rate']) }}</td>
                                <td class="num">{{ Fmt::pct($b['share_of_voice']) }}</td>
                                <td class="num">@if ($d)<span @class(['delta', 'delta--good' => $d['good'] === true, 'delta--bad' => $d['good'] === false])>{{ $d['text'] }}</span>@endif</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="panel">
                    <h3 class="panel__title">By AI assistant</h3>
                    @php $maxEngine = collect($a['engines'])->max('mention_rate') ?: 0; @endphp
                    <table class="table">
                        <thead><tr><th>Assistant</th><th class="num">Answers</th><th class="num">Mention rate</th><th class="num">Share of voice</th><th class="num">Our citations</th></tr></thead>
                        <tbody>
                        @foreach ($a['engines'] as $e)
                            <tr>
                                <td>{{ $e['engine'] }}</td>
                                <td class="num">{{ $e['runs'] }}</td>
                                <td class="num"><x-bar :value="$e['mention_rate'] ?? 0" :max="$maxEngine" :label="Fmt::pct($e['mention_rate'])" /> {{ Fmt::pct($e['mention_rate']) }}</td>
                                <td class="num">{{ Fmt::pct($e['share_of_voice']) }}</td>
                                <td class="num">{{ $e['owned_citations'] }} <span class="muted">/ {{ $e['citations'] }}</span></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="grid grid--2">
                <div class="panel">
                    <h3 class="panel__title">Most-cited domains</h3>
                    @forelse ($a['top_domains'] as $c)
                        @if ($loop->first)<table class="table"><thead><tr><th>Domain</th><th class="num">Citations</th><th class="num">Answers</th></tr></thead><tbody>@endif
                        <tr><td>{{ $c['label'] }}</td><td class="num">{{ $c['citations'] }}</td><td class="num">{{ $c['runs'] }}</td></tr>
                        @if ($loop->last)</tbody></table>@endif
                    @empty
                        <x-empty>No citations collected in this period.</x-empty>
                    @endforelse
                </div>
                <div class="panel">
                    <h3 class="panel__title">Most-cited pages</h3>
                    @forelse ($a['top_pages'] as $c)
                        @if ($loop->first)<table class="table"><thead><tr><th>Page</th><th class="num">Citations</th></tr></thead><tbody>@endif
                        <tr><td class="truncate"><a href="{{ $c['label'] }}" target="_blank" rel="noopener" title="{{ $c['title'] ?: $c['label'] }}">{{ $c['title'] ?: Fmt::url($c['label']) }}</a></td><td class="num">{{ $c['citations'] }}</td></tr>
                        @if ($loop->last)</tbody></table>@endif
                    @empty
                        <x-empty>No citations collected in this period.</x-empty>
                    @endforelse
                </div>
            </div>

            <div class="panel">
                <h3 class="panel__title">Performance by tracked prompt <span class="muted">({{ count($a['prompts']) }})</span></h3>
                <div class="table-wrap">
                    <table class="table table--prompts">
                        <thead><tr><th>Prompt</th><th>Stage</th><th class="num">Answers</th><th class="num">Mention rate</th><th class="num">Change</th><th class="num">Share of voice</th><th class="num">Rank</th><th>Most mentioned</th><th>Mentioned by</th></tr></thead>
                        <tbody>
                        @foreach ($a['prompts'] as $i => $p)
                            @php $d = Fmt::delta($p['mention_rate'], $p['prev_mention_rate'], 'rate'); @endphp
                            <tr @if ($i >= 20) hidden @endif>
                                <td class="prompt">{{ $p['prompt'] }}</td>
                                <td class="muted">{{ $p['phase'] ? ucfirst(strtolower(str_replace('_', ' ', $p['phase']))) : '' }}</td>
                                <td class="num">{{ $p['runs'] }}</td>
                                <td class="num">{{ Fmt::pct($p['mention_rate']) }}</td>
                                <td class="num">@if ($d)<span @class(['delta', 'delta--good' => $d['good'] === true, 'delta--bad' => $d['good'] === false])>{{ $d['text'] }}</span>@endif</td>
                                <td class="num">{{ Fmt::pct($p['share_of_voice']) }}</td>
                                <td class="num">{{ $p['rank'] ? '#' . $p['rank'] : '—' }}</td>
                                <td>{{ $p['leader'] ?? '—' }}</td>
                                <td class="muted">{{ implode(', ', $p['engines_mentioning']) ?: '—' }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                @if (count($a['prompts']) > 20)
                    {{-- Reveals the hidden rows 20 at a time. --}}
                    <button type="button" class="more-button" onclick="
                        const rows = [...this.previousElementSibling.querySelectorAll('tbody tr[hidden]')];
                        rows.slice(0, 20).forEach(r => r.hidden = false);
                        const left = rows.length - 20;
                        if (left > 0) this.textContent = `Show ${Math.min(20, left)} more (${left} left)`; else this.remove();
                    ">Show {{ min(20, count($a['prompts']) - 20) }} more ({{ count($a['prompts']) - 20 }} left)</button>
                @endif
            </div>
            <p class="source">Source: HubSpot AEO, synced {{ $a['synced_at'] ? \Illuminate\Support\Carbon::parse($a['synced_at'])->diffForHumans() : 'never' }}. Mentions are counted by finding each brand's name in the answer text.</p>
        @endif
    </div>
</section>

{{-- ============ 02 SEO ============ --}}
<section id="seo" class="section">
    <div class="container">
        <header class="section__head">
            <div>
                <h2 class="section__title">SEO</h2>
                <p class="section__lede">Google Search performance for the site.</p>
            </div>
        </header>
        @if (! $seo['ok'])
            <x-section-error :message="$seo['error']" />
        @else
            @php $s = $seo['data']; $t = $s['totals']; $pt = $s['prev_totals']; @endphp
            <div class="tiles">
                <x-tile hero label="Clicks" :value="Fmt::num($t['clicks'])" :delta="Fmt::delta($t['clicks'], $pt['clicks'])" />
                <x-tile label="Impressions" :value="Fmt::num($t['impressions'])" :delta="Fmt::delta($t['impressions'], $pt['impressions'])" />
                <x-tile label="CTR" :value="Fmt::pct($t['ctr'], 1)" :delta="Fmt::delta($t['ctr'], $pt['ctr'], 'rate')" />
                <x-tile label="Average position" :value="$t['position'] ? number_format($t['position'], 1) : '—'" :delta="Fmt::delta($t['position'], $pt['position'], 'position')" note="Lower is better" />
            </div>
            <div class="grid grid--2">
                @foreach (['queries' => 'Top search queries', 'pages' => 'Top landing pages'] as $key => $title)
                    <div class="panel">
                        <h3 class="panel__title">{{ $title }}</h3>
                        @forelse ($s[$key] as $r)
                            @if ($loop->first)<table class="table"><thead><tr><th>{{ $key === 'queries' ? 'Query' : 'Page' }}</th><th class="num">Clicks</th><th class="num">Impr.</th><th class="num">CTR</th><th class="num">Pos.</th></tr></thead><tbody>@endif
                            <tr>
                                <td class="truncate" title="{{ $r['label'] }}">{{ $key === 'pages' ? Fmt::url($r['label']) : $r['label'] }}</td>
                                <td class="num">{{ Fmt::num($r['clicks']) }}</td>
                                <td class="num">{{ Fmt::num($r['impressions']) }}</td>
                                <td class="num">{{ Fmt::pct($r['ctr'], 1) }}</td>
                                <td class="num">{{ $r['position'] ? number_format($r['position'], 1) : '—' }}</td>
                            </tr>
                            @if ($loop->last)</tbody></table>@endif
                        @empty
                            <x-empty>No search data in this period.</x-empty>
                        @endforelse
                    </div>
                @endforeach
            </div>
            <p class="source">Source: Google Search Console. Data from the last 2–3 days may still be incomplete.</p>
        @endif
    </div>
</section>

{{-- ============ 03 Traffic ============ --}}
<section id="traffic" class="section">
    <div class="container">
        <header class="section__head">
            <div>
                <h2 class="section__title">Website traffic</h2>
                <p class="section__lede">Sessions by source, with organic search and AI referrals called out.</p>
            </div>
        </header>
        @if (! $traffic['ok'])
            <x-section-error :message="$traffic['error']" />
        @else
            @php $tr = $traffic['data']; $src = collect($tr['sources'])->keyBy('label'); @endphp
            <div class="tiles">
                <x-tile hero label="Sessions" :value="Fmt::num($tr['sessions'])" :delta="Fmt::delta($tr['sessions'], $tr['prev_sessions'])" />
                @foreach (['Organic search', 'AI referrals'] as $label)
                    <x-tile :label="$label . ' sessions'" :value="Fmt::num($src[$label]['sessions'] ?? 0)"
                            :delta="Fmt::delta($src[$label]['sessions'] ?? 0, $src[$label]['prev_sessions'] ?? 0)"
                            :note="$tr['sessions'] ? Fmt::pct(($src[$label]['sessions'] ?? 0) / $tr['sessions']) . ' of all sessions' : null" />
                @endforeach
                <x-tile label="Page views" :value="Fmt::num($tr['views'])" :delta="Fmt::delta($tr['views'], $tr['prev_views'])" />
            </div>
            <div class="panel">
                <h3 class="panel__title">Sessions by source</h3>
                @php $maxS = collect($tr['sources'])->max('sessions') ?: 0; @endphp
                <table class="table">
                    <thead><tr><th>Source</th><th class="num">Sessions</th><th class="num">Share</th><th class="num">Change</th><th class="num">New contacts</th></tr></thead>
                    <tbody>
                    @foreach ($tr['sources'] as $r)
                        @php $d = Fmt::delta($r['sessions'], $r['prev_sessions']); @endphp
                        <tr @class(['is-own' => in_array($r['label'], ['Organic search', 'AI referrals'])])>
                            <td>{{ $r['label'] }}</td>
                            <td class="num"><x-bar :value="$r['sessions']" :max="$maxS" :label="Fmt::num($r['sessions']) . ' sessions'" /> {{ Fmt::num($r['sessions']) }}</td>
                            <td class="num">{{ $tr['sessions'] ? Fmt::pct($r['sessions'] / $tr['sessions']) : '—' }}</td>
                            <td class="num">@if ($d)<span @class(['delta', 'delta--good' => $d['good'] === true, 'delta--bad' => $d['good'] === false])>{{ $d['text'] }}</span>@endif</td>
                            <td class="num">{{ Fmt::num($r['contacts']) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="grid grid--2">
                @foreach (['top_pages' => ['Top pages', 'views', 'Views'], 'landing_pages' => ['Top landing pages', 'entrances', 'Entrances']] as $key => [$title, $metric, $col])
                    <div class="panel">
                        <h3 class="panel__title">{{ $title }}</h3>
                        @forelse ($tr[$key] as $r)
                            @if ($loop->first)<table class="table"><thead><tr><th>Page</th><th class="num">{{ $col }}</th></tr></thead><tbody>@endif
                            <tr><td class="truncate" title="{{ $r['url'] }}">{{ Fmt::url($r['url']) }}</td><td class="num">{{ Fmt::num($r[$metric]) }}</td></tr>
                            @if ($loop->last)</tbody></table>@endif
                        @empty
                            <x-empty>No page data in this period.</x-empty>
                        @endforelse
                    </div>
                @endforeach
            </div>
            <p class="source">Source: HubSpot traffic analytics.</p>
        @endif
    </div>
</section>

{{-- ============ 04 Leads ============ --}}
<section id="leads" class="section">
    <div class="container">
        <header class="section__head">
            <div>
                <h2 class="section__title">Leads &amp; conversions</h2>
                <p class="section__lede">New contacts and form submissions, by source, form and landing page.</p>
            </div>
        </header>
        @if (! $leads['ok'])
            <x-section-error :message="$leads['error']" />
        @else
            @php $l = $leads['data']; @endphp
            <div class="tiles">
                <x-tile hero label="New contacts" :value="Fmt::num($l['new_contacts'])" :delta="Fmt::delta($l['new_contacts'], $l['prev_new_contacts'])" />
                <x-tile label="Form submissions" :value="Fmt::num($l['submissions'])" :delta="Fmt::delta($l['submissions'], $l['prev_submissions'])" />
                @foreach ($l['key_pages'] as $k)
                    <x-tile :label="$k['label'] . ' conversion'" :value="Fmt::pct($k['conversion'], 1)"
                            :note="Fmt::num($k['submissions']) . ' submissions from ' . Fmt::num($k['views']) . ' views'" />
                @endforeach
            </div>
            <div class="grid grid--2">
                <div class="panel">
                    <h3 class="panel__title">New contacts by source</h3>
                    @php $maxC = collect($l['by_source'])->max('contacts') ?: 0; @endphp
                    @forelse ($l['by_source'] as $r)
                        @if ($loop->first)<table class="table"><thead><tr><th>Original source</th><th class="num">Contacts</th><th class="num">Share</th></tr></thead><tbody>@endif
                        <tr>
                            <td>{{ $r['label'] }}</td>
                            <td class="num"><x-bar :value="$r['contacts']" :max="$maxC" :label="$r['contacts'] . ' contacts'" /> {{ Fmt::num($r['contacts']) }}</td>
                            <td class="num">{{ $l['new_contacts'] ? Fmt::pct($r['contacts'] / $l['new_contacts']) : '—' }}</td>
                        </tr>
                        @if ($loop->last)</tbody></table>@endif
                    @empty
                        <x-empty>No new contacts in this period.</x-empty>
                    @endforelse
                </div>
                <div class="panel">
                    <h3 class="panel__title">Submissions by form</h3>
                    @forelse ($l['by_form'] as $r)
                        @if ($loop->first)<table class="table"><thead><tr><th>Form</th><th class="num">Submissions</th><th class="num">Views</th></tr></thead><tbody>@endif
                        <tr><td class="truncate" title="{{ $r['label'] }}">{{ $r['label'] }}</td><td class="num">{{ Fmt::num($r['submissions']) }}</td><td class="num">{{ $r['views'] ? Fmt::num($r['views']) : '—' }}</td></tr>
                        @if ($loop->last)</tbody></table>@endif
                    @empty
                        <x-empty>No form submissions in this period.</x-empty>
                    @endforelse
                </div>
            </div>
            <div class="panel">
                <h3 class="panel__title">Conversion by landing page</h3>
                @forelse ($l['by_page'] as $r)
                    @if ($loop->first)<table class="table"><thead><tr><th>Page</th><th class="num">Views</th><th class="num">Submissions</th><th class="num">Conversion rate</th></tr></thead><tbody>@endif
                    <tr><td class="truncate" title="{{ $r['url'] }}">{{ Fmt::url($r['url']) }}</td><td class="num">{{ Fmt::num($r['views']) }}</td><td class="num">{{ Fmt::num($r['submissions']) }}</td><td class="num">{{ $r['views'] ? Fmt::pct($r['submissions'] / $r['views'], 1) : '—' }}</td></tr>
                    @if ($loop->last)</tbody></table>@endif
                @empty
                    <x-empty>No pages with form submissions in this period.</x-empty>
                @endforelse
            </div>
            <p class="source">Source: HubSpot contacts, forms and page analytics. Conversion rate = form submissions ÷ page views.</p>
        @endif
    </div>
</section>

{{-- ============ 05 Revenue ============ --}}
<section id="revenue" class="section">
    <div class="container">
        <header class="section__head">
            <div>
                <h2 class="section__title">Revenue &amp; ROI</h2>
                <p class="section__lede">Deals created and closed-won, by the original source of the deal.</p>
            </div>
        </header>
        @if (! $revenue['ok'])
            <x-section-error :message="$revenue['error']" />
        @else
            @php $r = $revenue['data']; @endphp
            <div class="tiles">
                <x-tile hero label="Closed-won revenue" :value="Fmt::money($r['won_amount'])" :delta="Fmt::delta($r['won_amount'], $r['prev_won_amount'])" />
                <x-tile label="Closed-won deals" :value="Fmt::num($r['won'])" :delta="Fmt::delta($r['won'], $r['prev_won'])" />
                <x-tile label="Deals created" :value="Fmt::num($r['created'])" :delta="Fmt::delta($r['created'], $r['prev_created'])" :note="Fmt::money($r['created_amount']) . ' in pipeline'" />
            </div>
            <div class="panel">
                <h3 class="panel__title">By original source</h3>
                @php $maxW = collect($r['by_source'])->max('won_amount') ?: 0; @endphp
                @forelse ($r['by_source'] as $row)
                    @if ($loop->first)<table class="table"><thead><tr><th>Original source</th><th class="num">Deals created</th><th class="num">Created amount</th><th class="num">Closed-won</th><th class="num">Won amount</th></tr></thead><tbody>@endif
                    <tr>
                        <td>{{ $row['label'] }}</td>
                        <td class="num">{{ $row['created'] }}</td>
                        <td class="num">{{ Fmt::money($row['created_amount']) }}</td>
                        <td class="num">{{ $row['won'] }}</td>
                        <td class="num"><x-bar :value="$row['won_amount']" :max="$maxW" :label="Fmt::money($row['won_amount'])" /> {{ Fmt::money($row['won_amount']) }}</td>
                    </tr>
                    @if ($loop->last)</tbody></table>@endif
                @empty
                    <x-empty>No deals created or won in this period.</x-empty>
                @endforelse
            </div>
            <p class="source">Source: HubSpot deals. Amounts are in the account's main currency.</p>
        @endif
    </div>
</section>
</main>

<footer class="footer">
    <div class="container">Gil Travel · {{ $period->label() }} · generated {{ now()->format('M j, Y H:i') }}</div>
</footer>
</body>
</html>

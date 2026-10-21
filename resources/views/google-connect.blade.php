@extends('layouts.settings')

@section('title', 'Connect Search Console')
@section('eyebrow', 'Gil Travel marketing report')
@section('heading', 'Connect Google Search Console')

@section('content')
    <section class="panel">
        @if (! $ready)
            <p class="hint">
                The Google connection is not set up on the report yet (<code>GOOGLE_CLIENT_ID</code> and
                <code>GOOGLE_CLIENT_SECRET</code> are missing). Ask the report owner to add them, then open this link again.
            </p>
        @else
            <p class="hint">
                The report reads clicks, impressions, CTR and position from Search Console. It asks for
                <strong>read-only</strong> access and cannot change anything in Search Console.
            </p>
            <ol class="steps">
                <li>Click the button and sign in with the Google account that has access to the giltravel property.</li>
                <li>If Google shows <em>“Google hasn't verified this app”</em>, click <strong>Advanced</strong>, then <strong>Go to …</strong>. The report is an internal tool, so Google has not reviewed it.</li>
                <li>Leave the Search Console box ticked and click <strong>Continue</strong>.</li>
                <li>Back here, pick the property if you have more than one.</li>
            </ol>
            <a href="{{ $authUrl }}" class="button">Continue with Google</a>
        @endif
    </section>
@endsection

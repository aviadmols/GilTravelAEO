@extends('layouts.settings')

@section('title', 'Search Console Property')
@section('eyebrow', 'Gil Travel marketing report')
@section('heading', 'Search Console property')

@section('content')
    <section class="panel">
        @if ($error)
            <p class="hint">{{ $error }}</p>
        @elseif (! $sites)
            <p class="hint">
                {{ $account ?? 'This Google account' }} has no Search Console properties. Connect again with the account
                that has access to the giltravel property.
            </p>
        @else
            <p class="key-status">
                Signed in as <strong>{{ $account }}</strong>.
                @if ($current)
                    The SEO section reads <strong>{{ $current }}</strong>.
                @else
                    Choose the property the SEO section should read.
                @endif
            </p>

            <form method="post" action="{{ route('google.property') }}" class="key-form">
                @csrf
                <label class="field">
                    <span class="field__label">Property</span>
                    <select name="site_url" required>
                        @foreach ($sites as $site)
                            <option value="{{ $site }}" @selected($site === $current)>{{ $site }}</option>
                        @endforeach
                    </select>
                </label>
                @error('site_url')<p class="field__error">{{ $message }}</p>@enderror
                <button type="submit" class="button">Save property</button>
            </form>
        @endif
    </section>
@endsection

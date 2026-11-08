@extends('layouts.app')

@section('title', $server->name.' · '.$day->toDateString())

@section('content')
    <nav class="day-nav">
        <a href="{{ route('dashboard') }}">All servers</a>
        <span>
            <a href="{{ route('server-days.show', [$server, $previousDay->toDateString()]) }}" rel="prev">← {{ $previousDay->format('j M') }}</a>
            @if ($nextDay)
                <a href="{{ route('server-days.show', [$server, $nextDay->toDateString()]) }}" rel="next">{{ $nextDay->format('j M') }} →</a>
            @endif
        </span>
    </nav>

    <h1>{{ $server->name }} <span class="muted">{{ $day->format('l j F Y') }}</span></h1>

    @if ($alerts->isNotEmpty())
        <section>
            <h2>Alerts</h2>
            <ul class="items">
                @foreach ($alerts as $alert)
                    <li class="item item--alert">
                        <strong>{{ $alert->title }}</strong>
                        <span class="muted">{{ str_replace('_', ' ', $alert->source->value) }}{{ $alert->acknowledged_at ? ' · acknowledged' : '' }}</span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <section>
        <h2>Review</h2>
        @forelse ($reviews as $review)
            <p>
                <span class="badge badge--{{ $review->verdict->value }}">{{ $review->verdict->value }}</span>
                <span class="muted">{{ $review->model }} · confidence {{ $review->confidence ?? '–' }}</span>
            </p>
            <ul class="items">
                @foreach ($review->findings as $finding)
                    <li class="item item--{{ $finding['severity'] ?? 'info' }}">
                        <strong>{{ $finding['title'] ?? 'Finding' }}</strong>
                        <p>{{ $finding['evidence'] ?? '' }}</p>
                    </li>
                @endforeach
            </ul>
        @empty
            <p class="muted">{{ $summary ? 'Not reviewed yet.' : 'Nothing to review.' }}</p>
        @endforelse
    </section>

    @if ($ruleHits->isNotEmpty())
        <section>
            <h2>Rule hits</h2>
            <ul class="items">
                @foreach ($ruleHits as $hit)
                    <li class="item item--alert"><strong>{{ str_replace('_', ' ', $hit->rule) }}</strong></li>
                @endforeach
            </ul>
        </section>
    @endif

    <section>
        <h2>Key numbers</h2>
        @if ($summary)
            <dl class="numbers">
                @foreach ($highlights as $h)
                    <div @class(['attention' => $h['attention']])>
                        <dt>{{ $h['label'] }}</dt>
                        <dd>{{ is_int($h['value']) ? number_format($h['value']) : $h['value'] }}</dd>
                    </div>
                @endforeach
            </dl>
            <p class="muted">
                Collector {{ $summary->collector_version }} · {{ number_format($summary->size_bytes / 1024, 1) }} KB · received {{ $summary->received_at->format('j M H:i') }} UTC ·
                <a href="{{ route('server-days.raw', [$server, $day->toDateString()]) }}">raw summary JSON</a>
            </p>
        @else
            <p class="muted">No report for this day.</p>
        @endif
    </section>
@endsection

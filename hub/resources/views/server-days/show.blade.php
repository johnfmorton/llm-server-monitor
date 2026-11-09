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

    @if (session('status'))
        <p class="notice" role="status">{{ session('status') }}</p>
    @endif

    @if ($alerts->isNotEmpty())
        <section>
            <h2>Alerts</h2>
            <ul class="items">
                @foreach ($alerts as $alert)
                    <li @class(['item', 'item--alert' => ! $alert->acknowledged_at, 'item--info' => $alert->acknowledged_at])>
                        <strong>{{ $alert->title }}</strong>
                        <span class="muted">
                            {{ $alert->source === \App\AlertSource::Rule ? 'hard rule' : str_replace('_', ' ', $alert->source->value) }}
                            · {{ $alert->emailed_at ? 'emailed '.$alert->emailed_at->format('H:i') : 'not emailed' }}
                            @if ($alert->acknowledged_at)
                                · acknowledged {{ $alert->acknowledged_at->format('j M H:i') }}
                            @endif
                        </span>
                        @if ($alert->ruleHit)
                            <ul class="evidence">
                                @foreach (\App\Monitoring\Evidence::lines($alert->ruleHit->evidence) as $line)
                                    <li>{{ $line }}</li>
                                @endforeach
                            </ul>
                        @endif
                        @unless ($alert->acknowledged_at)
                            <form method="post" action="{{ route('alerts.acknowledge', $alert) }}" class="inline-form">
                                @csrf
                                <button type="submit">Acknowledge</button>
                            </form>
                        @endunless
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <section>
        <h2>Review</h2>
        @forelse ($reviews as $review)
            @if ($review->verdict)
                <p>
                    <span class="badge badge--{{ $review->verdict->value }}">{{ $review->verdict->value }}</span>
                    <span class="muted">{{ $review->model }} · confidence {{ $review->confidence ?? '–' }} · {{ number_format($review->input_tokens + $review->output_tokens) }} tokens</span>
                </p>
                @if ($review->summary)
                    <p>{{ $review->summary }}</p>
                @endif
                <ul class="items">
                    @foreach ($review->findings as $finding)
                        <li class="item item--{{ $finding['severity'] ?? 'info' }}">
                            <strong>{{ $finding['title'] ?? 'Finding' }}</strong>
                            <span class="muted">{{ $finding['severity'] ?? '' }}</span>
                            <p>{{ $finding['evidence'] ?? '' }}</p>
                        </li>
                    @endforeach
                </ul>
            @else
                <p><span class="badge badge--failed">failed</span> <span class="muted">{{ $review->created_at->format('j M H:i') }}</span></p>
                <p class="muted">{{ $review->error }}</p>
            @endif
            @break
        @empty
            <p class="muted">{{ $summary ? 'Not reviewed yet.' : 'Nothing to review.' }}</p>
        @endforelse
        @if ($reviews->count() > 1)
            <p class="muted">{{ $reviews->count() - 1 }} earlier {{ Str::plural('review', $reviews->count() - 1) }} of this day not shown.</p>
        @endif
    </section>

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

@extends('layouts.app')

@section('title', 'Weekly digest')

@php
    $labels = ['ok' => 'OK', 'watch' => 'Watch', 'alert' => 'Alert', 'pending' => 'Not reviewed yet', 'missing' => 'No report', 'future' => 'Not yet'];
    $change = function (int $current, ?int $last): string {
        if ($last === null) {
            return '';
        }
        if ($last === 0) {
            return $current === 0 ? '' : 'new';
        }
        $pct = (int) round(($current - $last) / $last * 100);

        return $pct === 0 ? '±0%' : sprintf('%+d%%', $pct);
    };
@endphp

@section('content')
    <nav class="day-nav">
        <a href="{{ route('digest', ['week' => $previousWeek->toDateString()]) }}">← Week of {{ $previousWeek->format('j M') }}</a>
        @if ($nextWeek)
            <a href="{{ route('digest', ['week' => $nextWeek->toDateString()]) }}">Week of {{ $nextWeek->format('j M') }} →</a>
        @endif
    </nav>

    <h1>
        Week of {{ $digest['start']->format('j M') }}–{{ $digest['end']->format('j M Y') }}
        <span @class(['badge', 'badge--ok' => $digest['clear'], 'badge--watch' => ! $digest['clear']])>{{ $digest['headline'] }}</span>
    </h1>

    @if ($digest['servers'] !== [])
        <div class="grid-scroll">
            <table class="grid">
                <thead>
                    <tr>
                        <th scope="col" class="grid-server">Server</th>
                        @foreach ($digest['days'] as $day)
                            <th scope="col" class="grid-day" title="{{ $day->format('D j M Y') }}">
                                <span class="dow">{{ $day->format('D')[0] }}</span>{{ $day->format('j') }}
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($digest['servers'] as $row)
                        <tr>
                            <th scope="row" class="grid-server">{{ $row['server']->name }}</th>
                            @foreach ($row['states'] as $date => $state)
                                <td>
                                    @if ($state === 'future')
                                        <span class="cell cell--future" title="{{ $labels[$state] }}"></span>
                                    @else
                                        <a class="cell cell--{{ $state }}"
                                           href="{{ route('server-days.show', [$row['server'], $date]) }}"
                                           aria-label="{{ $row['server']->name }}, {{ $date }}: {{ $labels[$state] }}"
                                           title="{{ $date }}: {{ $labels[$state] }}"></a>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                        <tr>
                            <td colspan="8" class="grid-note">{{ $row['line'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @foreach ($digest['servers'] as $row)
            @continue($row['alerts'] === [] && $row['watch'] === [])
            <section>
                <h2>{{ $row['server']->name }}</h2>
                <ul class="items">
                    @foreach ($row['alerts'] as $alert)
                        <li @class(['item', 'item--alert' => ! $alert->acknowledged_at, 'item--info' => $alert->acknowledged_at])>
                            <a href="{{ route('server-days.show', [$row['server'], $alert->date->toDateString()]) }}">{{ $alert->date->format('D j M') }}</a>:
                            <strong>{{ $alert->title }}</strong>
                            <span class="muted">· {{ str_replace('_', ' ', $alert->source->value) }} · {{ $alert->acknowledged_at ? 'acknowledged' : 'open' }}</span>
                        </li>
                    @endforeach
                    @foreach ($row['watch'] as $item)
                        <li class="item item--watch">
                            <a href="{{ route('server-days.show', [$row['server'], $item['date']->toDateString()]) }}">{{ $item['date']->format('D j M') }}</a>:
                            {{ $item['title'] }} <span class="muted">· watch, {{ $item['severity'] }}</span>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach

        <h2>Counts this week <span class="muted">(change on the week before)</span></h2>
        <div class="grid-scroll">
            <table class="counts">
                <thead>
                    <tr>
                        <th scope="col">Server</th>
                        @foreach (\App\Monitoring\WeeklyDigest::METRICS as $key)
                            <th scope="col">{{ \App\Monitoring\SummaryMetrics::LABELS[$key] }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($digest['servers'] as $row)
                        <tr>
                            <th scope="row">{{ $row['server']->name }}</th>
                            @foreach ($row['metrics'] as $metric)
                                <td>{{ number_format($metric['this']) }} <span class="muted">{{ $change($metric['this'], $metric['last']) }}</span></td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection

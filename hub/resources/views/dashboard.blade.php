@extends('layouts.app')

@section('title', 'Servers')

@php
    $labels = ['ok' => 'OK', 'watch' => 'Watch', 'alert' => 'Alert', 'pending' => 'Not reviewed yet', 'missing' => 'No report'];
@endphp

@section('content')
    <h1>Last {{ count($days) }} days</h1>

    @if ($servers->isEmpty())
        <p class="muted">No servers yet. Add one with <code>php artisan logwatch:server-add &lt;name&gt;</code>.</p>
    @else
        <div class="grid-scroll">
            <table class="grid">
                <thead>
                    <tr>
                        <th scope="col" class="grid-server">Server</th>
                        @foreach ($days as $day)
                            <th scope="col" class="grid-day" title="{{ $day->format('D j M Y') }}">
                                <span class="dow">{{ $day->format('D')[0] }}</span>{{ $day->format('j') }}
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($servers as $server)
                        <tr>
                            <th scope="row" class="grid-server">
                                {{ $server->name }}
                                @if ($server->is_client)<span class="tag">client</span>@endif
                                <span class="last-report">
                                    {{ $server->last_report_at ? 'reported '.$server->last_report_at->diffForHumans() : 'never reported' }}
                                </span>
                            </th>
                            @foreach ($days as $day)
                                @php($state = $status[$server->id][$day->toDateString()] ?? 'missing')
                                <td>
                                    <a class="cell cell--{{ $state }}"
                                       href="{{ route('server-days.show', [$server, $day->toDateString()]) }}"
                                       aria-label="{{ $server->name }}, {{ $day->format('j M') }}: {{ $labels[$state] }}"
                                       title="{{ $day->format('D j M') }}: {{ $labels[$state] }}"></a>
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <ul class="legend">
            @foreach ($labels as $state => $label)
                <li><span class="cell cell--{{ $state }}"></span>{{ $label }}</li>
            @endforeach
        </ul>
    @endif
@endsection

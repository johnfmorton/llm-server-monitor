{{-- Plain text: nothing here is rendered as HTML, so output is not escaped. --}}
LogWatch weekly digest, {!! $digest['start']->format('j M') !!}–{!! $digest['end']->format('j M Y') !!}
{!! $digest['headline'] !!}

@foreach ($digest['servers'] as $row)
{!! $row['server']->name !!}: {!! $row['line'] !!}
@foreach ($row['alerts'] as $alert)
   - {!! $alert->date->format('D j') !!}: {!! $alert->title !!} ({!! $alert->acknowledged_at ? 'acknowledged' : 'open' !!})
@endforeach
@foreach ($row['watch'] as $item)
   - {!! $item['date']->format('D j') !!}: {!! $item['title'] !!} (watch)
@endforeach
@endforeach

Open the week: {!! $digestUrl !!}

{{-- Plain text: nothing here is rendered as HTML, so output is not escaped. --}}
{!! $server->name !!} — {!! $day->format('l j F Y') !!} (UTC)

@foreach ($alerts as $alert)
{!! $loop->iteration !!}. {!! $alert->title !!}
   Raised by: {!! $alert->sourceLabel() !!}
@if ($alert->ruleHit)
@foreach (\App\Monitoring\Evidence::lines($alert->ruleHit->evidence) as $line)
   - {!! $line !!}
@endforeach
@elseif ($alert->review)
@if ($alert->review->summary)
   {!! $alert->review->summary !!}
@endif
@foreach ($alert->review->findings as $finding)
   - [{!! $finding['severity'] !!}] {!! $finding['title'] !!}: {!! $finding['evidence'] !!}
@endforeach
@elseif ($alert->source === \App\AlertSource::MissingReport)
   The collector didn't send a summary for this day. Check the server is up and its Forge scheduled job is still running.
@endif

@endforeach
Open the day: {!! $dayUrl !!}

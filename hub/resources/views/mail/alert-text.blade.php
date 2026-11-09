{{-- Plain text: nothing here is rendered as HTML, so output is not escaped. --}}
{!! $server->name !!} — {!! $day->format('l j F Y') !!} (UTC)

@foreach ($alerts as $alert)
{!! $loop->iteration !!}. {!! $alert->title !!}
   Raised by: {!! $alert->source === \App\AlertSource::Rule ? 'a hard rule ('.str_replace('_', ' ', $alert->ruleHit?->rule ?? '').')' : 'the model review ('.($alert->review?->model ?? '').')' !!}
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
@endif

@endforeach
Open the day: {!! $dayUrl !!}

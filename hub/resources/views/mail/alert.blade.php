<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>LogWatch alert</title>
</head>
<body style="margin:0;padding:16px;font:15px/1.5 -apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;color:#1d1d1f;background:#ffffff;">
<div style="max-width:640px;margin:0 auto;">
    <p style="margin:0 0 4px;font-size:13px;color:#6e6e73;">LogWatch alert</p>
    <h1 style="margin:0 0 16px;font-size:20px;">{{ $server->name }} <span style="font-weight:normal;color:#6e6e73;">{{ $day->format('l j F Y') }}</span></h1>

    @foreach ($alerts as $alert)
        <div style="border-left:4px solid #c62828;padding:4px 0 4px 12px;margin:0 0 20px;">
            <p style="margin:0;font-weight:600;">{{ $alert->title }}</p>
            <p style="margin:0 0 8px;font-size:13px;color:#6e6e73;">
                @if ($alert->source === \App\AlertSource::Rule)
                    Hard rule: {{ str_replace('_', ' ', $alert->ruleHit?->rule ?? '') }}
                @else
                    Model review: {{ $alert->review?->model }}
                @endif
            </p>
            @if ($alert->ruleHit)
                <ul style="margin:0;padding-left:20px;font-size:14px;">
                    @foreach (\App\Monitoring\Evidence::lines($alert->ruleHit->evidence) as $line)
                        <li style="word-break:break-word;">{{ $line }}</li>
                    @endforeach
                </ul>
            @elseif ($alert->review)
                @if ($alert->review->summary)
                    <p style="margin:0 0 8px;">{{ $alert->review->summary }}</p>
                @endif
                <ul style="margin:0;padding-left:20px;font-size:14px;">
                    @foreach ($alert->review->findings as $finding)
                        <li><strong>{{ $finding['title'] }}</strong> <span style="color:#6e6e73;">({{ $finding['severity'] }})</span><br>{{ $finding['evidence'] }}</li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endforeach

    <p><a href="{{ $dayUrl }}" style="color:#0b57d0;">Open this day on the dashboard</a></p>
</div>
</body>
</html>

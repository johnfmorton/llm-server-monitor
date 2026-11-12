<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>LogWatch weekly digest</title>
</head>
<body style="margin:0;padding:16px;font:15px/1.5 -apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;color:#1d1d1f;background:#ffffff;">
<div style="max-width:640px;margin:0 auto;">
    <p style="margin:0 0 4px;font-size:13px;color:#6e6e73;">LogWatch weekly digest · {{ $digest['start']->format('j M') }}–{{ $digest['end']->format('j M Y') }}</p>
    <h1 style="margin:0 0 16px;font-size:20px;">{{ $digest['headline'] }}</h1>

    @foreach ($digest['servers'] as $row)
        <div style="border-left:4px solid {{ $row['clear'] ? '#2f9e5b' : ($row['open_alerts'] > 0 ? '#c62828' : '#d99a16') }};padding:4px 0 4px 12px;margin:0 0 16px;">
            <p style="margin:0;font-weight:600;">{{ $row['server']->name }}</p>
            <p style="margin:0;font-size:14px;color:#3a3a3c;">{{ $row['line'] }}</p>
            @if ($row['alerts'] !== [] || $row['watch'] !== [])
                <ul style="margin:6px 0 0;padding-left:20px;font-size:14px;">
                    @foreach ($row['alerts'] as $alert)
                        <li>{{ $alert->date->format('D j') }}: {{ $alert->title }} <span style="color:#6e6e73;">({{ $alert->acknowledged_at ? 'acknowledged' : 'open' }})</span></li>
                    @endforeach
                    @foreach ($row['watch'] as $item)
                        <li>{{ $item['date']->format('D j') }}: {{ $item['title'] }} <span style="color:#6e6e73;">(watch)</span></li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endforeach

    <p><a href="{{ $digestUrl }}" style="color:#0b57d0;">Open the week on the dashboard</a></p>
</div>
</body>
</html>

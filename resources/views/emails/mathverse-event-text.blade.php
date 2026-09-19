MATHVERSE — {{ strtoupper($eyebrow) }}

{{ $heading }}

@if($recipientName !== '')
Hello {{ $recipientName }},

@endif
{{ $messageText }}

@foreach($details as $detail){{ $detail['label'] }}: {{ $detail['value'] }}
@endforeach
@if($actionLabel && $actionUrl)
{{ $actionLabel }}: {{ $actionUrl }}
@endif

{{ $securityNote }}

This automated notification was sent by Math MetaVerse. Please do not reply.

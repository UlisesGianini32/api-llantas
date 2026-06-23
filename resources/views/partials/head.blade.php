<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>{{ $title ?? config('app.name') }}</title>

{{-- FAVICON (usa tu llanta sola) --}}
<link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
{{-- opcional: si tienes tu propio svg, déjalo; si no, bórralo --}}
<link rel="icon" href="{{ asset('favicon.ico') }}?v=2" sizes="any">
<link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}?v=2">

<link rel="preconnect" href="https://fonts.bunny.net">
<link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />

@vite(['resources/css/app.css', 'resources/js/app.jsx'])
@fluxAppearance

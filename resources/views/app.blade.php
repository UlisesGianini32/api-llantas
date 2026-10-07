<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <title inertia>{{ config('app.name', 'Laravel') }}</title>

    <link rel="icon" type="image/x-icon" href="/favicon.ico?v=sbs2">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png?v=sbs2">
    <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png?v=sbs2">
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png?v=sbs2">

    @viteReactRefresh
    @vite('resources/js/app.jsx')
    @inertiaHead
</head>
<body class="h-full antialiased">
    @inertia
</body>
</html>
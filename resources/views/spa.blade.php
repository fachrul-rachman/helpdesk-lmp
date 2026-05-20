<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">
        <link rel="manifest" href="/manifest.webmanifest">
        <meta name="theme-color" content="#2563EB">

        @fonts

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/spa/main.tsx'])
        <title>{{ config('app.name', 'Helpdesk') }}</title>
    </head>
    <body class="font-sans antialiased bg-slate-50 text-slate-900">
        <div id="app"></div>
    </body>
</html>

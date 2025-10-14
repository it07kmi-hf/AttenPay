<!doctype html>
<html lang="{{ str_replace('_','-',app()->getLocale()) }}">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title inertia>{{ config('app.name') }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
    @vite('resources/js/app.jsx')
    @inertiaHead
  </head>
  <body class="bg-[#f0faff] text-gray-900">
    @inertia
  </body>
</html>

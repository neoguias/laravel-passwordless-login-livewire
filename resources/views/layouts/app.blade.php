<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Entrar' }} · {{ config('app.name') }}</title>
    <link rel="stylesheet" href="/login.css">
</head>
<body>
    <main class="card">
        {{ $slot }}
    </main>
</body>
</html>

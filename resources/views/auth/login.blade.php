<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ingresar | Portal CGNAT</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="login-page">
<main class="login-card">
    <h1>PORTAL CGNAT</h1>
    <p class="login-card__subtitle"></p>

    @if ($errors->any())
        <div class="alert alert--danger">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('portal.login.submit') }}">
        @csrf
        <div class="field">
            <label class="sr-only" for="username">Usuario corporativo</label>
            <input class="control" id="username" name="username" value="{{ old('username') }}" autocomplete="username" placeholder="Usuario / Código C" maxlength="100" required autofocus>
        </div>
        <div class="field password-field">
            <label class="sr-only" for="password">Contraseña</label>
            <input class="control" id="password" name="password" type="password" autocomplete="current-password" placeholder="Contraseña" maxlength="255" required>
            <button type="button" class="password-toggle" data-password-toggle aria-label="Mostrar contraseña">◉</button>
        </div>
        <button class="btn btn--primary login-card__submit" type="submit">Iniciar sesión</button>
    </form>
</main>
</body>
</html>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ingresar | Portal CGNAT</title>
    @vite(['resources/css/login-bootstrap.css', 'resources/css/app.css', 'resources/js/app.js'])
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
@if ($sessionConflict)
<div class="modal fade" id="activeSessionModal" tabindex="-1" aria-labelledby="activeSessionTitle" aria-describedby="activeSessionDescription" aria-hidden="true" data-active-session-modal>
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header"><h2 class="modal-title fs-5" id="activeSessionTitle">Sesión activa</h2></div>
            <div class="modal-body" id="activeSessionDescription">Ya tienes una sesión iniciada en otro navegador o dispositivo. Para ingresar aquí, debes cerrar esa sesión.</div>
            <div class="modal-footer">
                <form method="POST" action="{{ route('portal.login.cancel') }}">@csrf<button class="btn btn--secondary" type="submit">Cancelar</button></form>
                <form method="POST" action="{{ route('portal.login.replace') }}">@csrf<button class="btn btn--primary" type="submit">Cerrar otras sesiones</button></form>
            </div>
        </div>
    </div>
</div>
@endif
</body>
</html>

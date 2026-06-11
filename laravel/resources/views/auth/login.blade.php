<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion</title>
    <style>
        body {
            font-family: system-ui, sans-serif;
            margin: 24px;
            color: #111;
        }

        main {
            max-width: 420px;
        }

        label {
            display: block;
            margin-bottom: 12px;
        }

        span {
            display: block;
            margin-bottom: 4px;
        }

        input {
            box-sizing: border-box;
            width: 100%;
            padding: 8px;
            border: 1px solid #aaa;
        }

        button,
        a.button {
            display: inline-block;
            padding: 8px 12px;
            border: 1px solid #111;
            background: #111;
            color: white;
            cursor: pointer;
            text-decoration: none;
        }

        a {
            color: #111;
        }

        .actions {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .error {
            color: #b00020;
            margin-bottom: 12px;
        }
    </style>
</head>
<body>
    <main>
        <h1>Connexion</h1>

        @if ($errors->any())
            <div class="error">
                {{ $errors->first() }}
            </div>
        @endif
        <div id="offline-auth-error" class="error" hidden></div>

        <form id="login-form" method="POST" action="{{ route('login') }}">
            @csrf

            <label>
                <span>Email</span>
                <input name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="email" list="offline-users">
                <datalist id="offline-users"></datalist>
            </label>

            <label>
                <span>Mot de passe</span>
                <input name="password" type="password" required autocomplete="current-password">
            </label>

            <label>
                <input name="remember" type="checkbox" value="1" style="width: auto;">
                Se souvenir de moi
            </label>

            <div class="actions">
                <button type="submit">Se connecter</button>
                <a href="{{ route('register') }}">Creer un compte</a>
            </div>
        </form>
    </main>
    <script src="/js/offline-auth.js" defer></script>
</body>
</html>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inscription</title>
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

        button {
            padding: 8px 12px;
            border: 1px solid #111;
            background: #111;
            color: white;
            cursor: pointer;
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
        <h1>Inscription</h1>

        @if ($errors->any())
            <div class="error">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="{{ route('register') }}">
            @csrf

            <label>
                <span>Nom</span>
                <input name="name" type="text" value="{{ old('name') }}" required autofocus autocomplete="name">
            </label>

            <label>
                <span>Email</span>
                <input name="email" type="email" value="{{ old('email') }}" required autocomplete="email">
            </label>

            <label>
                <span>Mot de passe</span>
                <input name="password" type="password" required autocomplete="new-password">
            </label>

            <label>
                <span>Confirmer le mot de passe</span>
                <input name="password_confirmation" type="password" required autocomplete="new-password">
            </label>

            <div class="actions">
                <button type="submit">Creer le compte</button>
                <a href="{{ route('login') }}">Deja inscrit ?</a>
            </div>
        </form>
    </main>
</body>
</html>

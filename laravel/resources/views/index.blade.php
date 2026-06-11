<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Recherche ClickHouse</title>
    <style>
        body {
            font-family: system-ui, sans-serif;
            margin: 0;
            color: #111;
            background: #f6f6f6;
        }

        main {
            max-width: 100%;
        }

        form {
            display: flex;
            gap: 8px;
            margin-bottom: 12px;
        }

        .auth-panel {
            max-width: 460px;
            margin: 56px auto;
            padding: 24px;
            background: white;
            border: 1px solid #ddd;
        }

        .auth-panel form {
            display: block;
        }

        .auth-panel label {
            display: block;
            margin-bottom: 12px;
        }

        .auth-panel span {
            display: block;
            margin-bottom: 4px;
        }

        .auth-panel input {
            box-sizing: border-box;
            width: 100%;
        }

        .auth-switch {
            display: grid;
            grid-template-columns: 1fr 1fr;
            margin-bottom: 16px;
            border: 1px solid #111;
        }

        .auth-switch button {
            border: 0;
            background: white;
            color: #111;
        }

        .auth-switch button.is-active {
            background: #111;
            color: white;
        }

        .auth-submit {
            width: 100%;
        }

        #app-shell {
            margin: 24px;
        }

        header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 16px;
        }

        header form {
            margin-bottom: 0;
        }

        input {
            flex: 1;
            padding: 8px;
            border: 1px solid #aaa;
        }

        select {
            padding: 8px;
            border: 1px solid #aaa;
            background: white;
        }

        input[type="number"] {
            max-width: 90px;
        }

        .filters {
            display: flex;
            gap: 8px;
            margin-bottom: 12px;
        }

        .is-hidden {
            display: none;
        }

        button {
            padding: 8px 12px;
            border: 1px solid #111;
            background: #111;
            color: white;
            cursor: pointer;
        }

        #status {
            min-height: 22px;
            margin-bottom: 12px;
        }

        .error {
            color: #b00020;
            margin-bottom: 12px;
        }

        .offline {
            color: #a15c00;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        th,
        td {
            border: 1px solid #ddd;
            padding: 6px;
            text-align: left;
        }

        th {
            background: #f5f5f5;
        }

        @media (max-width: 700px) {
            body {
                margin: 12px;
            }

            form {
                display: block;
            }

            input,
            select,
            button {
                box-sizing: border-box;
                width: 100%;
                margin-bottom: 8px;
            }

            .filters {
                display: block;
            }

            table {
                display: block;
                overflow-x: auto;
            }
        }
    </style>
</head>
<body data-server-auth="{{ auth()->check() ? '1' : '0' }}" data-server-user-name="{{ auth()->user()?->name }}" data-server-user-email="{{ auth()->user()?->email }}">
    <main id="auth-panel" class="auth-panel{{ auth()->check() ? ' is-hidden' : '' }}">
        <h1 id="auth-title">Connexion</h1>
        <div id="offline-auth-error" class="error" hidden></div>

        <div class="auth-switch">
            <button id="show-login-button" class="is-active" type="button">Connexion</button>
            <button id="show-register-button" type="button">Inscription</button>
        </div>

        <form id="login-form" method="POST" action="{{ route('login') }}">
            @csrf

            <label>
                <span>Email</span>
                <input name="email" type="email" required autocomplete="email" list="offline-users">
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

            <button class="auth-submit" type="submit">Se connecter</button>
        </form>

        <form id="register-form" class="is-hidden" method="POST" action="{{ route('register') }}">
            @csrf

            <label>
                <span>Nom</span>
                <input name="name" type="text" required autocomplete="name">
            </label>

            <label>
                <span>Email</span>
                <input name="email" type="email" required autocomplete="email">
            </label>

            <label>
                <span>Mot de passe</span>
                <input name="password" type="password" required autocomplete="new-password">
            </label>

            <label>
                <span>Confirmer le mot de passe</span>
                <input name="password_confirmation" type="password" required autocomplete="new-password">
            </label>

            <button class="auth-submit" type="submit">Creer le compte</button>
        </form>
    </main>

    <main id="app-shell" class="{{ auth()->check() ? '' : 'is-hidden' }}">
    <header>
        <div id="auth-user-label">
            Connecte en tant que {{ auth()->user()?->name }}
        </div>
        <form id="logout-form" method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit">Deconnexion</button>
        </form>
    </header>

    <form id="search-form">
        <input id="search-input" name="q" type="search" placeholder="9 juin 2024, 20240609, 2024..." autocomplete="off" autofocus>
        <select id="mode-filter" name="mode">
            <option value="raw">Mesures</option>
            <option value="bz_average">Bz moyen</option>
            <option value="bz_threshold">Bz selon seuil</option>
            <option value="speed_12h_average">Vitesse moyenne par tranche</option>
        </select>
        <button type="submit">Chercher</button>
    </form>

    <div class="filters">
        <select id="bz-operator-filter" name="bz_operator" class="is-hidden">
            <option value="<">&lt;</option>
            <option value="<=">&lt;=</option>
            <option value="=">=</option>
            <option value=">=">&gt;=</option>
            <option value=">">&gt;</option>
        </select>
        <input id="bz-value-filter" class="is-hidden" name="bz_value" type="number" value="-40" step="0.1">
        <select id="bucket-hours-filter" name="bucket_hours" class="is-hidden">
            <option value="12">12h</option>
            <option value="1">1h</option>
            <option value="3">3h</option>
            <option value="6">6h</option>
            <option value="24">24h</option>
        </select>
    </div>

    <div id="status"></div>

    <table>
        <thead id="table-head">
            <tr>
                <th>Date</th>
                <th>Speed</th>
                <th>Density</th>
                <th>Bt</th>
                <th>Bz</th>
            </tr>
        </thead>
        <tbody id="results">
            <tr>
                <td colspan="5">Tape une date pour chercher.</td>
            </tr>
        </tbody>
    </table>
    </main>

    <script>
        const form = document.querySelector('#search-form');
        const input = document.querySelector('#search-input');
        const modeFilter = document.querySelector('#mode-filter');
        const bzOperatorFilter = document.querySelector('#bz-operator-filter');
        const bzValueFilter = document.querySelector('#bz-value-filter');
        const bucketHoursFilter = document.querySelector('#bucket-hours-filter');
        const statusEl = document.querySelector('#status');
        const tableHeadEl = document.querySelector('#table-head');
        const resultsEl = document.querySelector('#results');
        const dbName = 'flash-clickhouse-search';
        const storeName = 'searches';

        const db = new Promise((resolve, reject) => {
            const request = indexedDB.open(dbName, 1);

            request.onupgradeneeded = () => {
                request.result.createObjectStore(storeName, { keyPath: 'query' });
            };

            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error);
        });

        function normalizeQuery(value) {
            return value.replace(/\D+/g, '');
        }

        async function storeSearch(query, rows) {
            const connection = await db;
            const transaction = connection.transaction(storeName, 'readwrite');

            transaction.objectStore(storeName).put({
                query,
                rows,
                savedAt: new Date().toISOString(),
            });
        }

        async function offlineSearch(query) {
            const connection = await db;

            return new Promise((resolve, reject) => {
                const transaction = connection.transaction(storeName, 'readonly');
                const request = transaction.objectStore(storeName).getAll();

                request.onsuccess = () => {
                    const seen = new Set();
                    const rows = request.result
                        .flatMap((entry) => entry.rows || [])
                        .filter((row) => {
                            const key = row.date_raw || row.measured_at || JSON.stringify(row);

                            if (seen.has(key)) {
                                return false;
                            }

                            seen.add(key);

                            return !query || String(row.date_raw || '').startsWith(query);
                        })
                        .slice(0, 500);

                    resolve(rows);
                };
                request.onerror = () => reject(request.error);
            });
        }

        function syncFilters() {
            const mode = modeFilter.value;
            const showBzThreshold = mode === 'bz_threshold';
            const showBucket = mode === 'speed_12h_average';

            bzOperatorFilter.classList.toggle('is-hidden', !showBzThreshold);
            bzValueFilter.classList.toggle('is-hidden', !showBzThreshold);
            bucketHoursFilter.classList.toggle('is-hidden', !showBucket);
        }

        function renderRows(rows, mode = 'raw') {
            renderHeader(mode);

            if (!rows.length) {
                resultsEl.innerHTML = `<tr><td colspan="${columnCount(mode)}">Aucun résultat.</td></tr>`;
                return;
            }

            if (mode === 'bz_average') {
                resultsEl.innerHTML = rows.map((row) => `
                    <tr>
                        <td>${escapeHtml(row.period_start || '')}</td>
                        <td>${escapeHtml(row.period_end || '')}</td>
                        <td>${formatValue(row.bz_average)}</td>
                        <td>${formatValue(row.samples)}</td>
                    </tr>
                `).join('');
                return;
            }

            if (mode === 'speed_12h_average') {
                resultsEl.innerHTML = rows.map((row) => `
                    <tr>
                        <td>${escapeHtml(row.bucket_start || '')}</td>
                        <td>${escapeHtml(row.bucket_end || '')}</td>
                        <td>${formatValue(row.speed_average)}</td>
                        <td>${formatValue(row.samples)}</td>
                    </tr>
                `).join('');
                return;
            }

            resultsEl.innerHTML = rows.map((row) => `
                <tr>
                    <td>${escapeHtml(row.measured_at || row.date_raw || '')}</td>
                    <td>${formatValue(row.speed)}</td>
                    <td>${formatValue(row.density)}</td>
                    <td>${formatValue(row.bt)}</td>
                    <td>${formatValue(row.bz)}</td>
                </tr>
            `).join('');
        }

        function renderHeader(mode) {
            if (mode === 'bz_average') {
                tableHeadEl.innerHTML = `
                    <tr>
                        <th>Debut</th>
                        <th>Fin</th>
                        <th>Bz moyen</th>
                        <th>Mesures</th>
                    </tr>
                `;
                return;
            }

            if (mode === 'speed_12h_average') {
                tableHeadEl.innerHTML = `
                    <tr>
                        <th>Debut tranche</th>
                        <th>Fin tranche</th>
                        <th>Vitesse moyenne</th>
                        <th>Mesures</th>
                    </tr>
                `;
                return;
            }

            tableHeadEl.innerHTML = `
                <tr>
                    <th>Date</th>
                    <th>Speed</th>
                    <th>Density</th>
                    <th>Bt</th>
                    <th>Bz</th>
                </tr>
            `;
        }

        function columnCount(mode) {
            return mode === 'raw' || mode === 'bz_threshold' ? 5 : 4;
        }

        function statusText(mode, rowCount) {
            if (mode === 'bz_average') {
                return rowCount ? 'Bz moyen calcule depuis ClickHouse.' : 'Aucune mesure Bz pour cette recherche.';
            }

            if (mode === 'bz_threshold') {
                return `${rowCount} moment(s) ou Bz ${bzOperatorFilter.value} ${bzValueFilter.value}.`;
            }

            if (mode === 'speed_12h_average') {
                return `${rowCount} tranche(s) de vitesse moyenne.`;
            }

            return `${rowCount} resultat(s) depuis ClickHouse.`;
        }

        function formatValue(value) {
            return value === null || value === undefined ? '' : escapeHtml(String(value));
        }

        function escapeHtml(value) {
            return value
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;')
                .replaceAll('"', '&quot;')
                .replaceAll("'", '&#039;');
        }

        async function search(event) {
            event.preventDefault();

            const rawQuery = input.value.trim();
            const query = normalizeQuery(rawQuery);
            const mode = modeFilter.value;
            const params = new URLSearchParams({
                q: rawQuery,
                mode,
                bz_operator: bzOperatorFilter.value,
                bz_value: bzValueFilter.value,
                bucket_hours: bucketHoursFilter.value,
            });

            statusEl.className = '';
            statusEl.textContent = 'Recherche...';

            try {
                const response = await fetch(`/api/search?${params.toString()}`);

                if (!response.ok) {
                    throw new Error('API unavailable');
                }

                const payload = await response.json();
                const rows = payload.rows || [];

                await storeSearch(query, rows);
                renderRows(rows, payload.mode || mode);
                statusEl.textContent = statusText(payload.mode || mode, rows.length);
            } catch (error) {
                const rows = await offlineSearch(query);

                renderRows(rows, 'raw');
                statusEl.className = 'offline';
                statusEl.textContent = `Mode hors ligne: ${rows.length} résultat(s) depuis les anciennes recherches.`;
            }
        }

        modeFilter.addEventListener('change', syncFilters);
        form.addEventListener('submit', search);
        syncFilters();
    </script>
    <script src="/js/offline-auth.js" defer></script>
</body>
</html>

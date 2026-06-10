<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recherche ClickHouse</title>
    <style>
        body {
            font-family: system-ui, sans-serif;
            margin: 24px;
            color: #111;
        }

        form {
            display: flex;
            gap: 8px;
            margin-bottom: 12px;
        }

        input {
            flex: 1;
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

        #status {
            min-height: 22px;
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
            button {
                box-sizing: border-box;
                width: 100%;
                margin-bottom: 8px;
            }

            table {
                display: block;
                overflow-x: auto;
            }
        }
    </style>
</head>
<body>
    <form id="search-form">
        <input id="search-input" name="q" type="search" placeholder="2016, 201607, 20160726..." autocomplete="off" autofocus>
        <button type="submit">Chercher</button>
    </form>

    <div id="status"></div>

    <table>
        <thead>
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

    <script>
        const form = document.querySelector('#search-form');
        const input = document.querySelector('#search-input');
        const statusEl = document.querySelector('#status');
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

        function renderRows(rows) {
            if (!rows.length) {
                resultsEl.innerHTML = '<tr><td colspan="5">Aucun résultat.</td></tr>';
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

            const query = normalizeQuery(input.value.trim());
            statusEl.className = '';
            statusEl.textContent = 'Recherche...';

            try {
                const response = await fetch(`/api/search?q=${encodeURIComponent(query)}`);

                if (!response.ok) {
                    throw new Error('API unavailable');
                }

                const payload = await response.json();
                const rows = payload.rows || [];

                await storeSearch(query, rows);
                renderRows(rows);
                statusEl.textContent = `${rows.length} résultat(s) depuis ClickHouse.`;
            } catch (error) {
                const rows = await offlineSearch(query);

                renderRows(rows);
                statusEl.className = 'offline';
                statusEl.textContent = `Mode hors ligne: ${rows.length} résultat(s) depuis les anciennes recherches.`;
            }
        }

        form.addEventListener('submit', search);
    </script>
</body>
</html>

(() => {
    const usersKey = 'flashmcqween.offlineUsers';
    const sessionKey = 'flashmcqween.offlineSession';
    const deviceKey = 'flashmcqween.deviceKey';
    const iterations = 150000;
    const encoder = new TextEncoder();

    function bytesToBase64(bytes) {
        return btoa(String.fromCharCode(...new Uint8Array(bytes)));
    }

    function base64ToBytes(value) {
        return Uint8Array.from(atob(value), (char) => char.charCodeAt(0));
    }

    function randomBase64(length) {
        const bytes = new Uint8Array(length);
        crypto.getRandomValues(bytes);

        return bytesToBase64(bytes);
    }

    function normalizeEmail(email) {
        return String(email || '').trim().toLowerCase();
    }

    function readUsers() {
        try {
            return JSON.parse(localStorage.getItem(usersKey) || '{}');
        } catch (error) {
            return {};
        }
    }

    function writeUsers(users) {
        localStorage.setItem(usersKey, JSON.stringify(users));
    }

    function readSession() {
        try {
            return JSON.parse(localStorage.getItem(sessionKey) || 'null');
        } catch (error) {
            return null;
        }
    }

    function writeSession(session) {
        localStorage.setItem(sessionKey, JSON.stringify({
            ...session,
            loggedAt: new Date().toISOString(),
        }));
    }

    function clearLocalSession() {
        localStorage.removeItem(sessionKey);
    }

    async function verifier(password, email, salt) {
        const keyMaterial = await crypto.subtle.importKey(
            'raw',
            encoder.encode(`${normalizeEmail(email)}:${password}`),
            'PBKDF2',
            false,
            ['deriveBits'],
        );

        const bits = await crypto.subtle.deriveBits(
            {
                name: 'PBKDF2',
                hash: 'SHA-256',
                salt: base64ToBytes(salt),
                iterations,
            },
            keyMaterial,
            256,
        );

        return bytesToBase64(bits);
    }

    async function aesKey() {
        let rawKey = localStorage.getItem(deviceKey);

        if (!rawKey) {
            rawKey = randomBase64(32);
            localStorage.setItem(deviceKey, rawKey);
        }

        return crypto.subtle.importKey('raw', base64ToBytes(rawKey), 'AES-GCM', false, ['encrypt', 'decrypt']);
    }

    async function encryptPassword(password) {
        const iv = base64ToBytes(randomBase64(12));
        const encrypted = await crypto.subtle.encrypt(
            { name: 'AES-GCM', iv },
            await aesKey(),
            encoder.encode(password),
        );

        return {
            iv: bytesToBase64(iv),
            value: bytesToBase64(encrypted),
        };
    }

    async function decryptPassword(record) {
        const decrypted = await crypto.subtle.decrypt(
            { name: 'AES-GCM', iv: base64ToBytes(record.encryptedPassword.iv) },
            await aesKey(),
            base64ToBytes(record.encryptedPassword.value),
        );

        return new TextDecoder().decode(decrypted);
    }

    async function saveOfflineUser({ name, email, password, offline = false }) {
        const normalizedEmail = normalizeEmail(email);
        const users = readUsers();
        const salt = randomBase64(16);

        users[normalizedEmail] = {
            name: name || users[normalizedEmail]?.name || normalizedEmail,
            email: normalizedEmail,
            salt,
            verifier: await verifier(password, normalizedEmail, salt),
            encryptedPassword: await encryptPassword(password),
            updatedAt: new Date().toISOString(),
        };

        writeUsers(users);
        writeSession({
            name: users[normalizedEmail].name,
            email: normalizedEmail,
            offline,
        });
    }

    async function verifyOfflineUser(email, password) {
        const normalizedEmail = normalizeEmail(email);
        const record = readUsers()[normalizedEmail];

        if (!record) {
            return null;
        }

        const candidate = await verifier(password, normalizedEmail, record.salt);

        return candidate === record.verifier ? record : null;
    }

    function csrfFromDocument() {
        return document.querySelector('meta[name="csrf-token"]')?.content
            || document.querySelector('input[name="_token"]')?.value
            || '';
    }

    async function freshCsrfToken() {
        const response = await fetch('/login', {
            credentials: 'same-origin',
            headers: { Accept: 'text/html' },
        });
        const html = await response.text();
        const documentFragment = new DOMParser().parseFromString(html, 'text/html');

        return documentFragment.querySelector('input[name="_token"]')?.value
            || documentFragment.querySelector('meta[name="csrf-token"]')?.content
            || csrfFromDocument();
    }

    async function postJson(action, formData, csrfToken = csrfFromDocument()) {
        formData.set('_token', csrfToken);

        return fetch(action, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken,
            },
            body: formData,
        });
    }

    function unavailableError(message = 'Serveur indisponible.') {
        const error = new Error(message);
        error.offlineEligible = true;

        return error;
    }

    async function submitOnline(form) {
        let formData = new FormData(form);
        let response;

        try {
            response = await postJson(form.action, formData);
        } catch (error) {
            throw unavailableError();
        }

        if (response.status === 419) {
            formData = new FormData(form);

            try {
                response = await postJson(form.action, formData, await freshCsrfToken());
            } catch (error) {
                throw unavailableError();
            }
        }

        const payload = await response.json().catch(() => ({}));

        if (!response.ok) {
            const error = new Error(payload.message || 'Connexion refusee.');
            error.offlineEligible = response.status >= 500;

            throw error;
        }

        return payload;
    }

    function showError(message) {
        let error = document.querySelector('#offline-auth-error');

        if (!error) {
            error = document.createElement('div');
            error.id = 'offline-auth-error';
            error.className = 'error';
            document.querySelector('main')?.prepend(error);
        }

        error.textContent = message;
        error.hidden = false;
    }

    function populateLoginHistory() {
        const list = document.querySelector('#offline-users');

        if (!list) {
            return;
        }

        list.innerHTML = Object.values(readUsers())
            .sort((a, b) => String(a.email).localeCompare(String(b.email)))
            .map((user) => `<option value="${escapeHtml(user.email)}">${escapeHtml(user.name)}</option>`)
            .join('');
    }

    function escapeHtml(value) {
        return String(value)
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    async function handleLogin(event) {
        event.preventDefault();

        const form = event.currentTarget;
        const email = normalizeEmail(form.email.value);
        const password = form.password.value;

        try {
            const payload = await submitOnline(form);
            await saveOfflineUser({
                name: payload.user?.name || email,
                email,
                password,
                offline: false,
            });

            window.location.href = payload.redirect || '/';
        } catch (error) {
            if (navigator.onLine && !error.offlineEligible) {
                showError(error.message);
                return;
            }

            const user = await verifyOfflineUser(email, password);

            if (!user) {
                showError('Aucun login hors ligne ne correspond a ces identifiants sur ce navigateur.');
                return;
            }

            writeSession({
                name: user.name,
                email: user.email,
                offline: true,
            });

            window.location.href = '/';
        }
    }

    async function handleRegister(event) {
        event.preventDefault();

        const form = event.currentTarget;
        const email = normalizeEmail(form.email.value);
        const password = form.password.value;

        try {
            const payload = await submitOnline(form);
            await saveOfflineUser({
                name: payload.user?.name || form.name.value,
                email,
                password,
                offline: false,
            });

            window.location.href = payload.redirect || '/';
        } catch (error) {
            showError(navigator.onLine
                ? error.message
                : 'Il faut une connexion pour creer un nouveau compte.');
        }
    }

    async function reloginOnline() {
        const session = readSession();

        if (!navigator.onLine || !session?.offline) {
            return;
        }

        const record = readUsers()[normalizeEmail(session.email)];

        if (!record) {
            return;
        }

        try {
            const password = await decryptPassword(record);
            const formData = new FormData();
            formData.set('email', record.email);
            formData.set('password', password);
            formData.set('remember', '1');

            const response = await postJson('/login', formData, await freshCsrfToken());

            if (!response.ok) {
                return;
            }

            const payload = await response.json().catch(() => ({}));
            await saveOfflineUser({
                name: payload.user?.name || record.name,
                email: record.email,
                password,
                offline: false,
            });

            window.location.reload();
        } catch (error) {
            // Keep the local offline session; another online event can retry later.
        }
    }

    function hydrateOfflineIndex() {
        const session = readSession();
        const label = document.querySelector('#auth-user-label');

        if (label && session?.offline) {
            label.textContent = `Connecte hors ligne en tant que ${session.name || session.email}`;
        }

        document.querySelector('#logout-form')?.addEventListener('submit', (event) => {
            clearLocalSession();

            if (!navigator.onLine) {
                event.preventDefault();
                window.location.href = '/login';
            }
        });
    }

    function redirectOfflineSessionFromLogin() {
        const session = readSession();

        if (session?.offline && document.querySelector('#login-form')) {
            window.location.href = '/';
        }
    }

    async function registerServiceWorker() {
        if (!('serviceWorker' in navigator)) {
            return;
        }

        try {
            await navigator.serviceWorker.register('/offline-auth-sw.js');
        } catch (error) {
            // Offline auth still works on an already loaded page without the worker.
        }
    }

    document.querySelector('#login-form')?.addEventListener('submit', handleLogin);
    document.querySelector('#register-form')?.addEventListener('submit', handleRegister);
    window.addEventListener('online', reloginOnline);
    populateLoginHistory();
    hydrateOfflineIndex();
    registerServiceWorker();
    redirectOfflineSessionFromLogin();
    reloginOnline();
})();

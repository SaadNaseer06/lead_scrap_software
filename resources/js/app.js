import axios from 'axios';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { registerWebPush } from './webpush-client';

window.axios = axios;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

window.Pusher = Pusher;

function readCsrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}

function syncAxiosCsrf() {
    const t = readCsrfToken();
    if (t) {
        window.axios.defaults.headers.common['X-CSRF-TOKEN'] = t;
    }
    return t;
}

function refreshEchoCsrf() {
    const t = readCsrfToken();
    if (t && window.Echo?.options?.auth?.headers) {
        window.Echo.options.auth.headers['X-CSRF-TOKEN'] = t;
    }
}

const pusherKey = import.meta.env.VITE_PUSHER_APP_KEY;

if (pusherKey) {
    try {
        syncAxiosCsrf();
        const scheme = import.meta.env.VITE_PUSHER_SCHEME || 'https';
        const customHost = import.meta.env.VITE_PUSHER_HOST;

        const echoOptions = {
            broadcaster: 'pusher',
            key: pusherKey,
            cluster: import.meta.env.VITE_PUSHER_APP_CLUSTER || 'mt1',
            forceTLS: scheme === 'https',
            disableStats: true,
            authEndpoint: new URL('/broadcasting/auth', window.location.origin).toString(),
            auth: {
                headers: {
                    'X-CSRF-TOKEN': readCsrfToken(),
                    Accept: 'application/json',
                },
            },
        };

        if (customHost) {
            const port = import.meta.env.VITE_PUSHER_PORT ? Number(import.meta.env.VITE_PUSHER_PORT) : 443;
            echoOptions.wsHost = customHost;
            echoOptions.wsPort = port;
            echoOptions.wssPort = port;
        }

        window.Echo = new Echo(echoOptions);
        refreshEchoCsrf();
        try {
            window.Echo.connector?.pusher?.connection?.bind?.('connected', () => {
                refreshEchoCsrf();
            });
        } catch {
            /* noop */
        }
    } catch (e) {
        console.warn('Laravel Echo failed to initialize; live Pusher updates disabled.', e);
    }
}

document.addEventListener('livewire:init', () => {
    syncAxiosCsrf();
    refreshEchoCsrf();
});

document.addEventListener('livewire:navigated', () => {
    syncAxiosCsrf();
    refreshEchoCsrf();
});

function whenDocumentReady(fn) {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', fn, { once: true });
    } else {
        fn();
    }
}

window.__leadproTryWebPush = () => registerWebPush();

whenDocumentReady(() => {
    setTimeout(() => registerWebPush(), 0);
});

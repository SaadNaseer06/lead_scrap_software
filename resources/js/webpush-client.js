function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = window.atob(base64);
    const outputArray = new Uint8Array(rawData.length);
    for (let i = 0; i < rawData.length; ++i) {
        outputArray[i] = rawData.charCodeAt(i);
    }
    return outputArray;
}

function metaContent(name) {
    return document.querySelector(`meta[name="${name}"]`)?.getAttribute('content') ?? '';
}

/** Avoid hammering /webpush/subscribe on every 2s Livewire poll */
let lastWebPushAttemptAt = 0;
const WEB_PUSH_MIN_INTERVAL_MS = 8000;

/**
 * Register service worker + push subscription and POST to Laravel.
 * Called on first page load and when notifications are refreshed (debounced).
 */
export async function registerWebPush() {
    const now = Date.now();
    if (now - lastWebPushAttemptAt < WEB_PUSH_MIN_INTERVAL_MS) {
        return { ok: false, reason: 'debounced' };
    }
    lastWebPushAttemptAt = now;

    const vapidPublic = metaContent('webpush-vapid-public');
    const userId = metaContent('auth-user-id');

    if (!vapidPublic || !userId || !('serviceWorker' in navigator) || !('PushManager' in window)) {
        return { ok: false, reason: 'missing-meta-or-api' };
    }

    if (!('Notification' in window)) {
        return { ok: false, reason: 'no-notification-api' };
    }

    try {
        let permission = Notification.permission;
        if (permission === 'default') {
            permission = await Notification.requestPermission();
        }
        if (permission !== 'granted') {
            console.info(
                '[WebPush] Notifications not granted (permission: %s). Allow this site in browser settings if you want OS alerts.',
                permission,
            );
            return { ok: false, reason: `permission-${permission}` };
        }

        const reg = await navigator.serviceWorker.register('/sw.js', { scope: '/', updateViaCache: 'none' });
        await reg.update();
        let sub = await reg.pushManager.getSubscription();
        if (!sub) {
            sub = await reg.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(vapidPublic),
            });
        }

        const json = sub.toJSON();
        const csrf = metaContent('csrf-token');

        const res = await fetch('/webpush/subscribe', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrf,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(json),
        });

        if (!res.ok) {
            const text = await res.text().catch(() => '');
            throw new Error(`Subscribe HTTP ${res.status}: ${text.slice(0, 200)}`);
        }

        return { ok: true };
    } catch (e) {
        console.warn('Web push subscribe failed', e);
        return { ok: false, reason: 'error', error: e };
    }
}

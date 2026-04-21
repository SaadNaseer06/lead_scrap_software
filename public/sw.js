self.addEventListener('push', function (event) {
    let payload = { title: 'LeadPro', body: '' };
    if (event.data) {
        try {
            payload = { ...payload, ...event.data.json() };
        } catch {
            payload.body = event.data.text();
        }
    }
    const title = payload.title || 'LeadPro';
    // Unique tag per push — same tag replaces the previous OS notification (looks like only one ever fires).
    const tag = payload.tag || 'lead-fallback-' + Date.now() + '-' + Math.random().toString(16).slice(2);
    const options = {
        body: payload.body || '',
        icon: payload.icon || undefined,
        badge: payload.badge || undefined,
        tag,
        data: payload.data || { url: '/dashboard' },
        requireInteraction: Boolean(payload.requireInteraction),
        renotify: Boolean(payload.renotify),
        silent: Boolean(payload.silent),
    };
    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', function (event) {
    event.notification.close();
    const data = event.notification.data || {};
    const url = data.url || '/dashboard';
    event.waitUntil(
        self.clients.openWindow ? self.clients.openWindow(url) : Promise.resolve(),
    );
});

self.addEventListener('install', function () {
    self.skipWaiting();
});

self.addEventListener('activate', function (event) {
    event.waitUntil(self.clients.claim());
});

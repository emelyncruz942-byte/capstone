self.addEventListener('push', event => {
    let payload = {};
    try {
        payload = event.data?.json() ?? {};
    } catch (error) {
        payload = { body: event.data?.text() ?? 'MathVerse has a new notification.' };
    }

    const title = payload.title || 'MathVerse Notification';
    const options = {
        body: payload.body || 'A new item needs your attention.',
        icon: '/logo.png',
        badge: '/logo.png',
        tag: payload.tag || 'mathverse-notification',
        renotify: true,
        data: { url: payload.url || '/' },
    };

    event.waitUntil(Promise.all([
        self.registration.showNotification(title, options),
        notifyOpenMathVerseWindows(),
    ]));
});

self.addEventListener('notificationclick', event => {
    event.notification.close();
    const destination = safeDestination(event.notification.data?.url);

    event.waitUntil((async () => {
        const windows = await clients.matchAll({ type: 'window', includeUncontrolled: true });
        for (const client of windows) {
            if (new URL(client.url).origin === self.location.origin) {
                await client.navigate(destination);
                return client.focus();
            }
        }
        return clients.openWindow(destination);
    })());
});

async function notifyOpenMathVerseWindows() {
    const windows = await clients.matchAll({ type: 'window', includeUncontrolled: true });
    windows.forEach(client => client.postMessage({ type: 'mathverse:notifications-changed' }));
}

function safeDestination(value) {
    try {
        const candidate = new URL(String(value || '/'), self.location.origin);
        return candidate.origin === self.location.origin
            ? candidate.href
            : `${self.location.origin}/`;
    } catch (error) {
        return `${self.location.origin}/`;
    }
}

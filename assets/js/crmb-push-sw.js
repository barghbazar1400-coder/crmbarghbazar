/* Dedicated CRM push worker. No fetch interception, cache, or confidential text. */
self.addEventListener('push', function (event) {
    var data = {};
    try { data = event.data ? event.data.json() : {}; } catch (error) { data = {}; }
    var url = '/main/?crm_section=notifications';
    try {
        var target = new URL(data.url || url, self.location.origin);
        if (target.origin === self.location.origin && target.pathname.replace(/\/+$/, '') === '/main') {
            url = target.pathname + target.search;
        }
    } catch (error) { /* Fall back to the CRM inbox. */ }
    event.waitUntil(self.registration.showNotification('اعلان جدید CRM برق‌بازار', {
        body: 'برای دیدن جزئیات وارد پنل شوید.',
        tag: typeof data.tag === 'string' ? data.tag.substring(0, 80) : 'crmb',
        renotify: false,
        data: { url: url }
    }));
});
self.addEventListener('notificationclick', function (event) {
    event.notification.close();
    var url = (event.notification.data || {}).url || '/main/?crm_section=notifications';
    event.waitUntil(clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (windows) {
        for (var i = 0; i < windows.length; i++) {
            if (new URL(windows[i].url).origin === self.location.origin) {
                return windows[i].navigate(url).then(function (windowClient) {
                    return windowClient ? windowClient.focus() : clients.openWindow(url);
                });
            }
        }
        return clients.openWindow(url);
    }));
});

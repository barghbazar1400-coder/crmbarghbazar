(function () {
    "use strict";
    var cfg = window.crmbNoticeConfig || {};
    var bell = document.querySelector('.crm-topbar-bell');
    var badge = document.querySelector('.crmb-unread-badge');
    var status = document.getElementById('crmb-push-status');
    var enable = document.getElementById('crmb-push-enable');
    var disable = document.getElementById('crmb-push-disable');
    if (!cfg.ajax || !cfg.nonce) return;

    function setStatus(message) {
        if (status) status.textContent = message;
    }
    function post(action, mode, additional) {
        var form = new URLSearchParams();
        form.append('action', action);
        form.append('mode', mode);
        form.append('nonce', cfg.nonce);
        Object.keys(additional || {}).forEach(function (key) {
            form.append(key, additional[key]);
        });
        return fetch(cfg.ajax, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
            body: form.toString(),
            cache: 'no-store'
        }).then(function (res) {
            if (!res.ok) throw new Error('HTTP ' + res.status);
            return res.json();
        }).then(function (json) {
            if (!json.success) throw new Error('Request failed');
            return json.data || {};
        });
    }
    function showCount(count) {
        count = Number(count) || 0;
        if (bell) bell.setAttribute('aria-label', count ? count + ' اعلان خوانده‌نشده' : 'مرکز اعلان‌ها');
        if (badge) {
            badge.textContent = count > 99 ? '99+' : String(count);
            badge.hidden = !count;
        } else if (bell && count) {
            badge = document.createElement('span');
            badge.className = 'crmb-unread-badge';
            badge.textContent = count > 99 ? '99+' : String(count);
            bell.appendChild(badge);
        }
    }
    function poll() {
        if (document.hidden) return;
        post('crmb_notice', 'poll').then(function (data) {
            showCount(data.count);
        }).catch(function () { /* Keep server-rendered count if offline. */ });
    }
    poll();
    window.setInterval(poll, 45000);
    document.addEventListener('visibilitychange', poll);

    var supportsPush = ('serviceWorker' in navigator) && ('PushManager' in window) &&
        ('Notification' in window) && window.isSecureContext;
    function updateButtons(active) {
        if (enable) enable.hidden = active;
        if (disable) disable.hidden = !active;
    }
    function subscription() {
        return navigator.serviceWorker.getRegistration('/').then(function (registration) {
            return registration ? registration.pushManager.getSubscription() : null;
        });
    }
    if (!supportsPush) {
        setStatus('این مرورگر یا اتصال از پوش پشتیبانی نمی‌کند. اعلان‌های داخل پنل فعال می‌مانند.');
        updateButtons(false);
        if (enable) enable.disabled = true;
        return;
    }
    if (!cfg.publicKey) {
        setStatus('ارسال پوش هنوز روی سرور فعال نشده است؛ اعلان‌های داخل پنل کار می‌کنند.');
        if (enable) enable.disabled = true;
        updateButtons(false);
        return;
    }
    subscription().then(function (current) {
        if (!current) {
            updateButtons(false);
            setStatus(Notification.permission === 'denied'
                ? 'اجازه اعلان در تنظیمات مرورگر مسدود شده است.'
                : 'برای دریافت اعلان روی دسکتاپ، پوش را فعال کنید.');
            return;
        }
        return post('crmb_push_device', 'status', { subscription: JSON.stringify(current.toJSON()) })
            .then(function (data) {
                updateButtons(!!data.subscribed);
                setStatus(data.subscribed ? 'پوش برای حساب شما روی این مرورگر فعال است.'
                    : 'این مرورگر قبلاً مجوز داشته؛ برای اتصال به حساب خودتان «فعال‌سازی پوش» را بزنید.');
            });
    }).catch(function () {
        updateButtons(false);
        setStatus('وضعیت پوش برای این حساب قابل تأیید نیست.');
    });

    function keyBytes(base64url) {
        var padding = '='.repeat((4 - base64url.length % 4) % 4);
        var data = atob((base64url + padding).replace(/-/g, '+').replace(/_/g, '/'));
        return Uint8Array.from(data, function (c) { return c.charCodeAt(0); });
    }
    if (enable) enable.addEventListener('click', function () {
        enable.disabled = true;
        setStatus('در حال فعال‌سازی پوش...');
        navigator.serviceWorker.register(cfg.workerUrl, { scope: '/' }).then(function (registration) {
            return Notification.requestPermission().then(function (permission) {
                if (permission !== 'granted') throw new Error('permission');
                return registration.pushManager.getSubscription().then(function (existing) {
                    return existing || registration.pushManager.subscribe({
                        userVisibleOnly: true,
                        applicationServerKey: keyBytes(cfg.publicKey)
                    });
                });
            });
        }).then(function (sub) {
            return post('crmb_push_device', 'subscribe', { subscription: JSON.stringify(sub.toJSON()) });
        }).then(function () {
            updateButtons(true);
            setStatus('پوش دسکتاپ فعال شد.');
        }).catch(function (error) {
            setStatus(error.message === 'permission' ? 'مجوز اعلان صادر نشد.' :
                'فعال‌سازی پوش ناموفق بود. اتصال و تنظیمات مرورگر را بررسی کنید.');
        }).finally(function () { enable.disabled = false; });
    });
    if (disable) disable.addEventListener('click', function () {
        disable.disabled = true;
        subscription().then(function (sub) {
            if (!sub) return null;
            return post('crmb_push_device', 'unsubscribe', { subscription: JSON.stringify(sub.toJSON()) })
                .then(function () { return sub.unsubscribe(); });
        }).then(function () {
            updateButtons(false);
            setStatus('پوش این مرورگر غیرفعال شد.');
        }).catch(function () { setStatus('غیرفعال‌سازی انجام نشد؛ دوباره تلاش کنید.'); })
            .finally(function () { disable.disabled = false; });
    });
})();

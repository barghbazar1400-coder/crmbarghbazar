/* CRM notifications: live inbox + opt-in Windows/browser toasts + optional Web Push. */
(function () {
    'use strict';
    var cfg = window.crmbNoticeConfig || {};
    if (!cfg.ajax || !cfg.nonce || !cfg.userId) return;

    var bell = document.querySelector('.crm-topbar-bell');
    var badge = document.querySelector('.crmb-unread-badge');
    var status = document.getElementById('crmb-push-status');
    var enable = document.getElementById('crmb-push-enable');
    var disable = document.getElementById('crmb-push-disable');
    var test = document.getElementById('crmb-push-test');
    var background = document.getElementById('crmb-push-background');
    var soundToggle = document.getElementById('crmb-sound-toggle');
    var soundTest = document.getElementById('crmb-sound-test');
    var soundStatus = document.getElementById('crmb-sound-status');
    var username = String(cfg.userId);
    var consentKey = 'crmb-desktop-consent:' + username;
    var notifiedKey = 'crmb-desktop-notified:' + username;
    var soundKey = 'crmb-message-sound:' + username;
    var soundNotifiedKey = 'crmb-message-sound-last:' + username;
    var seenId = null;
    var requestBusy = false;
    var lastCount = null;
    var lastInboxSync = 0;
    var liveList = document.getElementById('crmb-messages-live-list');
    var isInbox = !!liveList && new URL(window.location.href).searchParams.get('box') !== 'sent'
        && (!new URL(window.location.href).searchParams.get('work_page')
            || new URL(window.location.href).searchParams.get('work_page') === '1');

    var desktopSupported = ('Notification' in window) && window.isSecureContext;
    var pushSupported = desktopSupported && ('serviceWorker' in navigator) && ('PushManager' in window);
    var serverReady = !!cfg.publicKey;
    var volatileConsent = false;
    var volatileNotified = 0;
    var inboxBusy = false;
    var pushLinked = false;
    var audioContext = null;
    var soundBusy = false;
    var lastSoundAt = 0;
    var volatileSoundNotified = 0;

    function safeGet(key) {
        try { return window.localStorage.getItem(key); } catch (err) { return null; }
    }
    function safeSet(key, value) {
        try { window.localStorage.setItem(key, String(value)); } catch (err) { /* Private browsing. */ }
    }
    function enabled() {
        var saved = safeGet(consentKey);
        return desktopSupported && window.Notification.permission === 'granted'
            && (saved === null ? volatileConsent : saved === '1');
    }
    function storeConsent(allow) {
        volatileConsent = allow;
        safeSet(consentKey, allow ? '1' : '0');
    }
    function setStatus(message) {
        if (status) status.textContent = message;
    }
    function setButtons() {
        var isEnabled = enabled();
        if (enable) {
            enable.hidden = isEnabled;
            enable.disabled = !desktopSupported;
        }
        if (disable) disable.hidden = !isEnabled;
        if (test) test.hidden = !isEnabled;
        if (background) background.hidden = !isEnabled || !serverReady || !pushSupported || pushLinked;
    }
    /*
     * Distinct, short original notification chime, generated with Web Audio.
     * No downloaded copyrighted audio, public file URL, or private message text.
     * Requires user gesture to unlock on Chrome/Edge after loading the page.
     */
    function soundOn() {
        return safeGet(soundKey) === '1';
    }
    function soundSupported() {
        return !!(window.AudioContext || window.webkitAudioContext);
    }
    function soundState() {
        var on = soundOn();
        if (soundToggle) {
            soundToggle.disabled = !soundSupported();
            soundToggle.setAttribute('aria-pressed', on ? 'true' : 'false');
            soundToggle.innerHTML = on
                ? '<i class="fa-solid fa-volume-xmark" aria-hidden="true"></i> بی‌صدا کردن پیام‌ها'
                : '<i class="fa-solid fa-volume-high" aria-hidden="true"></i> فعال‌سازی صدای پیام';
        }
        if (soundTest) soundTest.hidden = !on || !soundSupported();
        if (soundStatus) {
            soundStatus.textContent = !soundSupported()
                ? 'مرورگر فعلی امکان پخش صدای اختصاصی را ندارد.'
                : !on ? 'صدای پیام‌ها خاموش است.'
                : 'صدای پیام‌ها روشن است؛ در مرورگر باز، پیام تازه با زنگ کوتاه همراه می‌شود.';
        }
    }
    function ensureAudioContext() {
        var Audio = window.AudioContext || window.webkitAudioContext;
        if (!Audio) return Promise.reject(new Error('unsupported'));
        try {
            if (!audioContext || audioContext.state === 'closed') audioContext = new Audio();
            if (audioContext.state === 'suspended') {
                return Promise.resolve(audioContext.resume()).then(function () { return audioContext; });
            }
            return Promise.resolve(audioContext);
        } catch (err) { return Promise.reject(err); }
    }
    function playChime(testMode) {
        if (!testMode && !soundOn()) return Promise.resolve(false);
        if (soundBusy) return Promise.resolve(false);
        if (!testMode && Date.now() - lastSoundAt < 6500) return Promise.resolve(false);
        soundBusy = true;
        return ensureAudioContext().then(function (ctx) {
            if (ctx.state !== 'running') return false;
            var base = ctx.currentTime + 0.015;
            // Gentle three-tone sequence, under half a second in total.
            [740, 990, 840].forEach(function (frequency, index) {
                var start = base + index * 0.135;
                var oscillator = ctx.createOscillator();
                var gain = ctx.createGain();
                oscillator.type = 'sine';
                oscillator.frequency.setValueAtTime(frequency, start);
                gain.gain.setValueAtTime(0.0001, start);
                gain.gain.exponentialRampToValueAtTime(0.085, start + 0.018);
                gain.gain.exponentialRampToValueAtTime(0.0001, start + 0.12);
                oscillator.connect(gain);
                gain.connect(ctx.destination);
                oscillator.start(start);
                oscillator.stop(start + 0.125);
            });
            lastSoundAt = Date.now();
            return true;
        }).catch(function () { return false; }).finally(function () { soundBusy = false; });
    }
    function notifyNewMessages(entries) {
        if (!entries.length || !soundOn()) return;
        var newestMessage = entries.filter(function (item) {
            return item.kind === 'message' && !item.read;
        }).reduce(function (max, item) { return Math.max(max, Number(item.id) || 0); }, 0);
        if (!newestMessage) return;
        // Coordinate tabs for one employee; do not ring repeatedly on refresh.
        var lastHeard = Math.max(volatileSoundNotified, Number(safeGet(soundNotifiedKey)) || 0);
        if (newestMessage <= lastHeard) return;
        volatileSoundNotified = newestMessage;
        safeSet(soundNotifiedKey, newestMessage);
        playChime(false);
    }
    if (soundToggle) soundToggle.addEventListener('click', function () {
        if (soundOn()) {
            safeSet(soundKey, '0');
            soundState();
            return;
        }
        // Called immediately from click, not after an asynchronous polling request:
        // this lets the browser unlock AudioContext with the user's gesture.
        safeSet(soundKey, '1');
        var result = playChime(true);
        soundState();
        Promise.resolve(result).then(function (played) {
            if (!played && soundStatus) {
                soundStatus.textContent = 'صدا فعال شد، اما مرورگر هنوز اجازه پخش نداده است. دوباره «امتحان صدا» را بزنید.';
            }
        });
    });
    if (soundTest) soundTest.addEventListener('click', function () {
        playChime(true).then(function (played) {
            if (soundStatus) soundStatus.textContent = played
                ? 'صدای آزمایشی پخش شد. اگر نشنیدید، صدای تب، مرورگر و ویندوز را بررسی کنید.'
                : 'مرورگر اجازه پخش صدا نداد؛ یک بار روی صفحه کلیک کنید و دوباره تست بگیرید.';
        });
    });
    soundState();
    // A saved preference cannot override browser autoplay restrictions. Unlock
    // after the employee next interacts with the page; never auto-play on load.
    function unlockAudioOnGesture() {
        if (soundOn() && soundSupported()) ensureAudioContext().catch(function () {});
    }
    document.addEventListener('pointerdown', unlockAudioOnGesture, { passive: true });
    document.addEventListener('keydown', unlockAudioOnGesture);
    function post(action, mode, additional) {
        var form = new URLSearchParams();
        form.append('action', action);
        form.append('mode', mode);
        form.append('nonce', cfg.nonce);
        Object.keys(additional || {}).forEach(function (key) { form.append(key, additional[key]); });
        return fetch(cfg.ajax, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
            body: form.toString(),
            cache: 'no-store'
        }).then(function (response) {
            if (!response.ok) throw new Error('HTTP ' + response.status);
            return response.json();
        }).then(function (json) {
            if (!json.success) throw new Error('Request failed');
            return json.data || {};
        });
    }
    function safeDestination(href) {
        try {
            var address = new URL(href || '/main/?crm_section=notifications', location.origin);
            if (address.origin !== location.origin || address.pathname.replace(/\/+$/, '') !== '/main') {
                return '/main/?crm_section=notifications';
            }
            return address.pathname + address.search;
        } catch (err) {
            return '/main/?crm_section=notifications';
        }
    }
    function notificationTitle(kind) {
        var types = {
            message: 'پیام جدید داری',
            task: 'وظیفه جدید در CRM',
            manager: 'راهنمایی جدید مدیر',
            task_done: 'یک وظیفه تکمیل شد',
            announcement: 'اطلاعیه جدید مدیریت'
        };
        return Object.prototype.hasOwnProperty.call(types, kind) ? types[kind] : 'اعلان جدید CRM برق‌بازار';
    }
    function showDesktop(item, isTest) {
        if (!enabled()) return;
        var title = isTest ? 'اعلان آزمایشی CRM برق‌بازار' : notificationTitle(item.kind);
        var url = safeDestination(item.href);
        var options = {
            body: isTest ? 'اگر این پیام را کنار ساعت ویندوز می‌بینید، اعلان مرورگر فعال است.'
                : 'برای مشاهده جزئیات وارد CRM برق‌بازار شوید.',
            tag: isTest ? 'crmb-test-' + Date.now() : 'crmb-' + Number(item.id),
            renotify: false,
            data: { url: url }
        };
        // A native Notification is displayed by Windows/Chrome in the OS notification area.
        // Matching tags also reduce duplicates with the later server Web Push delivery.
        try {
            var toast = new Notification(title, options);
            toast.onclick = function () {
                window.focus();
                window.location.assign(url);
                toast.close();
            };
        } catch (error) {
            if (pushSupported) {
                navigator.serviceWorker.getRegistration('/').then(function (registration) {
                    if (!registration) throw Error('no_worker');
                    return registration.showNotification(title, options);
                }).catch(function () {
                    if (isTest) setStatus('ویندوز اعلان را نمایش نداد. مجوز مرورگر و تنظیمات Notifications ویندوز را بررسی کنید.');
                });
            } else if (isTest) {
                setStatus('این مرورگر نمایش اعلان روی سیستم را پشتیبانی نمی‌کند.');
            }
        }
    }
    function showCount(count) {
        count = Math.max(0, Number(count) || 0);
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
    function refreshInbox() {
        if (!isInbox || !liveList || inboxBusy) return;
        inboxBusy = true;
        post('crmb_notice', 'messages_html').then(function (data) {
            if (!data.html || !liveList.isConnected) return;
            var holder = document.createElement('div');
            holder.innerHTML = data.html;
            var replacement = holder.querySelector('#crmb-messages-live-list');
            if (!replacement) return;
            liveList.replaceWith(replacement);
            liveList = replacement;
            lastInboxSync = Date.now();
        }).catch(function () { /* Keep the original inbox if the connection fails. */ })
            .finally(function () { inboxBusy = false; });
    }
    function processItems(data) {
        var entries = Array.isArray(data.items) ? data.items : [];
        var latest = entries.reduce(function (max, item) {
            return Math.max(max, Number(item.id) || 0);
        }, 0);
        var count = Number(data.count) || 0;
        showCount(count);
        if (seenId === null) {
            // Baseline existing unread records: opening/reloading a tab should not flood Windows.
            seenId = latest;
            lastCount = count;
            refreshInbox();
            return;
        }
        var newlyArrived = entries.filter(function (item) {
            return (Number(item.id) || 0) > seenId && !item.read;
        }).sort(function (a, b) { return a.id - b.id; });
        seenId = Math.max(seenId, latest);
        // A single chime only for new private messages, not every task or notice.
        notifyNewMessages(newlyArrived);
        if (newlyArrived.length && enabled()) {
            var lastToasted = Math.max(volatileNotified, Number(safeGet(notifiedKey)) || 0);
            var batch = newlyArrived.filter(function (item) {
                return (Number(item.id) || 0) > lastToasted;
            }).slice(-3);
            batch.forEach(function (item) {
                volatileNotified = Math.max(volatileNotified, Number(item.id) || 0);
                safeSet(notifiedKey, volatileNotified);
                showDesktop(item, false);
            });
        }
        var newMessage = newlyArrived.some(function (item) { return item.kind === 'message'; });
        if (isInbox && (newMessage || (lastCount !== null && count !== lastCount))) refreshInbox();
        lastCount = count;
    }
    function poll() {
        // Do not skip hidden tabs: background Chrome can throttle timers, but a native toast
        // still works while the page runs. Closed tabs rely on server Web Push.
        if (requestBusy) return;
        requestBusy = true;
        post('crmb_notice', 'poll').then(processItems).catch(function () {
            // Do not claim a message was received if the server is unreachable.
        }).finally(function () { requestBusy = false; });
    }
    poll();
    window.setInterval(poll, 10000);
    document.addEventListener('visibilitychange', function () {
        poll();
        if (!document.hidden && isInbox && Date.now() - lastInboxSync > 25000) refreshInbox();
    });
    window.addEventListener('focus', poll);
    window.addEventListener('online', poll);
    window.addEventListener('storage', function (event) {
        if (event.key === consentKey) {
            volatileConsent = event.newValue === '1';
            setButtons();
        }
        if (event.key === soundKey) soundState();
        if (event.key === soundNotifiedKey) {
            volatileSoundNotified = Math.max(volatileSoundNotified, Number(event.newValue) || 0);
        }
    });

    function getPushSubscription() {
        if (!pushSupported) return Promise.resolve(null);
        return navigator.serviceWorker.getRegistration('/').then(function (registration) {
            return registration ? registration.pushManager.getSubscription() : null;
        });
    }
    function keyBytes(base64url) {
        var padding = '='.repeat((4 - base64url.length % 4) % 4);
        var data = atob((base64url + padding).replace(/-/g, '+').replace(/_/g, '/'));
        return Uint8Array.from(data, function (character) { return character.charCodeAt(0); });
    }
    function messageForState() {
        if (!desktopSupported) {
            setStatus('اعلان بومی این مرورگر در دسترس نیست؛ ورود به CRM از طریق HTTPS را بررسی کنید.');
        } else if (Notification.permission === 'denied') {
            setStatus('اجازه اعلان برای این سایت در تنظیمات مرورگر مسدود شده است.');
        } else if (enabled() && !serverReady) {
            setStatus('اعلان ویندوز برای تب باز فعال است. برای تب بسته باید ارسال Push روی سرور راه‌اندازی شود.');
        } else if (enabled() && serverReady) {
            setStatus('اعلان ویندوز فعال است. وضعیت Push پس‌زمینه در حال بررسی است.');
        } else {
            setStatus('برای دریافت اعلان ویندوز، ابتدا از همین صفحه اجازه مرورگر را فعال کنید.');
        }
        setButtons();
    }
    messageForState();
    // Respect existing consent given to the previous CRM Push interface.
    if (pushSupported && Notification.permission === 'granted') {
        getPushSubscription().then(function (subscription) {
            if (!subscription) return false;
            return post('crmb_push_device', 'status', {
                subscription: JSON.stringify(subscription.toJSON())
            }).then(function (data) { return !!data.subscribed; });
        }).then(function (registered) {
            pushLinked = !!registered;
            if (registered) storeConsent(true);
            setButtons();
            if (registered) {
                setStatus('اعلان ویندوز و Web Push برای این حساب فعال هستند.');
            } else if (enabled() && serverReady) {
                setStatus('اعلان با تب باز فعال است؛ برای دریافت پیام با تب بسته، دکمه «فعال‌سازی پوش پس‌زمینه» را بزنید.');
            }
        }).catch(function () {
            if (enabled()) setStatus('مجوز ویندوز فعال است، اما وضعیت ارسال پوش سرور مشخص نیست.');
        });
    }

    function registerPush() {
        return navigator.serviceWorker.register(cfg.workerUrl, { scope: '/' }).then(function (registration) {
            return registration.pushManager.getSubscription().then(function (existing) {
                return existing || registration.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: keyBytes(cfg.publicKey)
                });
            });
        }).then(function (subscription) {
            return post('crmb_push_device', 'subscribe', {
                subscription: JSON.stringify(subscription.toJSON())
            });
        }).then(function () {
            pushLinked = true;
            setButtons();
            setStatus('اعلان ویندوز و Web Push برای این حساب فعال شدند؛ مرورگر را برای دریافت پیام با تب بسته هم آزمایش کنید.');
        });
    }
    function enableDesktop() {
        if (!desktopSupported || !enable) return;
        enable.disabled = true;
        setStatus('در حال دریافت مجوز اعلان ویندوز…');
        // Request from the direct click gesture; requesting after async registration
        // loses user activation on some browsers.
        var permission;
        try { permission = Notification.requestPermission(); }
        catch (err) { permission = Promise.reject(err); }
        Promise.resolve(permission).then(function (result) {
            if (result !== 'granted') throw Error('permission');
            storeConsent(true);
            setButtons();
            if (!pushSupported || !serverReady) {
                messageForState();
                return;
            }
            setStatus('مجوز صادر شد؛ در حال ثبت این مرورگر برای پیام‌های پس‌زمینه…');
            return registerPush().catch(function () {
                setStatus('اعلان هنگام باز بودن CRM فعال است؛ ثبت Push برای تب بسته ناموفق بود. مدیر باید سرور و سرویس‌ورکر را بررسی کند.');
            });
        }).catch(function () {
            storeConsent(false);
            setStatus('مجوز اعلان صادر نشد. اجازه اعلان این سایت را در تنظیمات مرورگر بررسی کنید.');
        }).finally(function () {
            enable.disabled = false;
            setButtons();
        });
    }
    if (enable) enable.addEventListener('click', enableDesktop);
    if (background) background.addEventListener('click', function () {
        if (!enabled() || !pushSupported || !serverReady) return;
        background.disabled = true;
        setStatus('در حال ثبت این مرورگر برای ارسال پوش در پس‌زمینه…');
        registerPush().catch(function () {
            setStatus('فعال‌سازی پوش پس‌زمینه ناموفق بود؛ سرویس‌ورکر، کلید VAPID و دسترسی HTTPS را بررسی کنید.');
        }).finally(function () { background.disabled = false; });
    });
    if (disable) disable.addEventListener('click', function () {
        disable.disabled = true;
        storeConsent(false);
        pushLinked = false;
        // Remove the account's push subscription; never remove another account's record.
        getPushSubscription().then(function (subscription) {
            if (!subscription) return;
            return post('crmb_push_device', 'status', {
                subscription: JSON.stringify(subscription.toJSON())
            }).then(function (result) {
                if (!result.subscribed) return;
                return post('crmb_push_device', 'unsubscribe', {
                    subscription: JSON.stringify(subscription.toJSON())
                }).then(function () { return subscription.unsubscribe(); });
            });
        }).then(function () {
            setStatus('اعلان این حساب روی این مرورگر غیرفعال شد.');
        }).catch(function () {
            setStatus('اعلان تب باز غیرفعال شد؛ برای قطع Push پس‌زمینه اتصال سرور را بررسی کنید.');
        }).finally(function () {
            disable.disabled = false;
            setButtons();
        });
    });
    if (test) test.addEventListener('click', function () {
        if (!enabled()) { setStatus('ابتدا مجوز اعلان ویندوز را فعال کنید.'); return; }
        showDesktop({ id: 'test', kind: 'message', href: '/main/?crm_section=messages' }, true);
        setStatus('اعلان آزمایشی ارسال شد. نمایش حباب به تنظیمات Windows Notifications و مرورگر وابسته است.');
    });
})();

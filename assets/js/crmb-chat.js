/* Private CRM messenger: cursor pagination, lightweight live updates, safe DOM. */
(function () {
    'use strict';
    var app = document.getElementById('crmb-messenger');
    var cfg = window.crmbChatConfig || {};
    if (!app || !cfg.ajax || !cfg.nonce) return;

    var historyBox = document.getElementById('crmb-messenger-history');
    var bubbles = document.getElementById('crmb-chat-bubbles');
    var empty = document.getElementById('crmb-chat-empty');
    var list = document.getElementById('crmb-messenger-contacts');
    var search = document.getElementById('crmb-messenger-search-input');
    var find = document.getElementById('crmb-chat-find');
    var lookupResult = document.getElementById('crmb-chat-lookup-result');
    var initialListEmpty = document.getElementById('crmb-chat-list-empty');
    var title = document.getElementById('crmb-messenger-partner');
    var input = document.getElementById('crmb-chat-input');
    var form = document.getElementById('crmb-chat-compose');
    var send = document.getElementById('crmb-chat-send');
    var fileInput = document.getElementById('crmb-chat-attach-input');
    var attachmentTray = document.getElementById('crmb-chat-attachment-tray');
    var attachLabel = document.getElementById('crmb-chat-attach-label');
    var dropOverlay = document.getElementById('crmb-chat-drop-overlay');
    var chatSurface = app.querySelector('.crmb-messenger-conversation');
    var pendingFiles = [];
    var dragDepth = 0;
    var status = document.getElementById('crmb-chat-status');
    var older = document.getElementById('crmb-chat-older');
    var olderButton = document.getElementById('crmb-chat-older-button');
    var jump = document.getElementById('crmb-chat-jump');
    var back = document.getElementById('crmb-chat-back');
    var peer = Number(app.dataset.selectedPeer) || 0;
    var oldest = 0;
    var newest = 0;
    var hasOlder = false;
    var hasNewer = false;
    var loaded = false;
    var busyOlder = false;
    var busyNewer = false;
    var busyInitial = false;
    var busySend = false;
    var contactsBusy = false;
    var generation = 0;
    var lastContactRefresh = 0;
    var ids = new Set();
    var recentContactNames = new Map();
    var lookedUpUsers = new Map();
    var startTokens = new Map();
    var MAX_RENDERED = 240;
    var REFRESH_MS = 8500;
    var FILE_COUNT_LIMIT = 3;
    var FILE_SIZE_LIMIT = 8 * 1024 * 1024;
    var FILE_TOTAL_LIMIT = 20 * 1024 * 1024;
    var FILE_EXTENSIONS = /\.(?:jpe?g|png|gif|webp|pdf|txt|csv|docx|xlsx|pptx)$/i;

    function post(mode, data) {
        var params = new URLSearchParams();
        params.set('action', 'crmb_chat');
        params.set('nonce', cfg.nonce);
        params.set('mode', mode);
        Object.keys(data || {}).forEach(function (key) {
            params.set(key, String(data[key]));
        });
        return fetch(cfg.ajax, {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
            body: params.toString()
        }).then(function (response) {
            return response.json().then(function (json) {
                if (!response.ok || !json.success) {
                    throw new Error(json && json.data && json.data.message || 'دریافت اطلاعات انجام نشد.');
                }
                return json.data || {};
            });
        });
    }
    function postFiles(mode, data, attachments) {
        return new Promise(function (resolve, reject) {
            var params = new FormData();
            params.set('action', 'crmb_chat');
            params.set('nonce', cfg.nonce);
            params.set('mode', mode);
            Object.keys(data || {}).forEach(function (key) {
                params.set(key, String(data[key]));
            });
            attachments.forEach(function (entry) {
                params.append('attachments[]', entry.file, entry.file.name);
            });
            var request = new XMLHttpRequest();
            request.open('POST', cfg.ajax, true);
            request.withCredentials = true;
            request.timeout = 90000;
            request.upload.addEventListener('progress', function (event) {
                if (event.lengthComputable && event.total) {
                    say('در حال آپلود فایل‌ها: ' + Math.round(event.loaded * 100 / event.total) + '٪');
                }
            });
            request.addEventListener('load', function () {
                var result;
                try { result = JSON.parse(request.responseText); }
                catch (_) { reject(new Error('پاسخ سرور معتبر نیست.')); return; }
                if (request.status < 200 || request.status >= 300 || !result.success) {
                    reject(new Error(result && result.data && result.data.message || 'ارسال فایل ناموفق بود.'));
                    return;
                }
                resolve(result.data || {});
            });
            request.addEventListener('timeout', function () { reject(new Error('مدت انتظار برای آپلود به پایان رسید.')); });
            request.addEventListener('error', function () { reject(new Error('اتصال هنگام آپلود قطع شد.')); });
            request.send(params);
        });
    }
    function readableSize(bytes) {
        return bytes >= 1024 * 1024 ? (bytes / (1024 * 1024)).toFixed(1) + ' MB'
            : Math.max(1, Math.round(bytes / 1024)) + ' KB';
    }
    function clearFiles() {
        pendingFiles.forEach(function (entry) { if (entry.url) URL.revokeObjectURL(entry.url); });
        pendingFiles = [];
        fileInput.value = '';
        renderPendingFiles();
    }
    function renderPendingFiles() {
        attachmentTray.replaceChildren();
        attachmentTray.hidden = !pendingFiles.length;
        pendingFiles.forEach(function (entry, index) {
            var item = document.createElement('div');
            item.className = 'crmb-chat-pending-file';
            var picture = document.createElement('span');
            picture.className = 'crmb-chat-pending-image';
            if (entry.url) {
                var img = document.createElement('img');
                img.src = entry.url;
                img.alt = '';
                picture.appendChild(img);
            } else {
                var icon = document.createElement('i');
                icon.className = 'fa-solid fa-file-lines';
                picture.appendChild(icon);
            }
            var details = document.createElement('span');
            details.className = 'crmb-chat-pending-details';
            var name = document.createElement('strong');
            name.textContent = entry.file.name;
            var size = document.createElement('small');
            size.textContent = readableSize(entry.file.size);
            details.append(name, size);
            var remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'crmb-chat-pending-remove';
            remove.textContent = '×';
            remove.setAttribute('aria-label', 'حذف فایل ' + entry.file.name);
            remove.disabled = busySend;
            remove.addEventListener('click', function () {
                if (busySend) return;
                var deleted = pendingFiles.splice(index, 1)[0];
                if (deleted && deleted.url) URL.revokeObjectURL(deleted.url);
                renderPendingFiles();
            });
            item.append(picture, details, remove);
            attachmentTray.appendChild(item);
        });
    }
    function queueFiles(files) {
        if (busySend) return;
        var entries = Array.from(files || []);
        if (!entries.length) return;
        var total = pendingFiles.reduce(function (sum, entry) { return sum + entry.file.size; }, 0);
        var added = 0;
        for (var i = 0; i < entries.length; i++) {
            var file = entries[i];
            if (pendingFiles.length >= FILE_COUNT_LIMIT) {
                say('حداکثر ۳ فایل در یک پیام مجاز است.', true); break;
            }
            if (!FILE_EXTENSIONS.test(file.name || '')) {
                say('فرمت این فایل مجاز نیست: ' + (file.name || 'بدون نام'), true);
                continue;
            }
            if (file.size <= 0 || file.size > FILE_SIZE_LIMIT || total + file.size > FILE_TOTAL_LIMIT) {
                say('هر فایل حداکثر ۸ و مجموع فایل‌ها حداکثر ۲۰ مگابایت است.', true);
                continue;
            }
            var duplicate = pendingFiles.some(function (entry) {
                return entry.file.name === file.name && entry.file.size === file.size &&
                    entry.file.lastModified === file.lastModified;
            });
            if (duplicate) continue;
            var preview = file.type.startsWith('image/') ? URL.createObjectURL(file) : '';
            pendingFiles.push({ file: file, url: preview });
            total += file.size;
            added++;
        }
        if (added) say(peer ? 'فایل‌ها آماده ارسال هستند.' : 'فایل‌ها انتخاب شدند؛ برای ارسال ابتدا یک مخاطب را انتخاب کنید.');
        renderPendingFiles();
    }
    function safeAttachmentUrl(href) {
        try {
            var uri = new URL(href, location.href);
            if (uri.origin !== location.origin || !uri.pathname.endsWith('/admin-ajax.php')
                || uri.searchParams.get('action') !== 'crmb_chat_attachment') return '';
            return uri.href;
        } catch (_) { return ''; }
    }
    function renderReceivedFiles(inside, files) {
        if (!Array.isArray(files) || !files.length) return;
        var container = document.createElement('div');
        container.className = 'crmb-chat-received-files';
        files.forEach(function (file) {
            var href = safeAttachmentUrl(file.url);
            if (!href) return;
            var link = document.createElement('a');
            link.className = 'crmb-chat-file-card';
            link.href = href;
            link.target = '_blank';
            link.rel = 'noopener noreferrer';
            link.title = String(file.name || 'فایل');
            if (file.image) {
                // Hand off click to the in-page private image dialog.
                // The existing link remains an accessible fallback.
                link.setAttribute('data-crmb-chat-image', '');
                link.setAttribute('aria-label', 'نمایش تصویر ' + String(file.name || '') + ' در همین صفحه');
                var img = document.createElement('img');
                img.src = href;
                img.loading = 'lazy';
                img.alt = 'تصویر پیوست: ' + String(file.name || '');
                img.className = 'crmb-chat-inline-image';
                link.appendChild(img);
            } else {
                var icon = document.createElement('i');
                icon.className = 'fa-solid fa-file-arrow-down';
                icon.setAttribute('aria-hidden', 'true');
                link.appendChild(icon);
            }
            var caption = document.createElement('span');
            caption.className = 'crmb-chat-file-caption';
            caption.textContent = String(file.name || 'فایل') + ' · ' + readableSize(Number(file.size) || 0);
            link.appendChild(caption);
            container.appendChild(link);
        });
        inside.appendChild(container);
    }
    // Native file input occupies the full attachment label. It opens the OS
    // picker directly even if unrelated chat JavaScript fails to initialize.
    if (fileInput) {
        fileInput.addEventListener('change', function () {
            queueFiles(this.files);
            this.value = '';
        });
    }
    // Clipboard screenshots: only intercept image data, normal text paste stays untouched.
    input.addEventListener('paste', function (event) {
        if (!event.clipboardData || !event.clipboardData.items) return;
        var pictures = [];
        Array.prototype.forEach.call(event.clipboardData.items, function (item) {
            if (item.kind !== 'file' || !item.type.startsWith('image/')) return;
            var file = item.getAsFile();
            if (!file) return;
            if (['image/png', 'image/jpeg', 'image/gif', 'image/webp'].indexOf(item.type) === -1) {
                say('فرمت این تصویر قابل چسباندن نیست. از PNG، JPG، GIF یا WebP استفاده کنید.', true);
                event.preventDefault();
                return;
            }
            var ext = item.type === 'image/jpeg' ? 'jpg' : item.type === 'image/webp' ? 'webp'
                : item.type === 'image/gif' ? 'gif' : 'png';
            pictures.push(new File([file], 'clipboard-' + Date.now() + '.' + ext, {
                type: item.type, lastModified: Date.now()
            }));
        });
        if (pictures.length) {
            event.preventDefault();
            queueFiles(pictures);
        }
    });
    function draggedFiles(event) {
        return event.dataTransfer && Array.prototype.indexOf.call(event.dataTransfer.types || [], 'Files') !== -1;
    }
    chatSurface.addEventListener('dragenter', function (event) {
        if (!draggedFiles(event)) return;
        event.preventDefault();
        dragDepth++;
        dropOverlay.hidden = false;
    });
    chatSurface.addEventListener('dragover', function (event) {
        if (!draggedFiles(event)) return;
        event.preventDefault();
        event.dataTransfer.dropEffect = peer && !busySend ? 'copy' : 'none';
    });
    chatSurface.addEventListener('dragleave', function (event) {
        if (!draggedFiles(event)) return;
        dragDepth = Math.max(0, dragDepth - 1);
        if (!dragDepth) dropOverlay.hidden = true;
    });
    chatSurface.addEventListener('drop', function (event) {
        if (!draggedFiles(event)) return;
        event.preventDefault();
        dragDepth = 0;
        dropOverlay.hidden = true;
        queueFiles(event.dataTransfer.files);
    });
    // Avoid browser navigation when a user drops an unsupported file on the chat.
    window.addEventListener('dragover', function (event) { if (draggedFiles(event)) event.preventDefault(); });
    window.addEventListener('drop', function (event) {
        if (draggedFiles(event)) event.preventDefault();
        dragDepth = 0;
        dropOverlay.hidden = true;
    });
    window.addEventListener('blur', function () { dragDepth = 0; dropOverlay.hidden = true; });
    function say(message, isError) {
        status.textContent = message || '';
        status.classList.toggle('is-error', !!isError);
    }
    function otherName(id) {
        if (recentContactNames.has(id)) return recentContactNames.get(id);
        var item = list.querySelector('[data-peer="' + id + '"]');
        return item ? item.dataset.name : (lookedUpUsers.get(id) || 'همکار');
    }
    function currentContact() {
        return list.querySelector('.crmb-messenger-contact[data-peer="' + peer + '"]');
    }
    function updateHeader() {
        title.textContent = peer ? otherName(peer) : 'یک همکار را انتخاب کنید';
        input.disabled = !peer;
        send.disabled = !peer || busySend;
        fileInput.disabled = busySend;
        attachLabel.classList.toggle('is-disabled', busySend);
        app.classList.toggle('has-active-peer', !!peer);
        Array.prototype.forEach.call(list.querySelectorAll('.crmb-messenger-contact'), function (entry) {
            var active = Number(entry.dataset.peer) === peer;
            entry.classList.toggle('is-active', active);
            entry.setAttribute('aria-current', active ? 'true' : 'false');
        });
    }
    function updateFlags() {
        older.hidden = !loaded || !hasOlder;
        olderButton.disabled = busyOlder;
        olderButton.textContent = busyOlder ? 'در حال دریافت پیام‌های قبلی…' : '↑ نمایش پیام‌های قدیمی‌تر';
        jump.hidden = !loaded || !peer || (!hasNewer && historyBox.scrollHeight - historyBox.scrollTop - historyBox.clientHeight < 110);
    }
    function updateEdges() {
        var nodes = bubbles.querySelectorAll('.crmb-chat-bubble');
        if (!nodes.length) { oldest = 0; newest = 0; return; }
        oldest = Number(nodes[0].dataset.id) || 0;
        newest = Number(nodes[nodes.length - 1].dataset.id) || 0;
    }
    function makeBubble(item) {
        var article = document.createElement('article');
        article.className = 'crmb-chat-bubble ' + (item.mine ? 'is-mine' : 'is-theirs');
        article.dataset.id = String(item.id);
        article.setAttribute('dir', 'auto');
        var inside = document.createElement('div');
        inside.className = 'crmb-chat-bubble-inside';
        if (item.subject && item.subject !== 'پیام گفتگو') {
            var subject = document.createElement('strong');
            subject.className = 'crmb-chat-message-subject';
            subject.textContent = item.subject;
            inside.appendChild(subject);
        }
        if (item.text) {
            var body = document.createElement('p');
            body.className = 'crmb-chat-message-text';
            body.textContent = item.text;
            inside.appendChild(body);
        }
        renderReceivedFiles(inside, item.files);
        var meta = document.createElement('small');
        meta.className = 'crmb-chat-message-meta';
        meta.textContent = (item.time || '') + (item.mine && item.read ? '  ✓✓' : '');
        inside.appendChild(meta);
        article.appendChild(inside);
        return article;
    }
    function appendBatch(messages, prepend) {
        var fragment = document.createDocumentFragment();
        var count = 0;
        (messages || []).forEach(function (item) {
            var id = Number(item.id);
            if (!id || ids.has(id)) return;
            ids.add(id);
            fragment.appendChild(makeBubble(item));
            count++;
        });
        if (prepend) bubbles.prepend(fragment);
        else bubbles.appendChild(fragment);
        updateEdges();
        if (bubbles.children.length) empty.hidden = true;
        return count;
    }
    function clearThread() {
        bubbles.replaceChildren();
        ids.clear();
        oldest = 0;
        newest = 0;
        loaded = false;
        hasOlder = false;
        hasNewer = false;
        busyOlder = false;
        busyNewer = false;
        busyInitial = false;
        historyBox.scrollTop = 0;
        empty.hidden = false;
        empty.querySelector('strong').textContent = peer ? 'در حال دریافت پیام‌ها…' : 'یک همکار را انتخاب کنید';
        updateFlags();
    }
    function atBottom() {
        return historyBox.scrollHeight - historyBox.scrollTop - historyBox.clientHeight < 125;
    }
    function toBottom() {
        historyBox.scrollTop = historyBox.scrollHeight;
        updateFlags();
    }
    function trimSide(side) {
        var removed = 0;
        while (bubbles.children.length > MAX_RENDERED) {
            var victim = side === 'newest' ? bubbles.lastElementChild : bubbles.firstElementChild;
            if (!victim) break;
            ids.delete(Number(victim.dataset.id));
            victim.remove();
            removed++;
        }
        if (removed) {
            updateEdges();
            if (side === 'newest') hasNewer = true;
            else hasOlder = true;
        }
        return removed;
    }
    function initialThread() {
        if (!peer) { clearThread(); return; }
        var target = peer;
        var current = ++generation;
        clearThread();
        busyInitial = true;
        post('thread', { peer: target, start_token: startTokens.get(target) || '' }).then(function (data) {
            if (generation !== current || peer !== target) return;
            appendBatch(data.messages, false);
            hasOlder = !!data.has_more;
            loaded = true;
            if (!bubbles.children.length) empty.querySelector('strong').textContent = 'هنوز پیامی در این گفتگو وجود ندارد';
            updateFlags();
            toBottom();
            say('');
            refreshContacts(true);
        }).catch(function (error) {
            if (generation !== current) return;
            empty.querySelector('strong').textContent = 'بارگذاری گفتگو ناموفق بود';
            say(error.message, true);
        }).finally(function () {
            if (generation === current) busyInitial = false;
        });
    }
    function loadOlder() {
        if (!peer || !loaded || !hasOlder || busyOlder || busyInitial || !oldest) return;
        var current = generation;
        var target = peer;
        var anchor = oldest;
        var oldHeight = historyBox.scrollHeight;
        var oldTop = historyBox.scrollTop;
        busyOlder = true;
        updateFlags();
        post('thread', { peer: target, before: anchor, start_token: startTokens.get(target) || '' }).then(function (data) {
            if (generation !== current || target !== peer) return;
            appendBatch(data.messages, true);
            hasOlder = !!data.has_more;
            var removed = trimSide('newest');
            // After trimming the tail, preserve the original visual position.
            historyBox.scrollTop = Math.max(0, oldTop + historyBox.scrollHeight - oldHeight);
            if (removed) hasNewer = true;
            updateFlags();
        }).catch(function (error) {
            if (generation === current) say(error.message, true);
        }).finally(function () {
            if (generation === current) { busyOlder = false; updateFlags(); }
        });
    }
    function loadNewer() {
        if (!peer || !loaded || busyNewer || busyInitial || document.hidden) return;
        if (!newest) {
            // A conversation with no history must still receive its first
            // incoming message automatically, without a page reload.
            var emptyPeer = peer;
            var emptyGeneration = generation;
            busyNewer = true;
            post('thread', { peer: emptyPeer, start_token: startTokens.get(emptyPeer) || '' }).then(function (data) {
                if (emptyGeneration !== generation || peer !== emptyPeer) return;
                if (data.messages && data.messages.length) {
                    appendBatch(data.messages, false);
                    hasOlder = !!data.has_more;
                    empty.hidden = true;
                    toBottom();
                    refreshContacts(true);
                }
                updateFlags();
            }).catch(function () { /* Retry on the next polling interval. */ })
                .finally(function () { if (emptyGeneration === generation) busyNewer = false; });
            return;
        }
        var current = generation;
        var target = peer;
        var anchor = newest;
        var stick = atBottom();
        busyNewer = true;
        post('thread', { peer: target, after: anchor, start_token: startTokens.get(target) || '' }).then(function (data) {
            if (generation !== current || target !== peer) return;
            var count = appendBatch(data.messages, false);
            hasNewer = !!data.has_more;
            var oldHeight = historyBox.scrollHeight;
            var oldTop = historyBox.scrollTop;
            var removed = trimSide('oldest');
            if (removed && !stick) historyBox.scrollTop = Math.max(0, oldTop + historyBox.scrollHeight - oldHeight);
            if (count && stick) toBottom();
            if (count) refreshContacts(true);
            updateFlags();
        }).catch(function () { /* Offline: the next poll retries. */ })
            .finally(function () { if (generation === current) busyNewer = false; });
    }
    function switchPeer(id, fromHistory) {
        id = Number(id) || 0;
        if (!id || (!recentContactNames.has(id) && !lookedUpUsers.has(id)
            && !list.querySelector('[data-peer="' + id + '"]'))) return;
        if (peer === id && loaded) {
            app.classList.add('is-chat-visible');
            return;
        }
        if (busySend && peer !== id) { say('ابتدا ارسال فایل یا پیام فعلی را کامل کنید.', true); return; }
        // Files selected before choosing any chat remain queued for the chosen contact.
        if (pendingFiles.length && peer && peer !== id
            && !window.confirm('فایل‌های انتخاب‌شده برای این گفتگو پاک شوند؟')) return;
        if (peer && peer !== id) clearFiles();
        peer = id;
        lookupResult.hidden = true;
        search.value = '';
        Array.prototype.forEach.call(list.children, function (entry) { entry.hidden = false; });
        app.classList.add('is-chat-visible');
        updateHeader();
        if (!fromHistory) {
            var newUrl = new URL(window.location.href);
            newUrl.searchParams.set('crm_section', 'messages');
            newUrl.searchParams.set('peer', String(id));
            newUrl.searchParams.delete('message');
            newUrl.searchParams.delete('box');
            newUrl.searchParams.delete('work_page');
            window.history.pushState({ crmbPeer: id }, '', newUrl.pathname + newUrl.search);
        }
        initialThread();
    }
    function refreshContacts(urgent) {
        if (contactsBusy || (!urgent && Date.now() - lastContactRefresh < 20000)) return;
        contactsBusy = true;
        post('contacts').then(function (data) {
            var contacts = data.contacts || [];
            var filter = search.value.trim().toLocaleLowerCase();
            var top = list.scrollTop;
            var fragment = document.createDocumentFragment();
            recentContactNames.clear();
            if (initialListEmpty) initialListEmpty.hidden = contacts.length > 0;
            contacts.forEach(function (contact) {
                var id = Number(contact.id) || 0;
                if (!id) return;
                recentContactNames.set(id, contact.name);
                var item = document.createElement('button');
                item.type = 'button';
                item.className = 'crmb-messenger-contact' + (id === peer ? ' is-active' : '');
                item.setAttribute('role', 'listitem');
                item.setAttribute('aria-current', id === peer ? 'true' : 'false');
                item.dataset.peer = String(id);
                item.dataset.name = contact.name;
                var avatar = document.createElement('span');
                avatar.className = 'crmb-messenger-person-avatar';
                avatar.setAttribute('aria-hidden', 'true');
                var icon = document.createElement('i');
                icon.className = 'fa-solid fa-user';
                avatar.appendChild(icon);
                var details = document.createElement('span');
                details.className = 'crmb-messenger-contact-details';
                var name = document.createElement('strong');
                name.textContent = contact.name;
                var sub = document.createElement('small');
                sub.textContent = Number(contact.unread) ? 'پیام جدید دارید'
                    : '@' + String(contact.username || '');
                details.append(name, sub);
                item.append(avatar, details);
                if (Number(contact.unread) > 0) {
                    var unread = document.createElement('span');
                    unread.className = 'crmb-messenger-contact-unread';
                    unread.textContent = String(Math.min(99, Number(contact.unread)));
                    item.appendChild(unread);
                }
                item.hidden = !!filter && !filter.startsWith('@')
                    && !contact.name.toLocaleLowerCase().includes(filter);
                fragment.appendChild(item);
            });
            list.replaceChildren(fragment);
            list.scrollTop = top;
            lastContactRefresh = Date.now();
            updateHeader();
        }).catch(function () { /* Keep server-rendered contacts on network failure. */ })
            .finally(function () { contactsBusy = false; });
    }

    Array.prototype.forEach.call(list.querySelectorAll('[data-peer]'), function (node) {
        recentContactNames.set(Number(node.dataset.peer), node.dataset.name);
    });
    updateHeader();
    if (peer) {
        initialThread();
        app.classList.add('is-chat-visible');
    } else {
        clearThread();
    }

    list.addEventListener('click', function (event) {
        var contact = event.target.closest('[data-peer]');
        if (contact && list.contains(contact)) switchPeer(contact.dataset.peer, false);
    });
    function lookupExactUsername() {
        var name = search.value.trim();
        if (!name.startsWith('@') || name.length < 3) {
            lookupResult.textContent = 'برای پیدا کردن همکار جدید نام کاربری کامل را با @ بنویسید.';
            lookupResult.hidden = false;
            return;
        }
        find.disabled = true;
        lookupResult.hidden = false;
        lookupResult.textContent = 'در حال جست‌وجوی نام کاربری…';
        post('lookup', { username: name }).then(function (data) {
            lookupResult.replaceChildren();
            if (data.status !== 'found') {
                lookupResult.textContent = data.status === 'limited'
                    ? 'تعداد جست‌وجوها زیاد است؛ کمی بعد دوباره تلاش کنید.'
                    : 'نام کاربری موردنظر پیدا نشد. نام کامل و دقیق را بررسی کنید.';
                return;
            }
            var id = Number(data.id);
            if (!id) return;
            lookedUpUsers.set(id, String(data.name));
            startTokens.set(id, String(data.start_token || ''));
            var card = document.createElement('div');
            card.className = 'crmb-chat-lookup-card';
            var label = document.createElement('span');
            label.className = 'crmb-chat-lookup-name';
            label.textContent = String(data.name) + ' (@' + String(data.username) + ')';
            var button = document.createElement('button');
            button.type = 'button';
            button.textContent = data.existing ? 'بازکردن گفتگو' : 'شروع گفتگو';
            button.addEventListener('click', function () { switchPeer(id, false); });
            card.append(label, button);
            lookupResult.appendChild(card);
        }).catch(function () {
            lookupResult.textContent = 'جست‌وجو انجام نشد؛ اتصال و وضعیت ورود را بررسی کنید.';
        }).finally(function () { find.disabled = false; });
    }
    find.addEventListener('click', lookupExactUsername);
    search.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            if (search.value.trim().startsWith('@')) lookupExactUsername();
        }
    });
    search.addEventListener('input', function () {
        var filter = search.value.trim().toLocaleLowerCase();
        lookupResult.hidden = true;
        Array.prototype.forEach.call(list.children, function (item) {
            item.hidden = filter.startsWith('@') || (!!filter &&
                !String(item.dataset.name || '').toLocaleLowerCase().includes(filter));
        });
    });
    back.addEventListener('click', function () { app.classList.remove('is-chat-visible'); });
    olderButton.addEventListener('click', loadOlder);
    jump.addEventListener('click', function () {
        // Reset to the newest page: constant memory even after very long history.
        if (hasNewer) initialThread();
        else toBottom();
    });
    historyBox.addEventListener('scroll', function () {
        updateFlags();
        if (historyBox.scrollTop < 110 && hasOlder) loadOlder();
        if (atBottom() && hasNewer) loadNewer();
    }, { passive: true });
    window.addEventListener('popstate', function () {
        var id = Number(new URL(window.location.href).searchParams.get('peer')) || 0;
        if (id && (list.querySelector('[data-peer="' + id + '"]') || recentContactNames.has(id))) switchPeer(id, true);
    });

    function sendMessage() {
        var text = input.value.trim();
        if (!peer || busySend || (!text && !pendingFiles.length)) return;
        if (text.length > 3000) { say('متن پیام طولانی است.', true); return; }
        var target = peer;
        var files = pendingFiles.slice();
        busySend = true;
        send.disabled = true;
        fileInput.disabled = true;
        attachLabel.classList.add('is-disabled');
        renderPendingFiles();
        say(files.length ? 'در حال آپلود فایل‌ها…' : 'در حال ارسال…');
        var data = { peer: target, body: text, start_token: startTokens.get(target) || '' };
        var promise = files.length ? postFiles('send', data, files) : post('send', data);
        promise.then(function () {
            if (target !== peer) return;
            input.value = '';
            input.style.height = '';
            clearFiles();
            say('');
            // A user reading older history must return to the most recent page
            // to avoid a gap between loaded messages and this new message.
            if (hasNewer || !newest) initialThread();
            else loadNewer();
            refreshContacts(true);
        }).catch(function (error) {
            if (target === peer) say(error.message, true);
        }).finally(function () {
            busySend = false;
            send.disabled = !peer;
            fileInput.disabled = false;
            attachLabel.classList.remove('is-disabled');
            renderPendingFiles();
            input.focus();
        });
    }
    form.addEventListener('submit', function (event) {
        event.preventDefault();
        sendMessage();
    });
    input.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
            event.preventDefault();
            sendMessage();
        }
    });
    input.addEventListener('input', function () {
        this.style.height = 'auto';
        this.style.height = Math.min(this.scrollHeight, 132) + 'px';
    });

    window.setInterval(function () {
        if (document.hidden) return;
        refreshContacts(false);
        if (!peer) return;
        if (hasNewer && !atBottom()) {
            refreshContacts(false);
            return;
        }
        if (!busyInitial) loadNewer();
        refreshContacts(false);
    }, REFRESH_MS);
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) return;
        if (hasNewer) refreshContacts(true);
        else loadNewer();
    });
})();

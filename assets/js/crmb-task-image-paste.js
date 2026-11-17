/* CRM Task Progress: paste screenshots as PRIVATE report image attachments. */
(function () {
    'use strict';
    var form = document.getElementById('crmb-task-progress-form');
    var input = document.getElementById('crmb-task-images');
    var preview = document.getElementById('crmb-task-image-preview');
    var feedback = document.getElementById('crmb-task-image-feedback');
    if (!form || !input || !preview) return;

    // No application-level photo count limit. Protect the PHP/database server
    // from an accidentally enormous single image; server upload_max_filesize
    // and post_max_size may be lower than this 64 MiB defensive ceiling.
    var SAFETY_IMAGE_BYTES = 64 * 1024 * 1024;
    var uploadEndpoint = typeof form.getAttribute === 'function'
        ? form.getAttribute('data-photo-ajax') : '';
    var savedEventId = 0;
    var uploading = false;
    var allowed = { 'image/png': 'png', 'image/jpeg': 'jpg', 'image/webp': 'webp' };
    var selected = [];
    var processing = 0;
    var working = Promise.resolve();
     var editorWatcher = null;
    var fileButton = form.querySelector('button[type="submit"]');

    function say(message, error) {
        if (!feedback) return;
        feedback.textContent = message || '';
        feedback.classList.toggle('is-error', !!error);
    }
    function sizeLabel(size) {
        return size >= 1048576 ? (size / 1048576).toFixed(1) + ' مگابایت'
            : Math.ceil(size / 1024) + ' کیلوبایت';
    }
    function validImage(file) {
        return !!file && Object.prototype.hasOwnProperty.call(allowed, file.type) &&
            /\.(?:jpe?g|png|webp)$/i.test(file.name || '');
    }
    function syncInput() {
        if (typeof DataTransfer !== 'function') {
            input.value = '';
            say('این مرورگر مدیریت چند تصویر را پشتیبانی نمی‌کند؛ Chrome یا Edge به‌روز را امتحان کنید.', true);
            return false;
        }
        try {
            var transfer = new DataTransfer();
            selected.forEach(function (entry) { transfer.items.add(entry.file); });
            input.files = transfer.files;
            return input.files.length === selected.length;
        } catch (_) {
            input.value = '';
            say('انتقال تصویر به فرم انجام نشد. Chrome یا Edge به‌روز را امتحان کنید.', true);
            return false;
        }
    }
    function render() {
        preview.replaceChildren();
        selected.forEach(function (entry, index) {
            var card = document.createElement('div');
            card.className = 'crmb-task-pasted-image';
            var image = document.createElement('img');
            image.src = entry.url;
            image.alt = 'پیش‌نمایش تصویر ' + (index + 1);
            image.loading = 'lazy';
            var label = document.createElement('span');
            label.textContent = entry.file.name + ' (' + sizeLabel(entry.file.size) + ')';
            var remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'crmb-task-pasted-remove';
            remove.setAttribute('aria-label', 'حذف تصویر ' + (index + 1));
            remove.textContent = '×';
            remove.disabled = processing > 0 || uploading;
            remove.addEventListener('click', function () {
                if (processing || uploading) return;
                var deleted = selected.splice(index, 1)[0];
                if (!syncInput()) { selected.splice(index, 0, deleted); return; }
                URL.revokeObjectURL(deleted.url);
                render();
                say(selected.length ? selected.length + ' تصویر آماده ثبت است.' : 'تصویری انتخاب نشده است.', false);
            });
            card.append(image, label, remove);
            preview.appendChild(card);
        });
    }
    function addImages(files, pasted) {
        var additions = Array.from(files || []);
        if (!additions.length) return;
        processing++;
        if (fileButton) fileButton.disabled = true;
        working = working.then(async function () {
            for (var i = 0; i < additions.length; i++) {
                var original = additions[i];
                if (!validImage(original)) {
                    say('تنها تصاویر JPG، PNG و WebP قابل ثبت هستند.', true);
                    continue;
                }
                // Preserve original quality and file size; send each picture
                // separately so a large selection never creates one huge POST.
                var file = original;
                if (file.size < 1 || file.size > SAFETY_IMAGE_BYTES) {
                    say('این تک‌عکس بیش از حد ایمنی ۶۴ مگابایت است.', true);
                    continue;
                }
                var exists = selected.some(function (entry) {
                    return entry.file.name === file.name && entry.file.size === file.size &&
                        entry.file.lastModified === file.lastModified;
                });
                if (exists) continue;
                var url = URL.createObjectURL(file);
                selected.push({ file: file, url: url });
                if (!syncInput()) {
                    selected.pop();
                    URL.revokeObjectURL(url);
                    break;
                }
                say('تصویر اضافه شد. ' + selected.length + ' تصویر برای ثبت آماده است.', false);
            }
        }).catch(function () {
            say('افزودن تصویر انجام نشد؛ دوباره امتحان کنید.', true);
        }).finally(function () {
            processing--;
            // The native picker temporarily replaces input.files, including
            // when a chosen file was rejected. Restore ONLY validated items.
            syncInput();
            if (fileButton) fileButton.disabled = processing > 0 || uploading;
            render();
        });
    }
    input.addEventListener('change', function () {
         // File-picker normally replaces input.files. Keep previously pasted images.
        var fresh = Array.from(input.files || []);
        addImages(fresh, false);
    });

    function onPaste(event) {
        var clipboard = event.clipboardData || (event.originalEvent && event.originalEvent.clipboardData);
        if (!clipboard) return;
        var images = [];
        if (clipboard.items && clipboard.items.length) {
            Array.prototype.forEach.call(clipboard.items, function (item) {
                if (item.kind !== 'file' || !item.type || !item.type.startsWith('image/')) return;
                var original = item.getAsFile();
                if (!original) return;
                var ext = allowed[item.type];
                if (!ext) {
                    images.push(original);
                    return;
                }
                images.push(new File([original], 'screenshot-' + Date.now() + '-' +
                    (images.length + 1) + '.' + ext, { type: item.type, lastModified: Date.now() }));
            });
        } else if (clipboard.files) {
            images = Array.from(clipboard.files).filter(function (file) { return file.type.startsWith('image/'); });
        }
        if (!images.length) return; // Ordinary text/HTML paste must keep working.
        event.preventDefault();
        if (typeof event.stopImmediatePropagation === 'function') event.stopImmediatePropagation();
        addImages(images, true);
    }

    // In visual mode TinyMCE's editor lives inside its own iframe document.
    // Capture native paste before the editor inserts a large data: image.
    document.addEventListener('paste', function (event) {
        if (form.contains(event.target)) onPaste(event);
    }, true);

    var attachedEditor = false;
    function bindEditor(editor) {
        if (!editor || editor.id !== 'crmbtaskeditor') return;
        function attach() {
            var doc = typeof editor.getDoc === 'function' ? editor.getDoc() : null;
            if (!doc || doc.__crmbTaskImagePaste) return;
            doc.__crmbTaskImagePaste = true;
            doc.addEventListener('paste', onPaste, true);
            attachedEditor = true;
        }
        if (!editor.__crmbTaskImageSetup) {
            editor.__crmbTaskImageSetup = true;
            if (typeof editor.on === 'function') editor.on('init', attach);
        }
        if (editor.initialized) attach();
    }
    function watchEditor() {
        if (!window.tinymce) return false;
        if (!window.__crmbTaskEditorPasteHooked && typeof window.tinymce.on === 'function') {
            window.__crmbTaskEditorPasteHooked = true;
            window.tinymce.on('AddEditor', function (event) { bindEditor(event.editor); });
        }
        if (typeof window.tinymce.get === 'function') bindEditor(window.tinymce.get('crmbtaskeditor'));
        return attachedEditor;
    }
    // WP/TinyMCE loads asynchronously on some themes. A bounded init retry
    // handles an editor added after our page script without ongoing polling.
    watchEditor();
    var attempts = 0;
    editorWatcher = window.setInterval(function () {
        attempts++;
        if (watchEditor() || attempts >= 60) window.clearInterval(editorWatcher);
    }, 250);

    /**
     * Save the text FIRST, then append each photo to the same immutable event.
     * Failures leave unsent photos visible for retry and never create a
     * duplicate progress note. Non-JS browsers retain native multipart POST.
     */
    function postAjax(action, values, file) {
        var payload = new FormData();
        payload.append('action', action);
        Object.keys(values).forEach(function (key) { payload.append(key, values[key]); });
        if (file) payload.append('image', file, file.name);
        return fetch(uploadEndpoint, {
            method: 'POST', credentials: 'same-origin',
            cache: 'no-store', body: payload
        }).then(function (response) {
            return response.json().then(function (json) {
                if (!response.ok || !json || !json.success) {
                    throw new Error((json && json.data && json.data.message) || 'خطای دریافت پاسخ سرور');
                }
                return json.data || {};
            });
        });
    }
    function fieldValue(selector) {
        var node = form.querySelector(selector);
        return node && typeof node.value === 'string' ? node.value : '';
    }
    form.addEventListener('submit', function (event) {
        if (processing || uploading) {
            event.preventDefault();
            say('در حال آماده‌سازی یا ارسال تصاویر هستیم؛ تا پایان ارسال صبر کنید.', true);
            return;
        }
        // Progressive enhancement; never require JS for ordinary form use.
        if (!uploadEndpoint || typeof fetch !== 'function' || typeof FormData !== 'function'
            || !fieldValue('input[name="crmb_progress_nonce"]')) {
            return;
        }
        event.preventDefault();
        if (window.tinymce && typeof window.tinymce.triggerSave === 'function') {
            window.tinymce.triggerSave();
        }
        var nonce = fieldValue('input[name="crmb_progress_nonce"]');
        var taskId = fieldValue('input[name="id"]');
        var noteType = fieldValue('select[name="note_type"]');
        var noteBody = fieldValue('textarea[name="note_body"]');
        uploading = true;
        input.disabled = true;
        if (fileButton) fileButton.disabled = true;
        (async function () {
            if (!savedEventId) {
                say('در حال ثبت متن گزارش پیشرفت…', false);
                var result = await postAjax('crmb_task_photo_save', {
                    nonce: nonce, id: taskId, note_type: noteType,
                    note_body: noteBody, has_images: selected.length ? '1' : '0'
                });
                savedEventId = Number(result.event_id) || 0;
                if (!savedEventId) throw new Error('شناسه گزارش ذخیره‌شده دریافت نشد.');
            }
            var total = selected.length, uploaded = 0, failures = [];
            // Exactly one image per HTTP request: no max_file_uploads limit
            // from bundling dozens of files into a single native form.
            for (var i = 0, queue = selected.slice(); i < queue.length; i++) {
                var entry = queue[i];
                try {
                    say('در حال ارسال عکس ' + (i + 1) + ' از ' + total + '…', false);
                    await postAjax('crmb_task_photo_upload', {
                        nonce: nonce, id: taskId, event_id: String(savedEventId)
                    }, entry.file);
                    uploaded++;
                    var index = selected.indexOf(entry);
                    if (index !== -1) selected.splice(index, 1);
                    URL.revokeObjectURL(entry.url);
                } catch (error) {
                    failures.push(error.message || 'ارسال تصویر انجام نشد.');
                }
            }
            syncInput();
            render();
            if (failures.length) {
                say('متن گزارش ذخیره شد و ' + uploaded + ' عکس ارسال شد، اما ' +
                    failures.length + ' عکس باقی مانده است. ' +
                    failures[0] + ' برای تلاش مجدد «ثبت گزارش» را بزنید.', true);
                return;
            }
            say('گزارش و همه ' + uploaded + ' عکس با موفقیت ثبت شدند.', false);
            if (window.location && typeof window.location.reload === 'function') {
                window.location.reload();
            }
        })().catch(function (error) {
            say(error.message || 'ذخیره گزارش انجام نشد؛ دوباره تلاش کنید.', true);
        }).finally(function () {
            uploading = false;
            input.disabled = false;
            if (fileButton) fileButton.disabled = processing > 0;
            render();
        });
    }, true);
    window.addEventListener('pagehide', function () {
        selected.forEach(function (entry) { URL.revokeObjectURL(entry.url); });
    });
})();

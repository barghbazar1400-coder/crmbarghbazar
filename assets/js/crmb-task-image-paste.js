/* CRM Task Progress: paste screenshots as PRIVATE report image attachments. */
(function () {
    'use strict';
    var form = document.getElementById('crmb-task-progress-form');
    var input = document.getElementById('crmb-task-images');
    var preview = document.getElementById('crmb-task-image-preview');
    var feedback = document.getElementById('crmb-task-image-feedback');
    if (!form || !input || !preview) return;

    var MAX_FILES = 3;
    var MAX_BYTES = 1048576; // Match crmb_report_uploads on the server.
    var allowed = { 'image/png': 'png', 'image/jpeg': 'jpg', 'image/webp': 'webp' };
    var selected = [];
    var processing = 0;
    var working = Promise.resolve();
    var suppress = false;
    var editorWatcher = null;
    var fileButton = form.querySelector('button[type="submit"]');

    function say(message, error) {
        if (!feedback) return;
        feedback.textContent = message || '';
        feedback.classList.toggle('is-error', !!error);
    }
    function sizeLabel(size) {
        return Math.ceil(size / 1024) + ' کیلوبایت';
    }
    function validImage(file) {
        return !!file && Object.prototype.hasOwnProperty.call(allowed, file.type) &&
            /\.(?:jpe?g|png|webp)$/i.test(file.name || '');
    }
    function syncInput() {
        if (typeof DataTransfer !== 'function') {
            say('این مرورگر مدیریت چند تصویر را پشتیبانی نمی‌کند؛ Chrome یا Edge به‌روز را امتحان کنید.', true);
            return false;
        }
        try {
            var transfer = new DataTransfer();
            selected.forEach(function (entry) { transfer.items.add(entry.file); });
            input.files = transfer.files;
            return input.files.length === selected.length;
        } catch (_) {
            say('انتقال تصویر به فرم انجام نشد. از انتخاب فایل دستی استفاده کنید.', true);
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
            remove.disabled = processing > 0;
            remove.addEventListener('click', function () {
                if (processing) return;
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
    function optimiseScreenshot(file) {
        if (file.size <= MAX_BYTES) return Promise.resolve(file);
        if (file.size > 25 * 1024 * 1024 || typeof createImageBitmap !== 'function') {
            return Promise.reject(new Error('تصویر بیش از ۱ مگابایت است و امکان بهینه‌سازی آن وجود ندارد.'));
        }
        say('اسکرین‌شات بزرگ است؛ در حال کم‌حجم کردن برای گزارش…', false);
        return createImageBitmap(file).then(async function (bitmap) {
            try {
                var scale = Math.min(1, 2400 / Math.max(bitmap.width, bitmap.height),
                    Math.sqrt(16000000 / (bitmap.width * bitmap.height)));
                for (var attempt = 0; attempt < 4; attempt++) {
                    var canvas = document.createElement('canvas');
                    canvas.width = Math.max(1, Math.floor(bitmap.width * scale));
                    canvas.height = Math.max(1, Math.floor(bitmap.height * scale));
                    var context = canvas.getContext('2d');
                    if (!context) break;
                    context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
                    var blob = await new Promise(function (resolve) {
                        canvas.toBlob(resolve, 'image/webp', Math.max(0.58, 0.88 - attempt * 0.09));
                    });
                    canvas.width = 0; canvas.height = 0;
                    if (blob && blob.type === 'image/webp' && blob.size > 0 && blob.size <= MAX_BYTES) {
                        return new File([blob], file.name.replace(/\.[^.]+$/, '') + '.webp',
                            { type: 'image/webp', lastModified: Date.now() });
                    }
                    scale *= 0.75;
                }
                throw new Error('حجم اسکرین‌شات پس از بهینه‌سازی هنوز بیش از ۱ مگابایت است.');
            } finally {
                if (typeof bitmap.close === 'function') bitmap.close();
            }
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
                if (selected.length >= MAX_FILES) {
                    say('حداکثر ۳ تصویر برای هر گزارش مجاز است.', true);
                    break;
                }
                if (!validImage(original)) {
                    say('تنها تصاویر JPG، PNG و WebP قابل ثبت هستند.', true);
                    continue;
                }
                var file;
                try {
                    file = pasted ? await optimiseScreenshot(original) : original;
                } catch (error) {
                    say(error.message || 'بهینه‌سازی تصویر انجام نشد.', true);
                    continue;
                }
                if (file.size < 1 || file.size > MAX_BYTES) {
                    say('هر تصویر باید حداکثر ۱ مگابایت باشد.', true);
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
            if (fileButton) fileButton.disabled = processing > 0;
            render();
        });
    }
    input.addEventListener('change', function () {
        if (suppress) return;
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
        if (typeof editor.on === 'function') editor.on('init', attach);
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

    form.addEventListener('submit', function (event) {
        if (!processing) return;
        event.preventDefault();
        say('ابتدا پردازش تصویر را کامل کنید؛ سپس گزارش را ثبت کنید.', true);
    }, true);
    window.addEventListener('pagehide', function () {
        selected.forEach(function (entry) { URL.revokeObjectURL(entry.url); });
    });
})();

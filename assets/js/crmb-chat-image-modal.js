/* Chat private image preview; no external link or new tab when dialog is supported. */
(function () {
    'use strict';
    var app = document.getElementById('crmb-messenger');
    var dialog = document.getElementById('crmb-chat-image-modal');
    var photo = document.getElementById('crmb-chat-image-modal-photo');
    var caption = document.getElementById('crmb-chat-image-modal-caption');
    var closeButton = document.getElementById('crmb-chat-image-modal-close');
    if (!app || !dialog || !photo || !caption || !closeButton ||
        typeof dialog.showModal !== 'function' || typeof dialog.close !== 'function') return;

    var opener = null;
    function privateImageUrl(href) {
        try {
            var url = new URL(href, window.location.href);
            return url.origin === window.location.origin &&
                url.pathname.endsWith('/admin-ajax.php') &&
                url.searchParams.get('action') === 'crmb_chat_attachment' &&
                /^[1-9][0-9]*$/.test(url.searchParams.get('file') || '') &&
                !!url.searchParams.get('_wpnonce') ? url.href : '';
        } catch (_) {
            return '';
        }
    }
    function closePreview() {
        if (dialog.open) dialog.close();
    }
    app.addEventListener('click', function (event) {
        var target = event.target;
        if (!target || typeof target.closest !== 'function') return;
        var link = target.closest('a[data-crmb-chat-image]');
        if (!link || !app.contains(link) || !link.querySelector('img.crmb-chat-inline-image')) return;
        // Preserve the normal browser behavior for open-in-new-tab/context menus.
        if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        var url = privateImageUrl(link.href);
        if (!url) return;
        if (!dialog.open) {
            // If showModal fails, preserve the normal image link fallback.
            try { dialog.showModal(); }
            catch (_) { return; }
        }
        event.preventDefault();
        opener = link;
        var image = link.querySelector('img.crmb-chat-inline-image');
        photo.alt = image.alt || 'تصویر پیوست گفتگو';
        caption.textContent = link.title || 'تصویر پیوست گفتگو';
        photo.src = url;
        closeButton.focus();
    });

    closeButton.addEventListener('click', closePreview);
    // Clicking the backdrop (but not the image or its caption) closes the viewer.
    dialog.addEventListener('click', function (event) {
        if (event.target === dialog) closePreview();
    });
    // Native <dialog> closes on Escape. Restore the point of focus afterward.
    dialog.addEventListener('close', function () {
        photo.removeAttribute('src');
        caption.textContent = '';
        if (opener && opener.isConnected && typeof opener.focus === 'function') opener.focus();
        opener = null;
    });
    // Avoid an orphaned private image when the coworker/thread changes.
    var contacts = document.getElementById('crmb-messenger-contacts');
    if (contacts) contacts.addEventListener('click', closePreview);
    window.addEventListener('pagehide', closePreview);
})();

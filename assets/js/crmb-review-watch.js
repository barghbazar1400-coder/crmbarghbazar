/* CRM review queue: auto-refresh the server-rendered manager list when decisions change. */
(function () {
    'use strict';
    var watch = document.getElementById('crmb-review-watch');
    if (!watch || !window.fetch || !window.URLSearchParams) return;
    var refresh = document.getElementById('crmb-review-watch-refresh');
    var nonce = watch.dataset.nonce;
    var endpoint = watch.dataset.endpoint;
    var assigned = watch.dataset.assignee || '0';
    var scope = watch.dataset.scope === 'mine' ? 'mine' : 'team';
    var version = Number(watch.dataset.version) || 0;
    var count = Number(watch.dataset.count) || 0;
    var busy = false;
    var stopped = false;
    function changed(data) {
        return (Number(data.version) || 0) !== version || (Number(data.count) || 0) !== count;
    }
    function draftOpen() {
        return !!document.querySelector('.crmb-tasks-editor[open]');
    }
    function check() {
        if (busy || stopped || document.hidden) return;
        busy = true;
        var body = new URLSearchParams();
        body.set('action', 'crmb_work_review_poll');
        body.set('nonce', nonce);
        body.set('assigned_to', assigned);
        body.set('scope', scope);
        fetch(endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
            cache: 'no-store',
            body: body.toString()
        }).then(function (response) {
            if (!response.ok) throw new Error('unavailable');
            return response.json();
        }).then(function (json) {
            if (!json.success || !json.data || !changed(json.data)) return;
            stopped = true;
            if (draftOpen()) {
                if (refresh) refresh.hidden = false;
            } else {
                // Navigates the same review URL; server renders updated count and
                // cards. Do not reload when a manager is editing an unsaved task.
                window.location.reload();
            }
        }).catch(function () {
            // A failed poll must not replace the existing manager list with zero.
        }).finally(function () { busy = false; });
    }
    window.setInterval(check, 15000);
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) check();
    });
    window.addEventListener('focus', check);
    check();
})();
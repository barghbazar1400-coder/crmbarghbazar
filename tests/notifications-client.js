/* Node regression for native CRM notification polling; no browser/network needed. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(__dirname + '/../assets/js/crmb-notices.js', 'utf8');

function bootstrap(userId, storage, incoming, consentPermission = 'default') {
    const buttons = {};
    const calls = [];
    const toasts = [];
    const events = {};
    const timers = [];
    let permission = consentPermission;
    let requested = 0;

    function element(id) {
        const object = {
            id: id || '',
            textContent: '',
            hidden: false,
            disabled: false,
            listeners: {},
            isConnected: true,
            addEventListener(name, callback) { this.listeners[name] = callback; },
            setAttribute(name, value) { this[name] = value; },
            appendChild() {}
        };
        return object;
    }
    ['crmb-push-enable', 'crmb-push-disable', 'crmb-push-test', 'crmb-push-status'].forEach(key => {
        buttons[key] = element(key);
    });
    const bell = element('bell');
    const document = {
        hidden: false,
        querySelector(selector) {
            if (selector === '.crm-topbar-bell') return bell;
            if (selector === '.crmb-unread-badge') return null;
            return null;
        },
        getElementById(id) { return buttons[id] || null; },
        addEventListener(name, handler) { events[name] = handler; },
        createElement() { return element('dynamic'); }
    };
    class Notification {
        constructor(title, options) {
            toasts.push({ title, options });
            this.close = function() {};
        }
        static get permission() { return permission; }
        static requestPermission() {
            ++requested;
            permission = 'granted';
            return Promise.resolve('granted');
        }
    }
    const window = {
        crmbNoticeConfig: { userId: userId, nonce: 'nonce', ajax: '/wp-admin/admin-ajax.php', publicKey: '' },
        Notification: Notification,
        isSecureContext: true,
        location: { href: 'https://crmbarghbazar.com/main/?crm_section=dashboard' },
        localStorage: {
            getItem(key) { return storage.has(key) ? storage.get(key) : null; },
            setItem(key,value) { storage.set(key,String(value)); }
        },
        setInterval(callback, ms) { timers.push({callback,ms}); },
        addEventListener(name,handler) { events[name] = handler; },
        focus() {}
    };
    const location = {
        origin: 'https://crmbarghbazar.com',
        href: 'https://crmbarghbazar.com/main/?crm_section=dashboard'
    };
    const context = {
        window, document, Notification, location,
        URL, URLSearchParams, Promise, Math, Date,
        navigator: {}, console,
        fetch(_uri,options) {
            const params = new URLSearchParams(options.body);
            assert.equal(params.get('action'),'crmb_notice');
            assert.equal(params.get('nonce'),'nonce');
            assert.equal(params.get('mode'),'poll');
            calls.push(params.get('mode'));
            return Promise.resolve({ok: true, json() { return Promise.resolve({ success: true, data: incoming.value }); }});
        }
    };
    vm.runInNewContext(source, context);
    return {
        buttons, calls, toasts, document, events, timers,
        getPermissionCalls() { return requested; },
        async flush() {
            for (let i=0; i<6; i++) await new Promise(resolve => setImmediate(resolve));
        }
    };
}
(async function() {
    const storage = new Map();
    const incoming = { value: { count: 1, items: [{id:10,kind:'message',read:false,href:'/main/?crm_section=messages'}] } };
    const a = bootstrap(7, storage, incoming);
    await a.flush();
    assert.equal(a.toasts.length,0,'do not flood for old unread messages');
    assert.equal(a.timers[0].ms,10000,'live polling should run every ten seconds');
    assert.equal(a.buttons['crmb-push-test'].hidden,true,'test only after explicit consent');

    // Permission request must run on the direct click, even without server VAPID keys.
    a.buttons['crmb-push-enable'].listeners.click();
    assert.equal(a.getPermissionCalls(),1);
    await a.flush();
    assert.equal(storage.get('crmb-desktop-consent:7'),'1');
    assert.equal(a.buttons['crmb-push-test'].hidden,false);
    assert.match(a.buttons['crmb-push-status'].textContent,/تب باز/);

    incoming.value={count:2,items:[
        {id:11,kind:'message',read:false,href:'/main/?crm_section=messages&message=99'},
        {id:10,kind:'message',read:false,href:'/main/?crm_section=messages'}
    ]};
    a.timers[0].callback();
    await a.flush();
    assert.equal(a.toasts.length,1);
    assert.equal(a.toasts[0].title,'پیام جدید داری');
    assert.equal(a.toasts[0].options.tag,'crmb-11');
    assert.match(a.toasts[0].options.body,/وارد CRM/);
    assert.doesNotMatch(a.toasts[0].options.body,/محتوای خصوصی/);

    a.document.hidden=true; // Must still check new notifications in background tabs.
    incoming.value={count:3,items:[
        {id:12,kind:'manager',read:false,href:'/main/?crm_section=tasks'},
        {id:11,kind:'message',read:false,href:'/main/?crm_section=messages'},
        {id:10,kind:'message',read:false,href:'/main/?crm_section=messages'}
    ]};
    a.timers[0].callback();
    await a.flush();
    assert.equal(a.toasts.length,2);
    assert.equal(a.toasts[1].title,'راهنمایی جدید مدیر');
    a.timers[0].callback();
    await a.flush();
    assert.equal(a.toasts.length,2,'do not repeat the same notification');

    a.buttons['crmb-push-test'].listeners.click();
    assert.equal(a.toasts.length,3,'manual test should create one toast');
    assert.match(a.toasts[2].title,/آزمایشی/);

    // A different logged-in employee does not inherit an earlier account's consent.
    const b = bootstrap(8, storage, incoming, 'granted');
    await b.flush();
    incoming.value={count:4,items:[{id:13,kind:'message',read:false,href:'/main/?crm_section=messages'}]};
    b.timers[0].callback();
    await b.flush();
    assert.equal(b.toasts.length,0);
    assert.equal(b.buttons['crmb-push-test'].hidden,true);

    a.buttons['crmb-push-disable'].listeners.click();
    await a.flush();
    assert.equal(storage.get('crmb-desktop-consent:7'),'0');
    console.log('PASS: 10s live polling, native toast, no replays, background tab, opt-in, test, per-account consent');
})().catch(error => { console.error(error); process.exitCode=1; });

/* In-page private chat image viewer: keyboard, backdrop, and URL validation. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const source = fs.readFileSync(__dirname + '/../assets/js/crmb-chat-image-modal.js', 'utf8');
const chatSource = fs.readFileSync(__dirname + '/../assets/js/crmb-chat.js', 'utf8');
const template = fs.readFileSync(__dirname + '/../template-parts/messages.php', 'utf8');
assert.match(chatSource, /if \(file\.image\)[\s\S]*?data-crmb-chat-image/, 'only image links opt into the viewer');
assert.match(template, /<dialog[\s\S]*?id="crmb-chat-image-modal"/);
assert.match(template, /id="crmb-chat-image-modal-close"/);
assert.match(template, /crmb-chat-image-modal-inline/);

function setup(opts={}) {
    const nodes = {};
    function makeNode(id) {
        const handlers = new Map();
        const node = {
            id, listeners: handlers, attributes: {}, open: false, textContent: '',
            alt:'', src:'', isConnected:true, focused:0,
            addEventListener(type,fn) {
                if (!handlers.has(type)) handlers.set(type,[]);
                handlers.get(type).push(fn);
            },
            fire(type,evt={}) {
                for (const callback of handlers.get(type)||[]) callback(evt);
            },
            focus() { this.focused++; },
            removeAttribute(name) { delete this[name]; },
            querySelector() { return null; },
            contains(link) { return !!link && link.insideApp === true; }
        };
        nodes[id]=node;
        return node;
    }
    const app=makeNode('crmb-messenger');
    const dialog=makeNode('crmb-chat-image-modal');
    const photo=makeNode('crmb-chat-image-modal-photo');
    const caption=makeNode('crmb-chat-image-modal-caption');
    const button=makeNode('crmb-chat-image-modal-close');
    const contacts=makeNode('crmb-messenger-contacts');
    if (!opts.unsupported) {
        dialog.showModal=function(){if(opts.throwOnOpen)throw Error('showModal blocked');this.open=true;};
        dialog.close=function(){this.open=false;this.fire('close');};
    }
    const window = {location:{origin:'https://crmbarghbazar.com',href:'https://crmbarghbazar.com/main/?crm_section=messages'},handlers: {},
        addEventListener(name,fn){this.handlers[name]=fn;}};
    const document={getElementById(id){return nodes[id]||null;}};
    vm.runInNewContext(source,{document,window,URL,console});
    function link(url='https://crmbarghbazar.com/wp-admin/admin-ajax.php?action=crmb_chat_attachment&file=123&_wpnonce=opaque',isImage=true) {
        const image={alt:'تصویر پیوست: نمونه'};
        const anchor={href:url,title:'نمونه.png',insideApp:true,isConnected:true,focused:0,
            focus(){this.focused++;},
            querySelector(selector){return isImage && selector==='img.crmb-chat-inline-image'?image:null;}};
        return anchor;
    }
    function click(anchor, extras={}) {
        const event = {
            button:0,ctrlKey:false,metaKey:false,shiftKey:false,altKey:false,
            target:{closest(selector){return selector==='a[data-crmb-chat-image]'?anchor:null;}},
            prevented:false,preventDefault(){this.prevented=true;},...extras
        };
        app.fire('click',event);
        return event;
    }
    return {app,dialog,photo,caption,button,contacts,link,click,window};
}
const ui=setup();
const anchor=ui.link();
assert.equal(ui.click(anchor).prevented,true,'same-origin private image opens without navigating');
assert.equal(ui.dialog.open,true,'native dialog opened');
assert.equal(ui.photo.src,anchor.href,'same authenticated private attachment URL');
assert.equal(ui.caption.textContent,'نمونه.png');
assert.equal(ui.photo.alt,'تصویر پیوست: نمونه');
assert.equal(ui.button.focused,1,'close button receives focus');

ui.button.fire('click');
assert.equal(ui.dialog.open,false,'close button dismisses modal');
assert.equal(ui.photo.src,undefined,'private image src cleared on close');
assert.equal(anchor.focused,1,'keyboard focus restored to thumbnail');

ui.click(anchor);
ui.dialog.fire('click',{target:ui.dialog});
assert.equal(ui.dialog.open,false,'click outside modal content closes it');
ui.click(anchor);
ui.dialog.close(); // Native Escape handling closes <dialog>; close handler must clear details.
assert.equal(ui.caption.textContent,'','Escape closure releases modal state');
assert.equal(anchor.focused,3);

ui.click(anchor);
ui.contacts.fire('click');
assert.equal(ui.dialog.open,false,'changing contact closes image preview');
ui.click(anchor);
ui.window.handlers.pagehide();
assert.equal(ui.dialog.open,false,'navigation closes preview');

const outside=ui.link('https://untrusted.example/wp-admin/admin-ajax.php?action=crmb_chat_attachment&file=123&_wpnonce=xx');
assert.equal(ui.click(outside).prevented,false,'external image never intercepted');
assert.equal(ui.click(ui.link('javascript:alert(1)')).prevented,false,'script URLs rejected');
assert.equal(ui.click(ui.link('https://crmbarghbazar.com/wp-admin/admin-ajax.php?action=other&file=123&_wpnonce=x')).prevented,false);
assert.equal(ui.click(ui.link('https://crmbarghbazar.com/wp-admin/admin-ajax.php?action=crmb_chat_attachment&file=0&_wpnonce=x')).prevented,false);
assert.equal(ui.click(ui.link('https://crmbarghbazar.com/wp-admin/admin-ajax.php?action=crmb_chat_attachment&file=123')).prevented,false);
assert.equal(ui.click(ui.link(undefined,false)).prevented,false,'documents still follow normal link');
assert.equal(ui.click(anchor,{ctrlKey:true}).prevented,false,'Ctrl+click keeps browser behavior');
assert.equal(ui.click(anchor,{button:1}).prevented,false,'middle click keeps browser behavior');
assert.equal(ui.dialog.open,false,'invalid links and modifier clicks do not open viewer');
assert.equal(setup({unsupported:true}).click(anchor).prevented,false,'old browsers keep link fallback');
assert.equal(setup({throwOnOpen:true}).click(anchor).prevented,false,'failed dialog creation keeps browser navigation fallback');
console.log('PASS: CRM chat image modal, private link validation, native close/Escape, backdrop, focus and document fallback');

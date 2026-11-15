/* Standalone browser-behavior regression for Ctrl+V screenshots in task progress. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(__dirname + '/../assets/js/crmb-task-image-paste.js', 'utf8');

class Element {
    constructor(tag) {
        this.tagName = tag;
        this.children = [];
        this.listeners = {};
        this.attributes = {};
        this.style = {};
        this.textContent = '';
        this.disabled = false;
        this.files = [];
        this.value = '';
        this.classList = {
            toggle: (className, active) => { this.attributes[className] = active; }
        };
    }
    addEventListener(type, handler) { (this.listeners[type] ||= []).push(handler); }
    dispatch(type, event) { for (const handler of this.listeners[type] || []) handler(event); }
    append(...children) { this.children.push(...children); }
    appendChild(child) { this.children.push(child); return child; }
    replaceChildren(...children) { this.children = [...children]; }
    setAttribute(key,value) { this.attributes[key]=value; }
    contains(target) { return target === this || target?.parent === this; }
}
function build() {
    let urlCount = 0;
    const revoked = [];
    const editorDocument = new Element('iframeDocument');
    const editor = {
        id: 'crmbtaskeditor',
        initialized: true,
        getDoc() { return editorDocument; },
        on() {}
    };
    const nodes = new Map([
        ['crmb-task-progress-form',new Element('form')],
        ['crmb-task-images',new Element('input')],
        ['crmb-task-image-preview',new Element('div')],
        ['crmb-task-image-feedback',new Element('p')]
    ]);
    const form = nodes.get('crmb-task-progress-form');
    const button = new Element('button');
    form.querySelector = selector => selector === 'button[type="submit"]' ? button : null;
    const document = new Element('document');
    document.getElementById = name => nodes.get(name) || null;
    document.createElement = name => {
        if (name === 'canvas') {
            return {
                width:0,height:0,
                getContext() { return {drawImage(){}}; },
                toBlob(cb,type) { cb(new Blob([new Uint8Array(170000)], {type})); }
            };
        }
        return new Element(name);
    };
    class DataTransfer {
        constructor() {
            this._items = [];
            this.items = { add: file => this._items.push(file) };
        }
        get files() { return this._items.slice(); }
    }
    const originalURL = {
        createObjectURL() { return 'blob:test-' + ++urlCount; },
        revokeObjectURL(url) {revoked.push(url);}
    };
    const intervals = [];
    const window = {
        tinymce: {
            get(id) { return id==='crmbtaskeditor' ? editor : null; },
            on() {}
        },
        setInterval(fn) { intervals.push(fn); return intervals.length; },
        clearInterval() {},
        addEventListener() {}
    };
    vm.runInNewContext(source, {
        document,window,File,Blob,DataTransfer,URL:originalURL,Date,Math,Promise,console,
        createImageBitmap: async () => ({width:4200,height:2800,close(){}})
    });
    const field=nodes.get('crmb-task-images');
    return {
        form, document, field, button, feedback:nodes.get('crmb-task-image-feedback'),
        preview:nodes.get('crmb-task-image-preview'), editorDocument, revoked,
        async flush() {
            for(let i=0;i<12;i++)await new Promise(resolve=>setImmediate(resolve));
        },
        paste(target, items) {
            const event = {
                target, defaultPrevented:false, stopped:false,
                clipboardData: {items},
                preventDefault(){this.defaultPrevented=true;},
                stopImmediatePropagation(){this.stopped=true;}
            };
            (target===editorDocument ? editorDocument : document).dispatch('paste',event);
            return event;
        }
    };
}
function imageItem(file) {
    return {kind:'file',type:file.type,getAsFile(){return file;}};
}
function makePng(name='image.png',size=450) {
    return new File([new Uint8Array(size)],name,{type:'image/png',lastModified:55});
}
(async()=>{
    const ui = build();
    const png=makePng();
    const pasted = ui.paste(ui.editorDocument,[imageItem(png)]);
    assert.equal(pasted.defaultPrevented,true,'editor iframe screenshot must be intercepted');
    assert.equal(pasted.stopped,true,'prevent TinyMCE embedding data URI');
    await ui.flush();
    assert.equal(ui.field.files.length,1,'screenshot becomes genuine form file');
    assert.equal(ui.field.files[0].type,'image/png');
    assert.match(ui.field.files[0].name,/^screenshot-/);
    assert.equal(ui.preview.children.length,1,'thumbnail shown');

    const documentText = ui.paste(ui.editorDocument,[
        {kind:'string',type:'text/plain',getAsFile(){return null;}}
    ]);
    assert.equal(documentText.defaultPrevented,false,'ordinary text paste must not break');
    assert.equal(ui.field.files.length,1);

    // File picker and screenshot must accumulate instead of replacing each other.
    const manuallySelected=new File([new Uint8Array(200)],'diagram.png',{type:'image/png',lastModified:44});
    ui.field.files=[manuallySelected];
    ui.field.dispatch('change',{target:ui.field});
    await ui.flush();
    assert.equal(ui.field.files.length,2);
    assert.equal(ui.preview.children.length,2);

    const large=new File([new Uint8Array(2*1024*1024)],'screenshot.png',{type:'image/png'});
    const third=ui.paste(ui.form,[imageItem(large)]);
    assert.equal(third.defaultPrevented,true);
    await ui.flush();
    assert.equal(ui.field.files.length,3);
    assert.equal(ui.field.files[2].type,'image/webp','large screenshot should be resized for server 1MB limit');
    assert.ok(ui.field.files[2].size<=1048576);

    ui.paste(ui.editorDocument,[imageItem(png)]);
    await ui.flush();
    assert.equal(ui.field.files.length,3,'maximum three images');

    const removeButton=ui.preview.children[0].children[2];
    removeButton.dispatch('click',{});
    assert.equal(ui.field.files.length,2,'removed image deleted from actual file input');
    assert.ok(ui.revoked.length>=1,'thumbnail URL cleaned up');

    // Invalid file picker should never be present in multipart payload.
    const invalid=new File([new Uint8Array(100)],'shell.php',{type:'application/octet-stream'});
    ui.field.files=[invalid];
    ui.field.dispatch('change',{target:ui.field});
    await ui.flush();
    assert.equal(ui.field.files.length,2,'invalid selected file discarded');
    assert.match(ui.feedback.textContent,/JPG|PNG|WebP/);

    // Form outside paste should not be intercepted.
    const elsewhere=new Element('input');
    const outside = ui.paste(elsewhere,[imageItem(png)]);
    assert.equal(outside.defaultPrevented,false);
    await ui.flush();
    assert.equal(ui.field.files.length,2);
    console.log('PASS: TinyMCE screenshot Ctrl+V, native FormData input, text paste, image compression, 3-image limit, remove, picker merge and invalid images');
})().catch(error=>{console.error(error);process.exitCode=1;});

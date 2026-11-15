/* Node regression for per-account CRM notification sound and audio de-duplication. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(__dirname + '/../assets/js/crmb-notices.js','utf8');
const sharedStore = new Map();
const incoming = { value: { count:1, items:[
    { id:40,kind:'message',read:false,href:'/main/?crm_section=messages' }
]} };
const clock = {now:100000};
function setup(userId) {
    const elements = new Map();
    const timers = [];
    const starts = [];
    const events = {};
    function node(name) {
        const instance = {
            name, hidden:false, disabled:false, textContent:'', innerHTML:'',
            attributes:{}, listeners:{}, isConnected:true,
            setAttribute(key,value) { this.attributes[key]=value; },
            addEventListener(key,fn) { this.listeners[key]=fn; },
            appendChild() {}
        };
        elements.set(name,instance);
        return instance;
    }
    [
        'crmb-push-enable','crmb-push-disable','crmb-push-test','crmb-push-status',
        'crmb-sound-toggle','crmb-sound-test','crmb-sound-status'
    ].forEach(node);
    const bell = node('bell');
    const document = {
        hidden:false,
        getElementById(id) { return elements.get(id)||null; },
        querySelector(selector) {
            if (selector==='.crm-topbar-bell') return bell;
            return null;
        },
        addEventListener(name,handler) { events[name]=handler; },
        createElement:node
    };
    class FakeAudioContext {
        constructor() {
            this.state = 'running';
            this.currentTime = 1.2;
            this.destination = {};
        }
        resume() { this.state='running'; return Promise.resolve(); }
        createOscillator() {
            return {
                type:'sine',
                frequency:{setValueAtTime(){}},
                connect(){},
                start(time){ starts.push(time); },
                stop(){}
            };
        }
        createGain() {
            return {gain:{
                setValueAtTime(){},
                exponentialRampToValueAtTime(){}
            },connect(){}};
        }
    }
    const window = {
        crmbNoticeConfig:{ userId,ajax:'/wp-admin/admin-ajax.php',nonce:'abc',publicKey:'' },
        AudioContext:FakeAudioContext,
        isSecureContext:true,
        localStorage:{
            getItem(key) {return sharedStore.has(key)?sharedStore.get(key):null;},
            setItem(key,value) {sharedStore.set(key,String(value));}
        },
        location:{href:'https://crmbarghbazar.com/main/?crm_section=dashboard'},
        addEventListener(name,handler){events[name]=handler;},
        setInterval(fn,ms){timers.push({fn,ms});},
        focus(){}
    };
    const location = {origin:'https://crmbarghbazar.com'};
    const context = {
        window,document,location,
        navigator:{},
        Notification:class {static permission='default';},
        Date:{now(){return clock.now;}},
        Math,URL,URLSearchParams,Promise,console,
        fetch(url,options) {
            assert.equal(url,'/wp-admin/admin-ajax.php');
            const params = new URLSearchParams(options.body);
            assert.equal(params.get('mode'),'poll');
            return Promise.resolve({ok:true,json(){return Promise.resolve({success:true,data:incoming.value});}});
        }
    };
    vm.runInNewContext(source,context);
    return {
        starts,elements,timers,events,
        button(id){return elements.get(id);},
        async flush(){for(let i=0;i<7;i++)await new Promise(resolve=>setImmediate(resolve));},
        async poll(){timers[0].fn();await this.flush();}
    };
}
(async()=>{
    const user = setup(7);
    await user.flush();
    assert.equal(user.starts.length,0,'never sound at page load');
    assert.equal(user.button('crmb-sound-toggle').attributes['aria-pressed'],'false');
    assert.equal(user.button('crmb-sound-test').hidden,true);

    // Opt in from direct user gesture. The first chime is the audible setup test.
    user.button('crmb-sound-toggle').listeners.click();
    await user.flush();
    assert.equal(sharedStore.get('crmb-message-sound:7'),'1');
    assert.equal(user.starts.length,3,'original three-note chime');
    assert.equal(user.button('crmb-sound-test').hidden,false);

    clock.now+=7500;
    incoming.value={count:2,items:[
        {id:41,kind:'message',read:false,href:'/main/?crm_section=messages'},
        {id:40,kind:'message',read:false,href:'/main/?crm_section=messages'}
    ]};
    await user.poll();
    assert.equal(user.starts.length,6,'a genuinely new chat triggers one chime');

    clock.now+=7500;
    incoming.value={count:3,items:[
        {id:42,kind:'task',read:false,href:'/main/?crm_section=tasks'},
        {id:41,kind:'message',read:false,href:'/main/?crm_section=messages'}
    ]};
    await user.poll();
    assert.equal(user.starts.length,6,'a new task alone should not ring');

    const otherTab=setup(7);
    await otherTab.flush();
    assert.equal(otherTab.starts.length,0,'a new tab does not replay old messages');
    clock.now+=7500;
    incoming.value={count:4,items:[
        {id:43,kind:'message',read:false,href:'/main/?crm_section=messages'},
        {id:42,kind:'task',read:false,href:'/main/?crm_section=tasks'}
    ]};
    await user.poll();
    await otherTab.poll();
    assert.equal(user.starts.length,9,'first open tab rings');
    assert.equal(otherTab.starts.length,0,'second tab does not repeat');
    assert.equal(sharedStore.get('crmb-message-sound-last:7'),'43');

    const otherEmployee=setup(8);
    await otherEmployee.flush();
    assert.equal(otherEmployee.button('crmb-sound-toggle').attributes['aria-pressed'],'false');
    clock.now+=7500;
    incoming.value={count:5,items:[{id:44,kind:'message',read:false,href:'/main/?crm_section=messages'}]};
    await otherEmployee.poll();
    assert.equal(otherEmployee.starts.length,0,'sound setting isolated for different employee');

    user.button('crmb-sound-toggle').listeners.click();
    await user.flush();
    assert.equal(sharedStore.get('crmb-message-sound:7'),'0');
    assert.equal(user.button('crmb-sound-test').hidden,true);
    await user.poll();
    assert.equal(user.starts.length,9,'mute disables new sounds');
    assert.ok(user.timers[0].ms===10000,'existing lightweight live polling retained');
    console.log('PASS: opt-in sound, original chime, message-only triggers, mute, tab deduplication, per-user isolation');
})().catch(err=>{console.error(err);process.exitCode=1;});

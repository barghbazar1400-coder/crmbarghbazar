/* Node regression: bell popover stays on CRM page, real links, safe content, read actions. */
'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const source=fs.readFileSync(__dirname+'/../assets/js/crmb-notices.js','utf8');

function element(tag) {
  const el={
    tag, children:[], parent:null, listeners:{}, attributes:{}, hidden:false,
    className:'', textContent:'', href:'', disabled:false, isConnected:true,
    addEventListener(name,cb){this.listeners[name]=cb;},
    setAttribute(name,value){this.attributes[name]=value;},
    appendChild(child){child.parent=this;this.children.push(child);return child;},
    replaceChildren(...children){this.children=[];children.forEach(c=>this.appendChild(c));},
    contains(target){for(let n=target;n;n=n.parent){if(n===this)return true;}return false;},
    querySelector(selector){if(selector==='.crmb-notice-popover-close')return close;return null;},
    focus(){this.focused=true;}
  };
  return el;
}
const documentEvents={};
const bell=element('a'), wrap=element('div'), panel=element('section'), list=element('div'), counter=element('span'), close=element('button');
wrap.appendChild(bell);wrap.appendChild(panel);panel.appendChild(list);panel.hidden=true;
const elements={
  'crmb-notice-popover':panel,
  'crmb-notice-popover-list':list,
  'crmb-notice-popover-count':counter
};
const document={
  hidden:false,
  querySelector(s){
    if(s==='.crm-topbar-bell')return bell;
    if(s==='.crmb-notice-bell-wrap')return wrap;
    return null;
  },
  getElementById(id){return elements[id]||null;},
  createElement:element,
  addEventListener(type,handler){documentEvents[type]=handler;}
};
const calls=[],timers=[];
let inbox={
  count:3,
  items:[
    {id:21,kind:'task',title:'تسک جدید',summary:'کار جدید واگذار شد.',created:'۱۴۰۵/۰۸/۲۶، ۱۰:۳۰',href:'/main/?crm_section=tasks&task_id=8',read:false},
    {id:20,kind:'manager',title:'راهنمایی مدیر',summary:'برای تسک راهنمایی ثبت شده است.',created:'امروز',href:'/main/?crm_section=tasks&task_id=8',read:false},
    {id:19,kind:'message',title:'<img src=x onerror=alert(1)>',summary:'پیام جدید.',created:'دیروز',href:'https://evil.example/steal',read:false}
  ]
};
const location={origin:'https://crmbarghbazar.com',href:'https://crmbarghbazar.com/main/?crm_section=dashboard'};
const window={
  crmbNoticeConfig:{userId:8,nonce:'nonce',ajax:'/wp-admin/admin-ajax.php',publicKey:''},
  location,
  localStorage:{getItem(){return null;},setItem(){}},
  setInterval(fn,ms){timers.push({fn,ms});},
  addEventListener(){},
  isSecureContext:true
};
const context={
  window,document,location,navigator:{},Date,Math,Promise,URL,URLSearchParams,console,
  fetch(url,options){
    assert.equal(url,'/wp-admin/admin-ajax.php');
    let p=new URLSearchParams(options.body);
    assert.equal(p.get('action'),'crmb_notice');
    assert.equal(p.get('nonce'),'nonce');
    let mode=p.get('mode');calls.push(mode);
    if(mode==='read'){
      const id=Number(p.get('id'));
      const item=inbox.items.find(it=>it.id===id);
      assert.ok(item,'only known item can be marked read');
      item.read=true;
      inbox.count--;
      return Promise.resolve({ok:true,json:()=>Promise.resolve({success:true,data:{count:inbox.count}})});
    }
    assert.equal(mode,'poll');
    return Promise.resolve({ok:true,json:()=>Promise.resolve({success:true,data:inbox})});
  }
};
function flush(){return new Promise(async resolve=>{for(let i=0;i<10;i++)await new Promise(r=>setImmediate(r));resolve();});}
(async()=>{
  vm.runInNewContext(source,context);
  await flush();
  assert.equal(panel.hidden,true,'panel initially closed');
  assert.equal(timers[0].ms,10000,'reuse 10-second polling');
  assert.equal(list.children.length,0,'no premature DOM rendering when closed');
  let blocked=false;
  bell.listeners.click({preventDefault(){blocked=true;}});
  await flush();
  assert.equal(blocked,true,'bell does not navigate to settings');
  assert.equal(panel.hidden,false,'bell opens popover');
  assert.equal(bell.attributes['aria-expanded'],'true');
  assert.equal(list.children.length,3,'newest notices listed');
  assert.equal(counter.textContent,'3 خوانده‌نشده');
  assert.equal(list.children[0].children[1].href,'/main/?crm_section=tasks&task_id=8','task entry has direct task link');
  assert.equal(list.children[2].children[1].href,'/main/?crm_section=notifications','external link is rejected');
  assert.equal(list.children[2].children[1].textContent,'<img src=x onerror=alert(1)>','server text stays literal, never HTML');
  assert.ok(!list.children[1].children.at(-1).children.some(ch=>ch.className==='crmb-notice-popover-read'),'manager guidance needs task acknowledgement');
  const readButton=list.children[0].children.at(-1).children.find(ch=>ch.className==='crmb-notice-popover-read');
  assert.ok(readButton,'per-item explicit read button available');
  readButton.listeners.click();
  await flush();
  assert.equal(calls.includes('read'),true,'marks read in the backend');
  assert.equal(counter.textContent,'2 خوانده‌نشده','count updated');
  assert.equal(list.children[0].className.includes('is-unread'),false,'read state updated');
  // Click away closes without erasing the current page.
  documentEvents.click({target:element('outside')});
  assert.equal(panel.hidden,true);
  assert.equal(bell.attributes['aria-expanded'],'false');
  bell.listeners.click({preventDefault(){}});
  assert.equal(panel.hidden,false);
  documentEvents.keydown({key:'Escape'});
  assert.equal(panel.hidden,true,'Escape closes popover');
  assert.equal(bell.focused,true,'Escape restores keyboard focus');
  bell.listeners.click({preventDefault(){}});
  close.listeners.click();
  assert.equal(panel.hidden,true,'close button works');
  assert.ok(calls.filter(c=>c==='poll').length>=2,'opening refreshes newest notifications');
  console.log('PASS: no page redirect, latest notices, safe links/text, read status, guidance, dismissal, live polling');
})().catch(error=>{console.error(error);process.exitCode=1;});

/* Live review queue: fresh submissions appear without manual reload. */
'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const source=fs.readFileSync(__dirname+'/../assets/js/crmb-review-watch.js','utf8');
function setup(draftOpen=false,scope='team') {
  const requests=[],timers=[],listeners={},watch={
    dataset:{nonce:'nonce',endpoint:'/wp-admin/admin-ajax.php',assignee:scope==='mine'?'0':'7',scope:scope,version:'2',count:'0'}
  },reloads=[];
  const link={hidden:true};
  let reply={version:2,count:0};
  const doc={
    hidden:false,
    getElementById(id){return id==='crmb-review-watch'?watch:id==='crmb-review-watch-refresh'?link:null;},
    querySelector(selector){assert.equal(selector,'.crmb-tasks-editor[open]');return draftOpen?{}:null;},
    addEventListener(type,cb){listeners['document_'+type]=cb;}
  };
  const win={
    URLSearchParams,
    fetch(url,options) {
      assert.equal(url,'/wp-admin/admin-ajax.php');
      const p=new URLSearchParams(options.body);
      assert.equal(p.get('action'),'crmb_work_review_poll');
      assert.equal(p.get('nonce'),'nonce');
      assert.equal(p.get('assigned_to'),scope==='mine'?'0':'7');
      assert.equal(p.get('scope'),scope);
      requests.push(p.get('action'));
      return Promise.resolve({ok:true,json:()=>Promise.resolve({success:true,data:reply})});
    },
    location:{reload(){reloads.push(true);}},
    setInterval(cb,ms){timers.push({cb,ms});},
    addEventListener(type,cb){listeners['window_'+type]=cb;}
  };
  vm.runInNewContext(source,{document:doc,window:win,URLSearchParams,fetch:win.fetch,Promise,Number,console,Error});
  return {
    requests,timers,listeners,link,doc,reloads,
    setResponse(data){reply=data;},
    async flush(){for(let i=0;i<10;i++)await new Promise(r=>setImmediate(r));}
  };
}
(async()=>{
  const m=setup();
  await m.flush();
  assert.equal(m.timers[0].ms,15000,'poll only on review filter every 15 seconds');
  assert.equal(m.reloads.length,0,'no reload for unchanged initial state');
  m.setResponse({version:4,count:1});
  m.timers[0].cb();
  await m.flush();
  assert.equal(m.reloads.length,1,'second submission auto reloads manager list');
  const n=setup(true);
  await n.flush();
  n.setResponse({version:3,count:1});
  n.timers[0].cb();
  await n.flush();
  assert.equal(n.reloads.length,0,'unsaved editor is preserved');
  assert.equal(n.link.hidden,false,'visible refresh link if editing');
  const mine=setup(false,'mine');
  await mine.flush();
  mine.setResponse({version:6,count:2});
  mine.timers[0].cb();
  await mine.flush();
  assert.equal(mine.reloads.length,1,'manager personal inbox refreshes when approvals are routed to them');
  const hidden=setup();
  await hidden.flush();
  hidden.doc.hidden=true;
  hidden.timers[0].cb();
  await hidden.flush();
  assert.equal(hidden.requests.length,1,'hidden tab does not poll unnecessarily');
  hidden.doc.hidden=false;
  hidden.setResponse({version:3,count:1});
  hidden.listeners.document_visibilitychange();
  await hidden.flush();
  assert.equal(hidden.reloads.length,1,'returning to manager tab fetches new state');
  console.log('PASS: initial state, second submission, no draft loss, visibility and scoped requests');
})().catch(e=>{console.error(e);process.exitCode=1;});
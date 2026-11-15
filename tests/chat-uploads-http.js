/* Real multipart browser-compatible chat attachment integration, without WordPress. */
'use strict';
const assert = require('node:assert/strict');
const { spawn } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const root = path.join(__dirname, '..');
const folder = fs.mkdtempSync(path.join(os.tmpdir(), 'crmb-chat-http-'));
const server = spawn('php', ['-S', '127.0.0.1:8954', '-t', 'tests', 'tests/chat-uploads-router.php'], {
    cwd: root, env: { ...process.env, CRMB_CHAT_HTTP_TEST_DIR: folder },
    stdio: ['ignore', 'pipe', 'pipe']
});
let output = '';
server.stderr.on('data', bytes => { output += bytes.toString(); });
server.stdout.on('data', bytes => { output += bytes.toString(); });
const base = 'http://127.0.0.1:8954/';
const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScLTTAAAAABJRU5ErkJggg==', 'base64');
function multipart(files, description) {
    const data = new FormData();
    data.set('body', description || '');
    for (const file of files) {
        data.append('attachments[]', new Blob([file.buffer], { type: file.type || 'image/png' }), file.name);
    }
    return data;
}
async function waitForServer() {
    for (let i = 0; i < 45; i++) {
        if (server.exitCode !== null) throw Error('PHP server exited: ' + output);
        try { const r = await fetch(base); if (r.ok) return; } catch (_) {}
        await new Promise(resolve => setTimeout(resolve, 100));
    }
    throw Error('PHP server start timed out: ' + output);
}
(async () => {
    await waitForServer();
    let r = await fetch(base, { method:'POST', headers:{'X-Test-User':'7'}, body:multipart([
        { name:'screenshot.png', buffer:png }
    ]) });
    assert.equal(r.status, 200, await r.text());
    // Read JSON separately to avoid consuming body multiple times in diagnostics.
})().then(async () => {
    let stats = await (await fetch(base + '?mode=stats')).json();
    assert.equal(stats.messages, 1, 'one message persisted');
    assert.equal(stats.files, 1, 'one private file persisted');

    // Message participant can view image using a short-lived WordPress-style token.
    const token = encodeURIComponent('nonce-crmb_chat_attachment_1');
    const url = base + '?action=crmb_chat_attachment&file=1&_wpnonce=' + token;
    let response = await fetch(url, { headers: { 'X-Test-User':'7' } });
    assert.equal(response.status, 200, 'sender may open its private file');
    assert.equal(response.headers.get('content-type'), 'image/png');
    assert.match(response.headers.get('cache-control'), /no-store/);
    assert.equal(response.headers.get('x-content-type-options'), 'nosniff');
    assert.deepEqual(Buffer.from(await response.arrayBuffer()), png);

    response = await fetch(url, { headers: { 'X-Test-User':'8' } });
    assert.equal(response.status, 200, 'recipient may open its private file');
    response = await fetch(url, { headers: { 'X-Test-User':'9' } });
    assert.equal(response.status, 404, 'third employee cannot download');
    response = await fetch(base + '?action=crmb_chat_attachment&file=1&_wpnonce=wrong', {
        headers: { 'X-Test-User':'7' }
    });
    assert.equal(response.status, 403, 'invalid download nonce is rejected');

    response = await fetch(base, { method:'POST', headers:{'X-Test-User':'7'}, body:multipart([
        {name:'embedded.php',buffer:Buffer.from('<?php echo "hi"; ?>'),type:'text/plain'}
    ])});
    assert.equal(response.status, 400, 'no PHP files may be attached');
    response = await fetch(base, { method:'POST', headers:{'X-Test-User':'7'}, body:multipart([
        {name:'fake.png',buffer:Buffer.from('<?php echo "hi"; ?>'),type:'image/png'}
    ])});
    assert.equal(response.status, 400, 'falsely renamed images rejected');
    response = await fetch(base, { method:'POST', headers:{'X-Test-User':'7'}, body:multipart([
        {name:'one.png',buffer:png},{name:'two.png',buffer:png},{name:'three.png',buffer:png},{name:'four.png',buffer:png}
    ])});
    assert.equal(response.status, 400, 'limit to 3 files per message');

    // Check two attachments in the same message and no text (clipboard case).
    response = await fetch(base, { method:'POST', headers:{'X-Test-User':'7'}, body:multipart([
        {name:'clipboard.png',buffer:png},{name:'design.png',buffer:png}
    ])});
    assert.equal(response.status, 200, await response.text());
    stats = await (await fetch(base + '?mode=stats')).json();
    assert.equal(stats.messages, 2, 'rejected files never create a message');
    assert.equal(stats.files, 3, '2 new files joined one message');
    console.log('PASS: real HTTP multipart, attachment-only messages, MIME validation, sender/recipient/private downloads');
}).catch(error => {
    console.error(error);
    console.error(output.slice(-3000));
    process.exitCode = 1;
}).finally(async () => {
    server.kill('SIGTERM');
    await new Promise(resolve => {
        if (server.exitCode !== null) return resolve();
        server.once('exit', resolve);
        setTimeout(resolve, 1500).unref();
    });
    fs.rmSync(folder, { recursive:true, force:true });
});

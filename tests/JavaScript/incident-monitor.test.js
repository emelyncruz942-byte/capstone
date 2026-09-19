import assert from 'node:assert/strict';
import test from 'node:test';
import { monitor } from '../../scripts/incident-monitor.mjs';
const url = 'https://staging.example.test/api/operations/monitor';
const token = 'synthetic-monitor-token-not-a-real-secret';

test('independent monitor posts an authorization header without leaking credentials into the URL', async () => {
    const requests = [];
    assert.equal(await monitor({ url, token, fetchImpl: async (target, options) => {
        requests.push({target,options}); return { ok: true };
    }}),true);
    assert.equal(requests[0].options.headers.Authorization,`Bearer ${token}`);
    assert.ok(!requests[0].target.includes(token)); assert.equal(requests[0].options.redirect,'error');
});
test('unreachable app triggers an independent fallback without response bodies or credentials', async () => {
    const requests = [];
    assert.equal(await monitor({ url, token, webhook: 'https://alerts.example.test/synthetic-hook', fetchImpl: async (target,options) => {
        requests.push({target,options}); if(requests.length===1) throw new Error('Internal secret'); return {ok:true};
    }}),false);
    assert.equal(requests.length,2);
    assert.ok(!requests[1].options.body.includes(token)); assert.ok(!requests[1].options.body.includes('Internal secret'));
});
test('invalid or redirect-prone monitor configuration fails before a request', async () => {
    for(const target of ['http://staging.example.test/api/operations/monitor', `${url}?token=${token}`, 'https://user:password@example.test/api/operations/monitor']) {
        await assert.rejects(monitor({url:target,token,fetchImpl:()=>assert.fail('Must not fetch')}));
    }
});

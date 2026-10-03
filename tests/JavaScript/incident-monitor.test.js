import assert from 'node:assert/strict';
import { createHash, createHmac } from 'node:crypto';
import test from 'node:test';
import { monitor } from '../../scripts/incident-monitor.mjs';
const url = 'https://staging.example.test/api/operations/monitor';
const token = 'synthetic-monitor-token-not-a-real-secret';

test('independent monitor signs the exact body without transmitting its secret', async () => {
    const requests = [];
    const timestampMs = 1_800_000_000_000;
    const nonce = 'fixture_nonce_abcdefghijklmnopqrstuvwxyz_123456';
    assert.equal(await monitor({ url, token, fetchImpl: async (target, options) => {
        requests.push({target,options}); return { ok: true };
    }, now: () => timestampMs, nonceFactory: () => nonce }),true);
    const timestamp = String(Math.floor(timestampMs / 1000));
    const digest = createHash('sha256').update('{}').digest('hex');
    const signature = createHmac('sha256', token)
        .update(`v2:incident-monitor:POST:/api/operations/monitor:${timestamp}:${nonce}:${digest}`).digest('hex');
    assert.equal(requests[0].options.headers['X-MathVerse-Timestamp'], timestamp);
    assert.equal(requests[0].options.headers['X-MathVerse-Nonce'], nonce);
    assert.equal(requests[0].options.headers['X-MathVerse-Signature'], signature);
    assert.equal(requests[0].options.headers.Authorization, undefined);
    assert.equal(requests[0].options.body, '{}');
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

import { createHash, createHmac, randomBytes } from 'node:crypto';

// Independent observer: never prints tokens, webhook URLs or response bodies.
export async function monitor({ url, token, webhook, fetchImpl = fetch, now = () => Date.now(), nonceFactory = () => randomBytes(24).toString('hex') }) {
    const target = new URL(url);
    if (target.protocol !== 'https:' || target.username || target.password || target.search || target.hash
        || target.pathname !== '/api/operations/monitor' || !token || token.length < 32) {
        throw new Error('Configure a valid HTTPS monitor URL and a token of at least 32 characters.');
    }
    let failed = false;
    try {
        const body = '{}';
        const timestamp = String(Math.floor(now() / 1000));
        const nonce = nonceFactory();
        if (!/^[A-Za-z0-9_-]{32,128}$/.test(nonce)) throw new Error('The monitor nonce source is invalid.');
        const digest = createHash('sha256').update(body).digest('hex');
        const signature = createHmac('sha256', token)
            .update(`v2:incident-monitor:POST:${target.pathname}:${timestamp}:${nonce}:${digest}`)
            .digest('hex');
        const response = await fetchImpl(target.href, { method: 'POST', redirect: 'error',
            signal: AbortSignal.timeout(20000), headers: {
                Accept: 'application/json', 'Content-Type': 'application/json',
                'X-MathVerse-Timestamp': timestamp, 'X-MathVerse-Nonce': nonce,
                'X-MathVerse-Signature': signature,
            }, body });
        if (!response.ok) failed = true;
    } catch {
        failed = true;
    }
    if (!failed) return true;
    if (webhook) {
        const hook = new URL(webhook);
        if (hook.protocol !== 'https:' || hook.username || hook.password) throw new Error('Configure a valid HTTPS fallback webhook.');
        const message = 'MathVerse independent incident monitor failed. The site, primary database, alert dispatch or monitor configuration needs attention.';
        try {
            await fetchImpl(hook.href, { method: 'POST', redirect: 'error', signal: AbortSignal.timeout(10000),
                headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ text: message, content: message, severity: 'critical' }) });
        } catch { /* The failed workflow remains visible even if the fallback is unavailable. */ }
    }
    return false;
}

if (import.meta.url === `file://${process.argv[1]}`) {
    try {
        const ok = await monitor({ url: process.env.INCIDENT_MONITOR_URL, token: process.env.INCIDENT_MONITOR_TOKEN,
            webhook: process.env.INCIDENT_WEBHOOK_URL });
        console.log(ok ? 'Independent incident check completed.' : 'Independent incident check failed; inspect application health and monitor configuration.');
        process.exitCode = ok ? 0 : 1;
    } catch {
        console.error('Independent incident monitor configuration is invalid. No credentials or response data were logged.');
        process.exitCode = 1;
    }
}

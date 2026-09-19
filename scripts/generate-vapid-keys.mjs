import { generateKeyPairSync } from 'node:crypto';

const { publicKey, privateKey } = generateKeyPairSync('ec', {
    namedCurve: 'prime256v1',
});
const publicJwk = publicKey.export({ format: 'jwk' });
const privateJwk = privateKey.export({ format: 'jwk' });

const rawPublicKey = Buffer.concat([
    Buffer.from([4]),
    Buffer.from(publicJwk.x, 'base64url'),
    Buffer.from(publicJwk.y, 'base64url'),
]);

console.log(`VAPID_PUBLIC_KEY=${rawPublicKey.toString('base64url')}`);
console.log(`VAPID_PRIVATE_KEY=${privateJwk.d}`);
console.log('VAPID_SUBJECT=mailto:your-admin-email@example.com');

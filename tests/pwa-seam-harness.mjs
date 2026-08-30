/*
 * Drives `resources/js/app.js` under node, with just enough browser around it
 * to reach the two paths that only exist when something rejects.
 *
 * A Pest 4 browser test is what the seam deserves, but no browser plugin is
 * installed here and adding Playwright to assert one string is not the trade.
 * This holds the layer a test CAN hold without it: the real module, imported
 * rather than read as text, so what gets asserted is the message the reporter
 * actually builds — not the source that builds it, which is the assertion that
 * shipped this bug in the first place.
 *
 * Usage: node tests/pwa-seam-harness.mjs <scenario> <path-to-app.js>
 * Prints one JSON line: `{ posts, result }`.
 */

const [scenario, module] = process.argv.slice(2);

const listeners = {};
const posts = [];

globalThis.window = globalThis;

globalThis.addEventListener = (name, handler) => {
    (listeners[name] ??= []).push(handler);
};

globalThis.dispatchEvent = () => true;
globalThis.innerWidth = 390;
globalThis.location = { pathname: '/' };
globalThis.matchMedia = () => ({ matches: false });
globalThis.atob = (encoded) => Buffer.from(encoded, 'base64').toString('binary');

globalThis.document = {
    querySelector: (selector) => ({
        content: selector.includes('cfb-error-endpoint') ? '/client-errors' : 'test-csrf-token',
    }),
};

/* Every POST is captured, error reports and push subscriptions alike; the
 * test tells them apart by endpoint. */
globalThis.fetch = (url, options) => {
    posts.push({ url, body: JSON.parse(options.body) });

    return Promise.resolve({ ok: true });
};

globalThis.Notification = {
    permission: 'default',
    requestPermission: () => Promise.resolve('granted'),
};

globalThis.PushManager = { supportedContentEncodings: ['aes128gcm'] };

const subscription = {
    endpoint: 'https://push.example.test/abc',
    toJSON: () => ({ endpoint: 'https://push.example.test/abc', keys: { p256dh: 'k', auth: 'a' } }),
};

const pushManager = {
    subscribe: () => Promise.resolve(subscription),
    getSubscription: () => Promise.resolve(subscription),
};

const serviceWorker = {
    register: () => Promise.resolve({}),
    ready: Promise.resolve({ pushManager }),
};

switch (scenario) {
    case 'sw-named':
        serviceWorker.register = () =>
            Promise.reject(new DOMException('The operation is insecure.', 'SecurityError'));
        break;

    case 'sw-name-only':
        serviceWorker.register = () => Promise.reject({ name: 'SecurityError' });
        break;

    case 'sw-anonymous':
        serviceWorker.register = () => Promise.reject({});
        break;

    case 'sw-ok':
        break;

    case 'push-permission-rejects':
        globalThis.Notification.requestPermission = () =>
            Promise.reject(new DOMException('Permissions policy', 'NotAllowedError'));
        break;

    case 'push-denied':
        globalThis.Notification.requestPermission = () => Promise.resolve('denied');
        break;

    case 'push-subscribe-rejects':
        pushManager.subscribe = () =>
            Promise.reject(new DOMException('Push service unreachable', 'AbortError'));
        break;

    case 'push-granted':
        break;

    default:
        throw new Error(`Unknown scenario: ${scenario}`);
}

/* navigator is a read-only accessor on globalThis from node 21. */
Object.defineProperty(globalThis, 'navigator', {
    value: { serviceWorker },
    configurable: true,
    writable: true,
});

await import(module);

let result = null;

if (scenario.startsWith('push-')) {
    result = await window.cfbPush.enable('dGVzdA', '/push/subscriptions');
} else {
    for (const handler of listeners.load ?? []) {
        handler();
    }
}

/* Let the rejection handlers and the reporter's own fetch settle. */
for (let tick = 0; tick < 5; tick++) {
    await new Promise((resolve) => setImmediate(resolve));
}

console.log(JSON.stringify({ posts, result }));

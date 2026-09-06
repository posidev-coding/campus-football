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

const docListeners = {};

/* The upload seam registers on `livewire:init` and talks to Livewire's hook
 * bus, so the harness has to be able to BE Livewire for one commit. */
const hooks = {};
const knocks = [];
let knockResult = () => Promise.resolve();

globalThis.document = {
    querySelector: (selector) => ({
        content: selector.includes('cfb-error-endpoint') ? '/client-errors' : 'test-csrf-token',
    }),
    addEventListener: (name, handler) => {
        (docListeners[name] ??= []).push(handler);
    },
};

globalThis.Livewire = {
    hook: (name, callback) => {
        (hooks[name] ??= []).push(callback);
    },
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
    case 'asset-script':
    case 'asset-stylesheet':
    case 'asset-window-error':
    case 'island-failure':
        break;

    /* The screen the unnamed report actually landed on, so the path a report
     * carries is asserted rather than assumed. */
    case 'rejection-fetch':
    case 'rejection-name-only':
    case 'rejection-anonymous':
    case 'rejection-livewire':
    case 'rejection-string':
    case 'rejection-nothing':
        globalThis.location = { pathname: '/groups/51' };
        break;

    /* A commit carrying _startUpload that FAILS — the 500 raised before any
     * byte moves, which Livewire drops on the floor. */
    case 'upload-start-fails':
    case 'upload-start-fails-unnamed':
    case 'upload-start-knock-fails':
    case 'commit-unrelated-fails':
        globalThis.location = { pathname: '/groups/51' };
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

/* A resource error reaches the window ONLY in the capture phase, on the
 * element that failed, with no message and no position. The stub registers
 * both of the reporter's error listeners under one name, so every shape is
 * handed to both — which is also the browser's contract for the window's own
 * error event, and what the third scenario checks is reported exactly once. */
const assetEvents = {
    'asset-script': { target: { tagName: 'SCRIPT', src: 'https://campusfootball.test/flux/flux.min.js?id=1ea4120f' } },
    'asset-stylesheet': { target: { tagName: 'LINK', href: 'https://campusfootball.test/build/assets/app-abc.css' } },
    'asset-window-error': { target: globalThis, message: 'boom', filename: 'https://campusfootball.test/build/assets/app-abc.js', lineno: 3, colno: 4 },
};

/*
 * Everything a promise can be rejected WITH. A rejection is not a catch block:
 * nothing guarantees the reason is an Error, and the shapes below are the ones
 * production actually produces — a fetch that failed, a DOMException with only
 * a name, an anonymous object, Livewire's action rejection, and the two that
 * carry nothing at all.
 *
 * The fetch stack is browser-shaped on purpose: node writes file:// frames,
 * and the source the listener mines out of a stack is an https one.
 */
const failedFetch = new TypeError('Load failed');
failedFetch.stack = 'commit@https://campusfootball.test/build/assets/app-abc.js:12:34\n@[native code]';

const rejectionReasons = {
    'rejection-fetch': failedFetch,
    'rejection-name-only': { name: 'SecurityError' },
    'rejection-anonymous': {},
    'rejection-livewire': { status: 503, body: null, json: null, errors: null },
    'rejection-string': 'nope',
    'rejection-nothing': undefined,
};

if (scenario.startsWith('push-')) {
    result = await window.cfbPush.enable('dGVzdA', '/push/subscriptions');
} else if (scenario in assetEvents) {
    for (const handler of listeners.error ?? []) {
        handler(assetEvents[scenario]);
    }
} else if (scenario in rejectionReasons) {
    for (const handler of listeners.unhandledrejection ?? []) {
        handler({ reason: rejectionReasons[scenario] });
    }
} else if (scenario.startsWith('upload-start-') || scenario === 'commit-unrelated-fails') {
    for (const handler of docListeners['livewire:init'] ?? []) {
        handler();
    }

    if (scenario === 'upload-start-knock-fails') {
        knockResult = () => Promise.reject({ status: 500 });
    }

    /* The unrelated call carries a STRING first param on purpose. With an
     * empty params list the property guard would reject it anyway, and the
     * scenario would pass without the method filter existing at all. */
    const calls = scenario === 'commit-unrelated-fails'
        ? [{ method: 'save', params: ['iconFile'] }]
        : [{ method: '_startUpload', params: scenario === 'upload-start-fails-unnamed' ? [] : ['iconFile'] }];

    const component = {
        $wire: {
            call: (...args) => {
                knocks.push(args);

                return knockResult();
            },
        },
    };

    for (const hook of hooks.commit ?? []) {
        const failCallbacks = [];

        hook({ component, commit: { calls }, fail: (cb) => failCallbacks.push(cb) });

        failCallbacks.forEach((cb) => cb());
    }
} else if (scenario === 'island-failure') {
    /* The door the Blade islands report through — an Alpine `.catch()` that
     * knows what it was doing, which is the one thing the listener cannot. */
    window.cfbErrors.failure('iconFile upload knock failed (reportRefusedUpload)', { status: 503 });
} else {
    for (const handler of listeners.load ?? []) {
        handler();
    }
}

/* Let the rejection handlers and the reporter's own fetch settle. */
for (let tick = 0; tick < 5; tick++) {
    await new Promise((resolve) => setImmediate(resolve));
}

console.log(JSON.stringify({ posts, result, knocks }));

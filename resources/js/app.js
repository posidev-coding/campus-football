/*
 * The PWA seam. Everything visual lives in Livewire/Alpine components — this
 * file holds only what must exist outside any component: service-worker
 * registration, and the captured install prompt the Get the app screen offers
 * back to the user as a real button.
 */

/*
 * Chromium fires `beforeinstallprompt` once, early, and only if nothing has
 * consumed it — so it is captured here at boot rather than in a component
 * that may not be mounted yet. iOS never fires it; the Get the app screen
 * walks those users through the share sheet instead.
 */
window.cfbInstall = {
    deferred: null,

    available() {
        return this.deferred !== null;
    },

    /* Resolves 'accepted' | 'dismissed' | null (nothing to offer). The event
     * is single-use: either way it is spent afterwards. */
    async prompt() {
        if (this.deferred === null) return null;

        const event = this.deferred;
        this.deferred = null;

        event.prompt();

        const choice = await event.userChoice;

        window.dispatchEvent(new CustomEvent('cfb:install-choice', { detail: choice.outcome }));

        return choice.outcome;
    },
};

window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault();
    window.cfbInstall.deferred = event;
    window.dispatchEvent(new CustomEvent('cfb:install-ready'));
});

window.addEventListener('appinstalled', () => {
    window.cfbInstall.deferred = null;
    window.dispatchEvent(new CustomEvent('cfb:install-done'));
});

/*
 * Web push, the client half. Lives here beside the install prompt for the
 * same reason: two Blade islands (the Account switch, Home's nudge) share
 * one subscribe/unsubscribe machine, and duplicating key-decoding and
 * permission flow in two x-data blocks is how they drift.
 *
 * enable() MUST be called from inside a user-gesture handler — the
 * permission prompt is spent the moment it is shown, and a denied prompt
 * only comes back through OS settings.
 */
window.cfbPush = {
    supported() {
        return 'Notification' in window && 'PushManager' in window && 'serviceWorker' in navigator;
    },

    permission() {
        return this.supported() ? Notification.permission : null;
    },

    async subscribed() {
        if (!this.supported()) return false;

        const registration = await navigator.serviceWorker.ready;

        return (await registration.pushManager.getSubscription()) !== null;
    },

    /* Resolves 'granted' (subscribed and stored), 'denied', 'default'
     * (prompt dismissed), or 'error' (anything else went wrong).
     *
     * The try is that promise made TRUE. requestPermission and subscribe both
     * reject rather than resolve on some browsers — a wrong VAPID key, a
     * private window, a permissions policy — and the fetch was the only step
     * anybody had guarded. push-banner's turnOn() awaits this with no catch of
     * its own, so an escaping rejection left `busy` stuck true and the Turn on
     * button disabled for the rest of the session, while reporting itself as
     * the bare word "Rejected". */
    async enable(publicKey, storeUrl) {
        if (!this.supported()) return 'error';

        try {
            const permission = await Notification.requestPermission();

            if (permission !== 'granted') return permission;

            const registration = await navigator.serviceWorker.ready;

            const subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: this.serverKey(publicKey),
            });

            const body = subscription.toJSON();
            body.content_encoding = (window.PushManager.supportedContentEncodings || [])[0];

            const response = await fetch(storeUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                },
                body: JSON.stringify(body),
            }).catch(() => null);

            return response && response.ok ? 'granted' : 'error';
        } catch (error) {
            reportFailure('push subscription failed', error);

            return 'error';
        }
    },

    async disable(destroyUrl) {
        if (!this.supported()) return;

        const registration = await navigator.serviceWorker.ready;
        const subscription = await registration.pushManager.getSubscription();

        if (!subscription) return;

        const endpoint = subscription.endpoint;

        await subscription.unsubscribe();

        await fetch(destroyUrl, {
            method: 'DELETE',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
            },
            body: JSON.stringify({ endpoint }),
        }).catch(() => {});
    },

    /* VAPID public keys travel base64url; PushManager wants raw bytes. */
    serverKey(base64) {
        const padded = base64.padEnd(base64.length + ((4 - (base64.length % 4)) % 4), '=');
        const raw = window.atob(padded.replace(/-/g, '+').replace(/_/g, '/'));

        return Uint8Array.from(raw, (character) => character.charCodeAt(0));
    },
};

/*
 * The clipboard, guarded ONCE. writeText rejects off https and under
 * permissions policies, and every copy button used to call it bare —
 * flipping its "Copied" label over a clipboard that never changed, with
 * an unhandled rejection in the console as the only witness. Resolves
 * true only when the text actually landed; ChromeConsistencyTest bans
 * bare navigator.clipboard in Blade so buttons cannot drift back.
 */
window.cfbClipboard = {
    async copy(text) {
        if (!navigator.clipboard) return false;

        return navigator.clipboard.writeText(text).then(() => true).catch(() => false);
    },
};

/*
 * Client-side error capture — the one signal no server-side monitor can see.
 *
 * Pulse watches requests, queries, jobs and thrown exceptions; all of that is
 * the SERVER. A dead x-init, a rejected fetch or a swiper that never observes
 * is a 200 in every log and a green suite in CI — the automated tab produces
 * no rendering frames, so no test drives the code that breaks. The browser is
 * the only witness, and until now it was talking to a console nobody reads.
 *
 * Deliberately modest about what it sends:
 *
 *   - Cross-origin scripts report a bare "Script error." with no file and no
 *     position. That is a browser extension or a third party, it is not
 *     actionable, and it would be most of the volume. Dropped.
 *   - One report per distinct error per page, and at most five in total. A
 *     render loop can fire onerror thousands of times in a second, and the
 *     server's Redis dedupe should not be the only thing standing in front of
 *     that.
 *   - Every failure inside the reporter is swallowed. A reporter that can
 *     throw is a reporter that reports itself, forever.
 *
 * Registered here rather than in a pre-paint head script, which would catch
 * strictly more: the three inline scripts above it are a stamped attribute and
 * a counter, and buying those with a second reporting path is not worth it.
 */
window.cfbErrors = {
    endpoint: document.querySelector('meta[name=cfb-error-endpoint]')?.content ?? null,
    seen: new Set(),
    sent: 0,
    max: 5,

    report(fields) {
        if (!this.endpoint || this.sent >= this.max) return;

        /* A thrown error with no file and no position came from a
         * cross-origin script we cannot see into — an extension or a third
         * party, not actionable, and most of the volume. A REJECTION legitimately
         * has neither, so the guard is scoped to the one it can judge. */
        if (fields.kind === 'error' && !fields.source && !fields.line) return;

        const key = `${fields.kind}|${fields.message}|${fields.source}|${fields.line}|${fields.col}`;

        if (this.seen.has(key)) return;

        this.seen.add(key);
        this.sent++;

        const token = document.querySelector('meta[name=csrf-token]')?.content;

        if (!token) return;

        fetch(this.endpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': token,
            },
            body: JSON.stringify({
                ...fields,
                path: window.location.pathname,
                viewport: window.innerWidth,
                standalone: window.matchMedia('(display-mode: standalone)').matches
                    || window.navigator.standalone === true,
            }),
            keepalive: true,
        }).catch(() => {});
    },

    /*
     * The named door onto the reporter, for the Blade islands.
     *
     * One guarded machine here rather than a `.catch()` in every `x-data`
     * inventing its own wording — the trade window.cfbClipboard already makes
     * for copy. An island that knows WHAT it was doing when a promise rejected
     * knows the one thing the window-level listener below can never work out,
     * and this is where it says so.
     */
    failure(label, error) {
        reportFailure(label, error);
    },
};

window.addEventListener('error', (event) => {
    try {
        window.cfbErrors.report({
            kind: 'error',
            message: String(event.message ?? 'Unknown error'),
            source: event.filename || null,
            line: event.lineno || null,
            col: event.colno || null,
            stack: event.error?.stack ? String(event.error.stack) : null,
        });
    } catch { /* never let the reporter be the bug */ }
});

/*
 * A script or stylesheet that FAILED TO LOAD. Resource errors fire on the
 * element and never bubble, so the listener above cannot see them — and the
 * symptom they leave is a page of ReferenceErrors that name the missing
 * bundle's globals, never the bundle. Production read exactly that twice:
 * "$flux is not defined" beside "fluxModal is not defined", which is what
 * Alpine says when flux.min.js did not execute before it started, filed
 * against the screen rather than the asset (CFB-46). Capture phase is the
 * one place a load failure can be heard from the window; the handler skips
 * the window's own error events, which the listener above already owns.
 *
 * Only scripts and stylesheets: a missing team logo is a data question with
 * its own doctor, and images would spend the page's five reports on it.
 */
window.addEventListener('error', (event) => {
    try {
        const tag = event.target?.tagName;

        if (tag !== 'SCRIPT' && tag !== 'LINK') return;

        const url = event.target.src || event.target.href || null;

        if (!url) return;

        window.cfbErrors.report({
            kind: 'error',
            message: `Failed to load ${tag === 'SCRIPT' ? 'script' : 'stylesheet'} ${url}`,
            source: url,
            line: null,
            col: null,
            stack: null,
        });
    } catch { /* never let the reporter be the bug */ }
}, true);

/*
 * A rejection NOBODY CAUGHT — reported the way a caught one is.
 *
 * It used to be flattened to `String(reason?.message ?? reason)`, and what
 * production read off /groups/51 in an installed app was the two bare words
 * "Load failed": Safari's message for any failed fetch, with source null, line
 * null and no name to say even that much. The message half alone identifies
 * neither the request nor the code that made it, so the reason's NAME travels
 * with it now, through the same failureMessage() the service-worker path uses
 * — and under the same contract, which is that neither half is ever invented.
 *
 * The label is what the event IS, not a guess at what failed. A caller who
 * knows better should be calling window.cfbErrors.failure() with a real one;
 * this report is deliberately, unmistakably the anonymous one.
 */
window.addEventListener('unhandledrejection', (event) => {
    try {
        const reason = event.reason;

        window.cfbErrors.report({
            kind: 'unhandledrejection',
            message: failureMessage('unhandled rejection', rejectionDetail(reason)),
            /* A rejection carries no filename of its own; the first stack
             * frame is the closest thing to one, and it is what makes two
             * rejections distinguishable at all. */
            source: reason?.stack ? (String(reason.stack).match(/https?:\/\/[^\s):]+/)?.[0] ?? null) : null,
            line: null,
            col: null,
            stack: reason?.stack ? String(reason.stack) : null,
        });
    } catch { /* never let the reporter be the bug */ }
});

/*
 * A rejection we catch ourselves, named — reported in the same shape the
 * unhandledrejection listener above would have used, and swallowing its own
 * failures for the same reason that listener does.
 *
 * `import.meta.url` is this bundle. A rejection carries no filename of its
 * own, which is why the listener has to go mining in a stack for one; caught
 * here, the source is simply known.
 */
function reportFailure(label, error) {
    try {
        window.cfbErrors.report({
            kind: 'unhandledrejection',
            message: failureMessage(label, error),
            source: import.meta.url,
            line: null,
            col: null,
            stack: error?.stack ? String(error.stack) : null,
        });
    } catch { /* never let the reporter be the bug */ }
}

/*
 * "service-worker registration failed: SecurityError: The operation is
 * insecure." — what failed, then what the browser said about it.
 *
 * Both halves of the detail are optional and NEITHER is ever invented. A
 * rejection with no name contributes no name; one with neither leaves the
 * label standing alone rather than padded out with "Unknown error". No data
 * is no data here as everywhere else — and a placeholder is precisely the
 * thing this replaces, since "Rejected" is what the browser was already
 * giving us.
 */
function failureMessage(label, error) {
    const detail = [error?.name, error?.message].filter(Boolean).join(': ');

    return detail ? `${label}: ${detail}` : label;
}

/*
 * What a rejected reason may contribute to failureMessage, and no more.
 *
 * A rejection can be handed ANYTHING, where a catch block is usually handed an
 * Error. An Error — or a DOMException, or anything else carrying a name and a
 * message — contributes both. A primitive IS the message and travels as one,
 * because `Promise.reject('nope')` knows exactly one thing and losing it would
 * be the bug this replaces.
 *
 * Null, undefined and a bare object know NOTHING a reader can act on, and the
 * last of those is the trap: `{}` and Livewire's own
 * `{ status, body, json, errors }` both stringify to "[object Object]", a
 * placeholder wearing data's clothes. They contribute nothing, and the label
 * stands alone rather than being padded out — no data is no data here as
 * everywhere else.
 */
function rejectionDetail(reason) {
    if (reason === null || reason === undefined) return null;

    if (typeof reason === 'object' || typeof reason === 'function') return reason;

    return { message: String(reason) };
}

/*
 * Registration rejects for reasons that are not code defects — Safari private
 * browsing, a content blocker, a 404 or a parse error on /sw.js — so this is
 * not an exception. It is still worth knowing: no service worker means no push
 * subscription and no offline shell on that device, and the push banner will
 * go on pitching notifications regardless, because supported() only asks
 * whether the APIs exist.
 *
 * Left bare it was the one promise in this file with no rejection handler, and
 * it reported itself through the listener above as the useless single word
 * "Rejected" — burning two of the five reports a page is allowed before a real
 * bug can get a word in. Swallowing it would have traded a useless signal for
 * no signal; it is named instead.
 */
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch((error) => {
            reportFailure('service-worker registration failed', error);
        });
    });
}

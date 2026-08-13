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
     * (prompt dismissed), or 'error' (the server write failed). */
    async enable(publicKey, storeUrl) {
        if (!this.supported()) return 'error';

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

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js');
    });
}

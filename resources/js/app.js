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

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js');
    });
}

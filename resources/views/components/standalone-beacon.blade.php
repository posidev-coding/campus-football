{{--
    Reports "this session runs standalone" to the server, once — the stamp
    behind User::hasInstalled(), which is what lets a plain browser tab
    (the post-verify landing) know the reader has the app.

    sessionStorage, not localStorage, deliberately: a failed write must
    retry next session, and two people on one shared phone must not answer
    for each other — the guard is only set when the server said 204, and
    the server's own null-check is the real idempotence. Wrapped in @auth
    because the column lives on a user; a guest launch simply reports on
    the first signed-in load instead.
--}}
@auth
    <div
        hidden
        data-standalone-beacon
        x-data="{
            standalone() {
                return window.matchMedia('(display-mode: standalone)').matches
                    || window.navigator.standalone === true;
            },

            report() {
                if (! this.standalone()) return;
                if (sessionStorage.getItem('cfb.standalone.seen')) return;

                fetch(@js(route('standalone.seen')), {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    },
                }).then((response) => {
                    if (response.ok) sessionStorage.setItem('cfb.standalone.seen', '1');
                }).catch(() => {});
            },
        }"
        x-init="report()"
    ></div>
@endauth

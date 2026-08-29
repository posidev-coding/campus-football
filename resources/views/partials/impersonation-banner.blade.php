{{--
    The amber bar that says out loud whose account this is.

    An impersonation nobody can tell they are inside is how an admin posts as a
    member by accident, so this is not decoration — it is the feature's safety
    half, and it lives in the PRODUCT layout because that is where the
    impersonated session actually spends its time. Fixed to the top and inline
    at the head of the document, so it is the first thing painted and cannot be
    scrolled away from.

    The exit is a real form POST: it changes who is signed in, so it rides CSRF
    like every other state change. Plain form, no wire:navigate.
--}}
<div class="sticky top-0 z-50 flex items-center justify-between gap-3 bg-amber-400 px-4 py-2 text-sm text-amber-950">
    <span class="min-w-0 truncate font-medium">
        Signed in as {{ auth()->user()->name }} &mdash; you are impersonating.
    </span>

    <form method="POST" action="{{ route('impersonation.leave') }}" class="shrink-0">
        @csrf
        <button type="submit"
                class="rounded-md bg-amber-950/10 px-2.5 py-1 font-semibold hover:bg-amber-950/20">
            Return to admin
        </button>
    </form>
</div>

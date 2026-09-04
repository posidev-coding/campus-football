---
paths:
  - 'resources/views/filament/**,app/Filament/**'
---

# Filament

## The panel has its own Tailwind, and a Kanban's group must be x-sort:group
Since 2026-08-24 the panel compiles its own Tailwind at `resources/css/filament/admin/theme.css` (`->viteTheme()`), so hand-written classes in admin views finally work. It scans ONLY `app/Filament/**` and `resources/views/filament/**` — a custom admin view anywhere else still compiles to no CSS and renders unstyled, silently. Flux remains unavailable in the panel (its components need Flux's own bundles). `->viteTheme()` resolves through Vite, so admin pages 500 on a checkout where `npm run build` has not run.

Cross-column `wire:sort` (the Workbook board): bind the Sortable group with Alpine's **`x-sort:group`**, never `wire:sort:group`. Livewire's attribute loop in `supportWireSort.js` `return`s on `wire:sort:group`, so if it precedes `wire:sort` in the source, `wire:sort` never binds and the drag silently does nothing; `getGroupName()` reads either attribute. `wire:sort:group-id` on each list is appended as a THIRD handler argument and Sortable fires the handler on the DESTINATION list — that is how a drop says which column it landed in. Sortable's index is 0-based, stored positions are 1-based, and the column an item LEAVES must be renumbered or the next drop lands wrong.

A table sorting an enum-backed string column must use `FIELD()`: alphabetical order on severity is critical-high-low-medium.

## Filament splits an array state into a list, and modals render under nothing
A `TextEntry`/`TextColumn` whose state is an ARRAY — which an `array`/`json` cast on the attribute makes it — is rendered by Filament as a LIST: `formatStateUsing()` is called once per ELEMENT, with the element. A `fn (?array $state)` hint is then a TypeError on the first row that has data, and it fires only when the modal opens. Override `->state(fn (Model $record) => …)` to collapse it to a scalar before Filament sees the array; do not type the formatter's argument as an array. (Verified: `evidence` on WorkbookItem, 2026-08-25.)

Modals are the panel's blind spot. `assertCanSeeTableRecords` proves the ROWS render — a ViewAction infolist, an EditAction form, and a CreateAction's `mutateDataUsing` all run only when the modal mounts, so they ship rendered by nothing. Cover them with `->mountAction(TestAction::make(ViewAction::class)->table($record))->assertMountedActionModalSee(...)` and `->callAction(CreateAction::class, [...])`. Same family as `#[Lazy]` Pulse cards: green in CI, red in a browser.

## A bare-letter shortcut cannot use keyBindings(), and "a modal is open" is a DOM question
Filament's `->keyBindings(['c'])` renders `x-mousetrap.global`, and `bindGlobal` fires INSIDE inputs by design — right for the cmd+k it was built for, wrong for a bare letter, which would fire on the "c" of "coverage" typed into the panel's global search. A single-letter shortcut needs a hand-rolled `x-on:keydown.c.window` guarded on: a system modifier held (Cmd+C is a copy), an editable target (INPUT/SELECT/TEXTAREA/contenteditable), a modal already open, and `event.repeat`.

Ask the DOM whether a modal is open, not Livewire. `$wire.mountedActions` goes on naming the action it just closed until the next round trip clears it — over a second after Escape, long enough to swallow the keystroke that follows. Filament's own mousetrap directive looks for a visible `[aria-modal="true"]`; do the same. Inside a double-quoted attribute write the selector value unquoted: `[aria-modal=true]`.

Put the body in an `x-data` METHOD and leave a plain call in the `x-on` — same reason as every other multi-statement Alpine expression. A test can only assert the rendered attribute; the guards themselves are browser-only (verified on the Workbook board, 2026-09-04).

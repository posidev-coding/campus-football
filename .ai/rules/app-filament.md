---
paths:
  - 'app/Filament/**'
---

# App Filament

## Filament splits an array state into a list, and modals render under nothing
A `TextEntry`/`TextColumn` whose state is an ARRAY — which an `array`/`json` cast on the attribute makes it — is rendered by Filament as a LIST: `formatStateUsing()` is called once per ELEMENT, with the element. A `fn (?array $state)` hint is then a TypeError on the first row that has data, and it fires only when the modal opens. Override `->state(fn (Model $record) => …)` to collapse it to a scalar before Filament sees the array; do not type the formatter's argument as an array. (Verified: `evidence` on WorkbookItem, 2026-08-25.)

Modals are the panel's blind spot. `assertCanSeeTableRecords` proves the ROWS render — a ViewAction infolist, an EditAction form, and a CreateAction's `mutateDataUsing` all run only when the modal mounts, so they ship rendered by nothing. Cover them with `->mountAction(TestAction::make(ViewAction::class)->table($record))->assertMountedActionModalSee(...)` and `->callAction(CreateAction::class, [...])`. Same family as `#[Lazy]` Pulse cards: green in CI, red in a browser.

/*
 * Drives the RENDERED `<x-image-file-input>` control under node.
 *
 * The gates that matter here live in an Alpine `x-data` object inside a
 * Blade component, which has no Livewire component around it and no browser
 * in this suite. Sweeping the source for `$wire.upload(` would pass on a
 * call that hands Livewire the wrong argument in the wrong position — and a
 * source assertion is exactly what let the missing error callback ship. So
 * the object literal is EVALUATED against a stubbed `$wire` and the gates
 * are fired, the same trade tests/pwa-seam-harness.mjs makes for app.js.
 *
 * Usage: node tests/upload-control-harness.mjs <scenario> <path-to-x-data>
 * The file holds the decoded `x-data` attribute value, nothing else.
 * Prints one JSON line: `{ calls, uploads, errorCallback }`.
 */

import { readFileSync } from 'node:fs';

const [scenario, source] = process.argv.slice(2);

/** Every `$wire.call(...)` the control made, in order. */
const calls = [];

/** Every `$wire.upload(...)`, kept whole so argument POSITION is assertable. */
const uploads = [];

const $wire = {
    call: (...args) => {
        calls.push(args);

        return Promise.resolve();
    },
    upload: (...args) => {
        uploads.push(args);
    },
};

/*
 * Alpine evaluates `x-data` as an object literal with `$wire` in scope; a
 * function parameter is the same scoping, without pulling Alpine in.
 */
const data = new Function('$wire', `return (${readFileSync(source, 'utf8')})`)($wire);

/** A picker change event, stubbed down to what the control reads. */
const change = (size) => ({ target: { files: [{ size }], value: 'C:\\fakepath\\mark.png' } });

if (scenario === 'oversized') {
    data.pick(change(data.max + 1));
}

if (scenario === 'accepted' || scenario === 'refused') {
    data.pick(change(1024));
}

/*
 * The refusal itself: Livewire hands the error callback back when the round
 * trip fails. Missing, `uploads[0][3]` is undefined and this scenario ends
 * with an empty `calls` — which is the bug, stated as an outcome.
 */
if (scenario === 'refused' && typeof uploads[0]?.[3] === 'function') {
    uploads[0][3]();
}

process.stdout.write(JSON.stringify({
    calls,
    uploads: uploads.map((args) => args.map((arg) => typeof arg === 'function' ? 'function' : arg)),
    errorCallback: uploads.length > 0 ? typeof uploads[0][3] : null,
}));

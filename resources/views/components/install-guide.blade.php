@props(['platform'])

{{--
    THE canonical per-platform install steps — the get-app screen and the
    tour's install stop both render from here, so the instructions cannot
    drift between the two surfaces.

    The steps quote Apple's and Google's own labels verbatim (the reader is
    hunting for those exact words), and the iPhone Chrome/Firefox walkthroughs
    route through More: both browsers open the system share sheet with
    Add to Home Screen tucked behind it on a stock action list — a step that
    was learned on a real phone, not from documentation.
--}}
@php
    $steps = match ($platform) {
        'ios-safari' => [
            ['icon' => 'box-arrow-up', 'text' => 'Tap <strong>Share</strong> in Safari\'s toolbar.'],
            ['icon' => 'plus-square', 'text' => 'Scroll down and tap <strong>Add to Home Screen</strong>.'],
            ['icon' => 'phone', 'text' => 'Tap <strong>Add</strong> — the icon lands on your home screen.'],
        ],
        'ios-chrome' => [
            ['icon' => 'box-arrow-up', 'text' => 'Tap <strong>Share</strong> at the top of Chrome.'],
            ['icon' => 'plus-square', 'text' => 'Tap <strong>Add to Home Screen</strong> — if it\'s not in the list, tap <strong>More</strong> first.'],
            ['icon' => 'phone', 'text' => 'Tap <strong>Add</strong> — the icon lands on your home screen.'],
        ],
        'ios-firefox' => [
            ['icon' => 'box-arrow-up', 'text' => 'Tap <strong>Share</strong> in Firefox\'s toolbar.'],
            ['icon' => 'plus-square', 'text' => 'Tap <strong>Add to Home Screen</strong> — if it\'s not in the list, tap <strong>More</strong> first.'],
            ['icon' => 'phone', 'text' => 'Tap <strong>Add</strong> — the icon lands on your home screen.'],
        ],
        'android' => [
            ['icon' => 'three-dots-vertical', 'text' => 'Open Chrome\'s menu at the top right.'],
            ['icon' => 'download', 'text' => 'Tap <strong>Add to Home screen</strong>, then <strong>Install</strong>.'],
            ['icon' => 'phone', 'text' => 'The app installs like any other — icon, full screen, the works.'],
        ],
        'desktop' => [
            ['icon' => 'download', 'text' => 'Click the <strong>install icon</strong> at the right end of the address bar.'],
            ['icon' => 'phone', 'text' => 'Or open the browser menu and choose <strong>Install app</strong>. It docks like a native app — its own window, its own icon.'],
            ['icon' => 'globe-alt', 'text' => 'On desktop Firefox there is no install — use Chrome or Edge there, or grab it on your phone with the steps above.'],
        ],
    };
@endphp

<x-install-steps :steps="$steps" />

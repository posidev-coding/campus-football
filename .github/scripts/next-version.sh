#!/usr/bin/env bash
#
# Print the version that follows the one given.
#
# A pre-release bumps its trailing number:  4.0.0-beta.3  ->  4.0.0-beta.4
# A release bumps its patch:                4.0.3         ->  4.0.4
#
# That is the whole automatic ladder, on purpose. A minor or a major is a
# decision, and the way to make it is to edit VERSION in the pull request —
# the Release workflow tags whatever number it finds there untagged, and only
# reaches for this script when the number is already taken.
#
# Anything this cannot read exits non-zero and prints nothing: a guessed
# version is worse than a failed run. ReleaseTest drives every branch here.

set -euo pipefail

current="${1:-}"

if [[ "$current" =~ ^([0-9]+)\.([0-9]+)\.([0-9]+)$ ]]; then
    echo "${BASH_REMATCH[1]}.${BASH_REMATCH[2]}.$((BASH_REMATCH[3] + 1))"
elif [[ "$current" =~ ^([0-9]+\.[0-9]+\.[0-9]+-.*[^0-9])([0-9]+)$ ]]; then
    echo "${BASH_REMATCH[1]}$((BASH_REMATCH[2] + 1))"
else
    echo "next-version: cannot bump '${current}' — expected 4.0.3 or 4.0.0-beta.3" >&2
    exit 1
fi

#!/usr/bin/env bash
#
# Push the release to main and tag it, surviving a merge that lands mid-run.
#
#   .github/scripts/push-release.sh 4.0.0-beta.4
#
# Run from the Release workflow's clone, with VERSION already saying the number
# and, when the bot bumped it, the one "Release vX" commit already made on top
# of the commit the run checked out.
#
# `concurrency: release` serializes release RUNS, not MERGES. A merge that lands
# between checkout and push moves main, and a bare `git push origin HEAD:main`
# is then rejected and the run dies red before it tags — 9 of 21 runs on
# 2026-11-16. So on a rejection this fetches main, rebases the single Release
# commit onto it, and tries again, a bounded number of times. The tag goes on
# whatever commit actually reached main.
#
# The number stays right through a rebase, because runs are serialized and a
# merge never touches VERSION unless its pull request chose a number. That is
# the one exception, and it shows up as a CONFLICT on VERSION: the rebase is
# aborted, NOTHING is tagged, and this exits 0 — the next run tags the number
# that pull request chose. A rebase that leaves nothing to push means main
# already carries this same bump (an earlier run released it), which is also
# a clean exit with nothing tagged.
#
# A pushed tag is never moved: no --force anywhere, and a tag that already
# exists fails the run rather than being rewritten.
#
# Writes `released=true|false` to $GITHUB_OUTPUT when it is set, so the
# workflow's later steps (release notes, deploy) run only when a tag was
# pushed. Anything unexpected exits non-zero. ReleaseTest drives every branch
# here against a throwaway bare repository.

set -euo pipefail

version="${1:-}"
remote="origin"
branch="main"
attempts=5

say() {
    echo "push-release: $*"
}

released() {
    if [ -n "${GITHUB_OUTPUT:-}" ]; then
        echo "released=$1" >> "$GITHUB_OUTPUT"
    fi
}

if [ -z "$version" ]; then
    echo "push-release: no version given" >&2
    exit 1
fi

if [ "$(tr -d '[:space:]' < VERSION)" != "$version" ]; then
    echo "push-release: VERSION does not say ${version}" >&2
    exit 1
fi

tag="v${version}"

fetch_main() {
    git fetch --quiet "$remote" "+refs/heads/${branch}:refs/remotes/${remote}/${branch}"
}

fetch_main

ahead="$(git rev-list --count "${remote}/${branch}..HEAD")"

if [ "$ahead" -gt 1 ]; then
    echo "push-release: ${ahead} commits ahead of ${remote}/${branch}; expected the one Release commit" >&2
    exit 1
fi

# One commit ahead is the bot's bump: get it onto main first. Zero is a number
# the pull request chose, already on main, and only needs its tag.
if [ "$ahead" -eq 1 ]; then
    pushed=false

    for ((attempt = 1; attempt <= attempts; attempt++)); do
        if git push "$remote" "HEAD:refs/heads/${branch}"; then
            pushed=true
            break
        fi

        say "${branch} moved under attempt ${attempt} of ${attempts}; rebasing the Release commit onto it."

        fetch_main

        if ! git rebase --quiet "${remote}/${branch}"; then
            git rebase --abort
            say "a later merge changed VERSION; ${tag} is not tagged, and the next run tags the number that merge chose."
            released false
            exit 0
        fi

        if [ "$(git rev-list --count "${remote}/${branch}..HEAD")" -eq 0 ]; then
            say "${branch} already carries this bump; ${tag} was released by an earlier run."
            released false
            exit 0
        fi
    done

    if [ "$pushed" != "true" ]; then
        echo "push-release: could not push the Release commit after ${attempts} attempts" >&2
        released false
        exit 1
    fi
fi

git tag -a "$tag" -m "Release ${tag}"
git push "$remote" "refs/tags/${tag}"

say "${tag} is on $(git rev-parse --short HEAD)."
released true

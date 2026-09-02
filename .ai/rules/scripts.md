---
paths:
  - 'VERSION,app/Support/Release.php,.github/workflows/release.yml,.github/scripts/next-version.sh,config/cfb.php'
---

# Scripts

## VERSION is the release stamp, the tag is v + VERSION, and a PR chooses a number by editing the file
The app never asks git for its version: the deployed image carries no .git and Laravel Cloud injects no commit or tag variable, so VERSION at the repository root is the one source. App\Support\Release reads it through config('cfb.version_file') and resolves NULL when the file is missing, blank or not semver — Account and the avatar menu then print nothing, never a default (ReleaseTest breaks back on a 0.0.0 fallback). .github/workflows/release.yml runs on every push to main, serialized, and tags v<VERSION>: as-is when that tag does not exist (a pull request chose the number — this is the whole override for a minor, a major, or leaving beta), otherwise bumped by .github/scripts/next-version.sh (beta.N+1 while a pre-release, patch+1 after) with a bot commit "Release vX". Never hardcode a version elsewhere, never move a pushed tag, and keep the workflow's own `permissions: contents: write` — the repository default token is read-only. With push-to-deploy on, a bumped merge deploys twice; the deploy-hook switch in docs/operations.md "Releases" makes it one deploy at the tagged commit.

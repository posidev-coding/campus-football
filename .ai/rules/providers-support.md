---
paths:
  - 'app/Providers/AppServiceProvider.php,app/Support/R2SignedUploadUrl.php,config/filesystems.php,config/livewire.php'
---

# Providers Support

## Livewire uploads go DIRECT to R2 in production, and must not sign an ACL
`LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK=r2` is set only on the deployment, and `r2` is the `s3` driver — so `FileUploadConfiguration::isUsingS3()` is TRUE in production and FALSE everywhere else. Deployed, Livewire does not post files to its own `upload-file` endpoint at all: it hands the browser a pre-signed PUT straight at `<bucket>.<account>.r2.cloudflarestorage.com`. This is why every upload works locally and none of them worked deployed — the two environments do not share a code path.

`GenerateSignedUploadUrl::forS3()` hardcodes `'ACL' => 'private'` onto that PUT, as a signed query parameter AND a request header. R2 answers `NotImplemented` — config/filesystems.php has said since the disk was added that no upload feeding R2 may ask for a visibility. `App\Support\R2SignedUploadUrl` passes an empty visibility, which `forS3`'s `array_filter` drops; it is BOUND in AppServiceProvider, never swapped, because a swap displaces the stub Livewire installs for its own tests.

The PUT is also CROSS-ORIGIN, so the bucket needs a CORS policy allowing PUT from the app origin. That lives in Cloudflare, not this repo — `LivewireBucketUploadTest` asserts the signed host so the requirement cannot be forgotten.

Testing this needs one trick: under a test run `FileUploadConfiguration::disk()` ignores the config and returns `tmp-for-tests`, faking it ONLY if it does not already resolve. Define `filesystems.disks.tmp-for-tests` bucket-shaped and the real signing runs. Pre-signing is string building — no credentials, no network.

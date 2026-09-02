---
paths:
  - 'app/Providers/AppServiceProvider.php,app/Support/R2SignedUploadUrl.php,app/Support/R2Writes.php,config/filesystems.php,config/livewire.php'
---

# Providers Support Support

## "No ACL" has two halves, and the checksum keys live at the disk's top level
R2 rejects object ACLs, and there are TWO writers to strip them from. (1) The BROWSER's presigned PUT: `R2SignedUploadUrl` passes an empty visibility (the older rule above). (2) The APP's own writes — SetGroupIcon's copy-back, the account photo, Brand, Filament — go through Flysystem's S3 adapter, which puts `ACL` on EVERY PutObject with no option to omit it; `throw => true` made each refusal a 500 while the suite stayed green (CFB-41, fixed 2026-09-01). `App\Support\R2Writes::attach()` is an init middleware on the S3 CLIENT that unsets `ACL`; `AppServiceProvider::register()` wraps the `s3` driver via `Storage::extend('s3', …)` and attaches it when a disk carrying `'no_acl' => true` RESOLVES. Never attach at boot: that client is not the one tests see (phpunit sets UPLOAD_DISK=public, useR2() flips config after boot) and it does not survive forgetDisk(). The driver name stays `s3` so Livewire's isUsingS3() is untouched. `request_checksum_calculation` / `response_checksum_validation` must sit at the disk's TOP level — `options` is the adapter's per-write bag the SDK never reads, and a pin there is inert; UploadDiskTest asks the resolved client, not the config. `php artisan cfb:uploads:doctor [--probe] [--force]` reports all of it; `--probe` writes to the named bucket and refuses outside production without --force.

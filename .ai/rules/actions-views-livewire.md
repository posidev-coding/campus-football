---
paths:
  - 'app/Support/R2Writes.php,app/Providers/AppServiceProvider.php,app/Actions/SetGroupIcon.php,resources/views/livewire/account.blade.php'
---

# Actions Views Livewire

## A platform mounts the DISK, not the AWS_* names — and a mounted disk must be forced to throw
Confirmed on Laravel Cloud 2026-09-02. Attaching a bucket in Cloud's UI adds a fully-configured `s3` disk under the name given there (`app`); the AWS_* values on the Resources tab are reference, NOT variables injected into the app — so `config('filesystems.disks.r2')` stayed empty forever and no upload had ever worked. `UPLOAD_DISK` and `LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK` point at the mounted disk's own name. Because that disk's config belongs to the platform, `R2Writes::harden()` fills in three things for any disk carrying `no_acl` or an R2 endpoint host, before the client is constructed: both checksum pins and `throw => true`. `throw` is the load-bearing one — a disk with `throw => false` answers a refused write with FALSE, and Livewire's `TemporaryUploadedFile::storeAs()` DISCARDS what `put()` returned and hands back the path it intended to write, so on the path production takes a refusal is indistinguishable from success and the column takes a path to an object that does not exist. No caller-side check can close that gap (a plain `UploadedFile::storeAs` does return false, and SetGroupIcon guards it, but that is not the production path). `cfb:uploads:doctor` prints every disk with whether it holds a bucket and names which to point at.

---
paths:
  - 'app/Providers/AppServiceProvider.php,app/Support/R2Writes.php,config/filesystems.php,app/Console/Commands/UploadsDoctorCommand.php'
---

# Providers Support Console Commands

## Platform-injected bucket credentials miss config:cache, and the disk name is not ours to assume
Found on Laravel Cloud 2026-09-02, after CFB-41's fix shipped. Two things, both invisible from inside this repo. (1) A hand-typed environment variable exists when the config is BUILT; a variable the platform attaches to the environment (a mounted bucket's AWS_BUCKET / AWS_ENDPOINT / AWS_ACCESS_KEY_ID) arrives at RUN time, so `config:cache` freezes it as null and `Storage::disk('r2')` cannot be constructed at all — a TypeError from Flysystem about a null bucket, not a misconfiguration message. Reading config alone diagnoses this as "unset" and sends you to config/filesystems.php, where the disk is correct; `cfb:uploads:doctor` reads env() beside config() and names the gap. Fix by making the names present at build time and redeploying — never by adding a fallback in the disk. (2) The platform chooses the DISK NAME its bucket is mounted under (`app` here), and no key can be added to a config array that is not in this tree — so `R2Writes::wants()` attaches the ACL-stripping middleware for `no_acl` OR an endpoint host ending `r2.cloudflarestorage.com`. The key stays the documented switch; the endpoint is the net, and is safe because R2 rejects ACLs and modern AWS buckets have them disabled. A doctor command must never throw from the fault it is reporting: skip the probe, report the disk.

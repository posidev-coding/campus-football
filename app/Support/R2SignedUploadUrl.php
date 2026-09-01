<?php

namespace App\Support;

use Livewire\Features\SupportFileUploads\GenerateSignedUploadUrl;

/**
 * Livewire's direct-to-bucket upload, with the ACL taken back off.
 *
 * When the temporary upload disk is S3-shaped — and `r2` is, it is the `s3`
 * driver — Livewire stops posting files to its own endpoint and hands the
 * BROWSER a pre-signed PUT straight at the bucket. It signs that PUT with
 * `x-amz-acl: private`, both as a query parameter and as a request header.
 *
 * R2 does not implement object ACLs and REJECTS them rather than ignoring
 * them; config/filesystems.php has said so since the disk was added, and
 * said that neither the disk nor any upload feeding it may ask for one.
 * Livewire's S3 path is exactly such an upload, and it asks on every file.
 * `LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK=r2` is set only in production, which
 * is why every upload works locally and none of them work deployed.
 *
 * Dropping it is a falsy visibility: {@see GenerateSignedUploadUrl::forS3()}
 * builds its command through `array_filter`, so an empty ACL is removed from
 * the request rather than sent empty. Visibility on R2 belongs to the BUCKET
 * and is chosen when it is created, so there is nothing to say here anyway —
 * and on a modern AWS bucket, which has ACLs disabled by default, asking is
 * equally wrong.
 */
class R2SignedUploadUrl extends GenerateSignedUploadUrl
{
    /**
     * @param  mixed  $file
     * @param  string  $visibility  ignored — see the class docblock
     */
    public function forS3($file, $visibility = ''): array
    {
        return parent::forS3($file, '');
    }
}

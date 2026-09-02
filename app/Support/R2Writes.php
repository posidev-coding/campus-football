<?php

namespace App\Support;

use Aws\CommandInterface;
use Aws\Middleware;
use Aws\S3\S3Client;

/**
 * The app's own writes to R2, with the ACL taken back off.
 *
 * {@see R2SignedUploadUrl} removed the ACL from the BROWSER's presigned PUT
 * (2026-08-31), and that was half of CFB-41. The other half is every write
 * the app makes for itself — SetGroupIcon's copy-back, the account photo,
 * the brand assets, a Filament upload — which all go through Flysystem's
 * S3 adapter. That adapter puts an `ACL` on every PutObject (`private`
 * when nothing asked for a visibility) and has no option to omit it; R2
 * implements no object ACLs and answers `NotImplemented`, and the disk's
 * `throw => true` turns that into a 500 on the Livewire update. So the
 * icon never landed in production while every test stayed green.
 *
 * ONE SEAM: an init middleware on the S3 CLIENT that unsets `ACL` from the
 * command before it is serialized, named so it can be found and so a second
 * attach replaces rather than stacks. Attached when the disk RESOLVES —
 * see AppServiceProvider::register() — never at boot: a boot-time attach is
 * on a client the tests never see (phpunit.xml sets UPLOAD_DISK=public and
 * useR2() flips config after boot) and would not survive forgetDisk().
 *
 * The init step also runs on the presigned PUT (Aws\serialize walks the
 * whole handler list), where it is a harmless no-op beside the signer.
 */
class R2Writes
{
    public const NAME = 'r2.no-acl';

    public static function attach(S3Client $client): void
    {
        $list = $client->getHandlerList();

        // Idempotent: replace, never stack.
        $list->remove(self::NAME);

        // mapCommand calls $handler($f($command)), so the closure must
        // RETURN the command — a void closure hands the handler null.
        $list->appendInit(Middleware::mapCommand(function (CommandInterface $command): CommandInterface {
            unset($command['ACL']);

            return $command;
        }), self::NAME);
    }

    /** Whether this client carries the middleware — what the doctor reports. */
    public static function attached(S3Client $client): bool
    {
        return str_contains((string) $client->getHandlerList(), 'Name: '.self::NAME);
    }
}

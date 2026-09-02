<?php

namespace App\Console\Commands;

use App\Support\R2Writes;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Throwable;

/**
 * The upload path, at the terminal — one line per thing that has failed
 * silently in production.
 *
 * Whether the deployment even sets UPLOAD_DISK=r2, AWS_URL and a CORS policy
 * that allows PUT cannot be decided from this repository, so this answers
 * what it can from inside the app: the resolved disk, Livewire's branch,
 * which env names are set (NAMES, never values — this output lands in a
 * terminal and a support thread), the SDK's checksum mode as the CLIENT
 * reads it, and whether the ACL-stripping middleware is on that client.
 *
 * `--probe` is the only step that talks to the bucket: it writes a 12-byte
 * object, reads it back, HEADs its public URL and deletes it. That WRITES to
 * whatever bucket the environment names, and docs/operations.md warns that
 * a local .env can hold the live keys — so it prints the disk and bucket
 * name first and refuses outside production unless `--force`.
 *
 * Same shape as cfb:doctor: non-zero exit on any failing line.
 */
#[Signature('cfb:uploads:doctor {--probe : Write, read, HEAD and delete a 12-byte object on the upload disk} {--force : Allow --probe outside production}')]
#[Description('Check the upload disk: driver, env names, checksum mode, ACL-free writes, and optionally a live probe')]
class UploadsDoctorCommand extends Command
{
    private int $failing = 0;

    public function handle(): int
    {
        $disk = (string) config('cfb.upload_disk');
        $config = config("filesystems.disks.{$disk}");

        $this->check($config !== null, 'Upload disk', $config === null
            ? "{$disk} — no such disk in config/filesystems.php"
            : "{$disk} ({$config['driver']})");

        $temp = config('livewire.temporary_file_upload.disk') ?? config('filesystems.default');
        $this->check(true, 'Livewire temp disk', $temp.' · direct-to-bucket: '.(FileUploadConfiguration::isUsingS3() ? 'yes' : 'no'));

        if (($config['driver'] ?? null) !== 's3') {
            $this->line('  <fg=gray>local disk — the R2 checks do not apply</>');
        } else {
            $this->checkBucketConfig($disk, $config);
        }

        if ($this->option('probe')) {
            $this->probe($disk, $config ?? []);
        }

        $this->newLine();

        if ($this->failing > 0) {
            $this->line("  <fg=red>{$this->failing} failing</>");

            return self::FAILURE;
        }

        $this->line('  <fg=gray>all checks passing</>');

        return self::SUCCESS;
    }

    /** @param  array<string, mixed>  $config */
    private function checkBucketConfig(string $disk, array $config): void
    {
        // Names, never values. Read off config rather than env(): with the
        // config cached in production, env() answers null for everything.
        foreach (['AWS_URL' => 'url', 'AWS_BUCKET' => 'bucket', 'AWS_ENDPOINT' => 'endpoint', 'AWS_ACCESS_KEY_ID' => 'key'] as $env => $key) {
            $this->check(filled($config[$key] ?? null), $env, filled($config[$key] ?? null) ? 'set' : "unset (disks.{$disk}.{$key})");
        }

        try {
            $client = Storage::disk($disk)->getClient();
        } catch (Throwable $e) {
            $this->check(false, 'S3 client', get_class($e).': '.$e->getMessage());

            return;
        }

        $checksum = $client->getConfig('request_checksum_calculation');
        $this->check($checksum === 'when_required', 'Checksum mode', (string) $checksum.($checksum === 'when_required' ? '' : ' — the SDK will send x-amz-checksum-crc32 on every PutObject'));

        $attached = R2Writes::attached($client);
        $this->check($attached, 'ACL-free writes', $attached ? R2Writes::NAME.' middleware on the client' : R2Writes::NAME." missing — set 'no_acl' => true on the disk");
    }

    /** @param  array<string, mixed>  $config */
    private function probe(string $disk, array $config): void
    {
        $bucket = $config['bucket'] ?? '(local)';

        if (! app()->isProduction() && ! $this->option('force')) {
            $this->check(false, 'Probe', "refused: this would write to disk '{$disk}' (bucket {$bucket}) from the '".app()->environment()."' environment — pass --force if that is the intent");

            return;
        }

        $path = 'uploads-doctor/probe-'.Str::lower(Str::random(8)).'.txt';
        $body = 'campusfootbl';

        $this->line("  <fg=gray>probing disk '{$disk}' (bucket {$bucket}) at {$path}</>");

        $store = Storage::disk($disk);

        try {
            $store->put($path, $body);
            $this->check(true, 'Probe: put', '12 bytes written');
        } catch (Throwable $e) {
            $this->check(false, 'Probe: put', get_class($e).': '.$e->getMessage());

            return;
        }

        try {
            $read = $store->get($path);
            $this->check($read === $body, 'Probe: get', $read === $body ? 'read back intact' : 'read back different bytes');
        } catch (Throwable $e) {
            $this->check(false, 'Probe: get', get_class($e).': '.$e->getMessage());
        }

        try {
            $url = $store->url($path);
            $status = Http::timeout(10)->head($url)->status();
            $this->check($status === 200, 'Probe: public URL', "HEAD {$status} — ".parse_url($url, PHP_URL_HOST));
        } catch (Throwable $e) {
            $this->check(false, 'Probe: public URL', get_class($e).': '.$e->getMessage());
        }

        try {
            $store->delete($path);
            $this->check(true, 'Probe: delete', 'cleaned up');
        } catch (Throwable $e) {
            $this->check(false, 'Probe: delete', get_class($e).': '.$e->getMessage()." — remove {$path} by hand");
        }
    }

    private function check(bool $ok, string $label, string $detail): void
    {
        if (! $ok) {
            $this->failing++;
        }

        $this->line(sprintf('  %s %-20s %s', $ok ? '<fg=green>✓</>' : '<fg=red>✗</>', $label, $detail));
    }
}

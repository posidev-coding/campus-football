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

        $usable = false;

        if (($config['driver'] ?? null) !== 's3') {
            $this->line('  <fg=gray>local disk — the R2 checks do not apply</>');
            $usable = $config !== null;
        } else {
            $usable = $this->checkBucketConfig($disk, $config);
        }

        if ($this->option('probe')) {
            /*
             * A probe against a disk that cannot even be CONSTRUCTED throws
             * out of the command — which is how this first ran on Laravel
             * Cloud: three env names unset, so Flysystem refused the adapter
             * and the TypeError buried the report that had just explained
             * why. A doctor may never die of the disease it diagnoses.
             */
            if ($usable) {
                $this->probe($disk, $config ?? []);
            } else {
                $this->check(false, 'Probe', 'not run — the disk above is not configured, so there is nothing to write to');
            }
        }

        $this->newLine();

        if ($this->failing > 0) {
            $this->line("  <fg=red>{$this->failing} failing</>");

            return self::FAILURE;
        }

        $this->line('  <fg=gray>all checks passing</>');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return bool whether the disk is complete enough to talk to a bucket
     */
    private function checkBucketConfig(string $disk, array $config): bool
    {
        /*
         * Names, never values — and CONFIG beside ENVIRONMENT, because the
         * gap between the two is its own diagnosis. `env()` reads the real
         * process environment whether or not the config is cached, so a name
         * the environment has and the config does not means the config was
         * cached before that name existed: a platform that injects bucket
         * credentials at RUN time, cached at BUILD time. That is the shape
         * Laravel Cloud produced on 2026-09-02 — a hand-typed AWS_URL
         * present, every injected bucket name absent — and no amount of
         * re-reading config/filesystems.php explains it.
         */
        $names = ['AWS_URL' => 'url', 'AWS_BUCKET' => 'bucket', 'AWS_ENDPOINT' => 'endpoint', 'AWS_ACCESS_KEY_ID' => 'key'];
        $cachedWithout = false;

        foreach ($names as $env => $key) {
            $inConfig = filled($config[$key] ?? null);
            $inEnv = filled(env($env));

            if ($inConfig) {
                $this->check(true, $env, 'set');

                continue;
            }

            $cachedWithout = $cachedWithout || $inEnv;

            $this->check(false, $env, $inEnv
                ? "the ENVIRONMENT has it, the config does not — disks.{$disk}.{$key} was cached without it"
                : "unset (disks.{$disk}.{$key})");
        }

        if ($cachedWithout) {
            $this->line('  <fg=yellow>→ the config is '.($this->laravel->configurationIsCached() ? 'CACHED' : 'not cached')
                .'. A name injected after `config:cache` never reaches the disk: set it as a plain environment'
                .' variable so it is present when the config is built, then redeploy.</>');
        }

        // Which disks this app actually defines, so a bucket "mounted as
        // disk X" somewhere else is visibly not a disk here.
        $defined = collect(config('filesystems.disks'))
            ->map(fn (array $d, string $name) => $name.' ('.($d['driver'] ?? '?').')')
            ->values()
            ->implode(', ');

        $this->line("  <fg=gray>disks defined here: {$defined}</>");

        if (filled($config['url'] ?? null)) {
            /*
             * A custom public domain is right and expected — the S3 endpoint
             * is authenticated, so AWS_URL has to be the bucket's own public
             * hostname — but it must be BOUND to this bucket, and a domain
             * pointing anywhere else 404s every asset while every upload
             * succeeds. The VERDICT, not the domain: this report promises
             * names and never values, and the probe's HEAD is what settles
             * it anyway.
             */
            $urlHost = (string) parse_url((string) $config['url'], PHP_URL_HOST);
            $endpointHost = (string) parse_url((string) ($config['endpoint'] ?? ''), PHP_URL_HOST);
            $custom = $urlHost !== '' && $endpointHost !== '' && ! str_ends_with($urlHost, $endpointHost);

            $this->line('  <fg=gray>public URL is '.($custom ? 'a custom domain' : "the bucket's own endpoint")
                .' — it must be bound to this bucket, which only --probe\'s HEAD can confirm</>');
        }

        try {
            $client = Storage::disk($disk)->getClient();
        } catch (Throwable $e) {
            $this->check(false, 'S3 client', get_class($e).': '.$e->getMessage());

            return false;
        }

        $checksum = $client->getConfig('request_checksum_calculation');
        $this->check($checksum === 'when_required', 'Checksum mode', (string) $checksum.($checksum === 'when_required' ? '' : ' — the SDK will send x-amz-checksum-crc32 on every PutObject'));

        $attached = R2Writes::attached($client);
        $this->check($attached, 'ACL-free writes', $attached ? R2Writes::NAME.' middleware on the client' : R2Writes::NAME." missing — set 'no_acl' => true on the disk");

        return true;
    }

    /** @param  array<string, mixed>  $config */
    private function probe(string $disk, array $config): void
    {
        $bucket = ($config['driver'] ?? null) === 's3'
            ? ($config['bucket'] ?? 'unset')
            : '(local)';

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

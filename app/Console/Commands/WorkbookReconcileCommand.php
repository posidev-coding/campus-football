<?php

namespace App\Console\Commands;

use App\Actions\CloseMergedWorkbookItem;
use App\Console\Concerns\TracksFeedRun;
use App\Enums\WorkbookStatus;
use App\Models\WorkbookItem;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The net under the merge webhook: close any In review card whose pull request
 * has merged, by asking GitHub rather than waiting to be told.
 *
 * `/ops/github` is the fast path and stays the main one. But GitHub NEVER
 * retries a failed delivery, and every merge to main deploys (twice for a
 * bumped merge while push-to-deploy is on), so a delivery that lands mid-swap
 * gets a 503 and its card sits In review for good. That happened to CFB-121
 * on 2026-11-16, while 21 pull requests merged in 25 minutes.
 *
 * One request per In review card that carries a `pr_url`, and the board rarely
 * holds more than a dozen. The close is {@see CloseMergedWorkbookItem}, the
 * same Action the webhook calls, so the two cannot drift: matched on the STORED
 * branch, actor `github`, never the merging user.
 *
 * Off until `cfb.github_token` is set — the repository is private, so asking
 * needs a token, and without one this says so and exits 0. It never guesses a
 * merge from a branch name, and a card it cannot ask about is left exactly
 * where it is.
 */
class WorkbookReconcileCommand extends Command
{
    use TracksFeedRun;

    /** The feed_runs key — and its `SyncSchedule::ledgerKey()` arm. */
    public const LEDGER = 'workbook:reconcile';

    public const API = 'https://api.github.com';

    public const TIMEOUT_SECONDS = 10;

    public const CONNECT_TIMEOUT_SECONDS = 5;

    protected $signature = 'cfb:workbook:reconcile';

    protected $description = 'Close In review workbook cards whose pull request has merged, for webhooks GitHub never delivered';

    public function handle(CloseMergedWorkbookItem $close): int
    {
        $token = trim((string) config('cfb.github_token'));

        /*
         * Unconfigured is not a failure, and it is not a run either: no row
         * on the ledger, nothing moved. The webhook still closes cards on its
         * own; this is only the net under it.
         */
        if ($token === '') {
            $this->info('Workbook reconcile is not configured (no GITHUB_READ_TOKEN); nothing moved.');

            return self::SUCCESS;
        }

        $repository = self::repository();

        if ($repository === null) {
            $this->error('cfb.repo_host does not name a github.com repository; nothing moved.');

            return self::FAILURE;
        }

        $failed = 0;

        try {
            $closed = $this->trackRun(self::LEDGER, null, function () use ($close, $token, $repository, &$failed): int {
                $closed = 0;

                $items = WorkbookItem::query()
                    ->inColumn(WorkbookStatus::InReview)
                    ->whereNotNull('pr_url')
                    ->whereNotNull('branch')
                    ->get();

                foreach ($items as $item) {
                    $outcome = $this->reconcile($item, $close, $token, $repository);

                    $closed += $outcome === 'closed' ? 1 : 0;
                    $failed += $outcome === 'failed' ? 1 : 0;
                }

                /*
                 * A card GitHub would not answer for stays where it is, and
                 * the run is recorded FAILED so Sync Health shows it — the
                 * other cards were still asked about and still closed.
                 */
                if ($failed > 0) {
                    throw new RuntimeException("GitHub did not answer for {$failed} of {$items->count()} In review ".str('card')->plural($items->count()).'.');
                }

                return $closed;
            });
        } catch (RuntimeException $e) {
            if ($failed === 0) {
                throw $e;
            }

            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Closed {$closed} merged ".str('card')->plural($closed).'.');

        return self::SUCCESS;
    }

    /**
     * Ask about one card, and close it only on GitHub's own say-so.
     *
     * @param  array{owner: string, repo: string}  $repository
     * @return 'closed'|'open'|'skipped'|'failed'
     */
    private function reconcile(WorkbookItem $item, CloseMergedWorkbookItem $close, string $token, array $repository): string
    {
        $number = self::pullNumber((string) $item->pr_url);

        if ($number === null) {
            $this->warn("{$item->reference}: {$item->pr_url} is not a pull request on this repository; left In review.");

            return 'skipped';
        }

        try {
            $response = Http::baseUrl(self::API)
                ->withToken($token)
                ->accept('application/vnd.github+json')
                ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
                ->timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->get("/repos/{$repository['owner']}/{$repository['repo']}/pulls/{$number}");
        } catch (ConnectionException) {
            // The exception text can carry the request; the token is a
            // header, but nothing here needs more than the fact.
            $this->warn("{$item->reference}: GitHub could not be reached; left In review.");

            return 'failed';
        }

        if (! $response->successful()) {
            $this->warn("{$item->reference}: GitHub answered {$response->status()} for #{$number}; left In review.");

            return 'failed';
        }

        // Strictly true: a missing or malformed field is "not merged", never a guess.
        if ($response->json('merged') !== true) {
            return 'open';
        }

        /*
         * The same rule the webhook keeps: the card is matched on the branch
         * GitHub says it merged, against the STORED column. A pull request
         * that merged some other branch is not this card's answer.
         */
        $head = (string) $response->json('head.ref', '');

        if ($head !== $item->branch) {
            $this->warn("{$item->reference}: #{$number} merged from {$head}, not {$item->branch}; left In review.");

            return 'skipped';
        }

        $close->handle($head);

        $this->line("{$item->reference}: #{$number} merged; moved to Done.");

        return 'closed';
    }

    /**
     * The owner and name off `cfb.repo_host` — the same setting the board pins
     * every `pr_url` to, so a URL that passed review validation parses here.
     *
     * @return array{owner: string, repo: string}|null
     */
    private static function repository(): ?array
    {
        $host = trim((string) config('cfb.repo_host'), '/');

        if (! preg_match('#^github\.com/([\w.-]+)/([\w.-]+)$#', $host, $m)) {
            return null;
        }

        return ['owner' => $m[1], 'repo' => $m[2]];
    }

    /** `https://github.com/owner/repo/pull/221` → 221, and anything else → null. */
    private static function pullNumber(string $url): ?int
    {
        $prefix = preg_quote('https://'.trim((string) config('cfb.repo_host'), '/').'/pull/', '#');

        return preg_match('#^'.$prefix.'(\d+)(?:[/?\#].*)?$#', $url, $m) ? (int) $m[1] : null;
    }
}

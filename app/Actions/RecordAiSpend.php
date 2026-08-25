<?php

namespace App\Actions;

use App\Enums\AiModel;
use App\Models\AiSpend;
use Illuminate\Support\Facades\Log;
use Throwable;

use function Illuminate\Support\defer;

/**
 * Write one model call into the spend ledger.
 *
 * The single doorway, so the cost cannot be computed two different ways by two
 * callers — the `GrantWalletEntry` shape. The rate card lives on
 * {@see AiModel}; nothing else may price a call.
 *
 * TWO ENTRY POINTS, and the difference is deliberate rather than clever:
 *
 *   handle()  writes now. What a QUEUED caller wants — there is no response to
 *             hurry, and the next job's budget check should see this one.
 *   later()   writes after the response is sent. What a REQUEST-PATH caller
 *             wants: a person waiting on a stat answer should not also wait on
 *             our bookkeeping.
 *
 * Naming them apart means the choice is visible at the call site instead of
 * hidden in a flag.
 *
 * FED BY WHATEVER MAKES THE CALL. It takes plain numbers rather than an SDK
 * response object, so the `laravel/ai` usage event — when that dependency
 * lands — is a listener that maps `usage` onto these arguments and nothing
 * more. A ledger coupled to one client is a ledger that breaks on the client's
 * next pre-1.0 minor.
 */
class RecordAiSpend
{
    public function handle(
        AiModel $model,
        string $feature,
        int $inputTokens,
        int $outputTokens,
        int $cacheWriteTokens = 0,
        int $cacheReadTokens = 0,
        bool $batch = false,
    ): AiSpend {
        return AiSpend::create([
            'model' => $model,
            'feature' => mb_substr($feature, 0, 40),
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'cache_write_tokens' => $cacheWriteTokens,
            'cache_read_tokens' => $cacheReadTokens,
            'batch' => $batch,
            'cost' => $model->cost($inputTokens, $outputTokens, $cacheWriteTokens, $cacheReadTokens, $batch),
        ]);
    }

    /**
     * The same write, after the response has gone out.
     *
     * Swallows its own failures. The money was already spent the moment the
     * API answered — losing the LEDGER row is bad, losing the user's answer to
     * a bookkeeping error is worse, and by the time a deferred callback runs
     * there is no response left to put an error into anyway.
     */
    public function later(
        AiModel $model,
        string $feature,
        int $inputTokens,
        int $outputTokens,
        int $cacheWriteTokens = 0,
        int $cacheReadTokens = 0,
        bool $batch = false,
    ): void {
        defer(function () use ($model, $feature, $inputTokens, $outputTokens, $cacheWriteTokens, $cacheReadTokens, $batch): void {
            try {
                $this->handle($model, $feature, $inputTokens, $outputTokens, $cacheWriteTokens, $cacheReadTokens, $batch);
            } catch (Throwable $e) {
                Log::warning('Could not record AI spend.', [
                    'feature' => $feature,
                    'model' => $model->value,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }
}

<?php

namespace App\Jobs;

use App\Models\SmsLog;
use App\Services\Sms\SemaphoreClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Delivers one queued SMS through Semaphore and records the outcome on the
 * sms_logs row. Retries with backoff on transport errors; marks the log
 * "failed" when retries are exhausted.
 */
class SendSmsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $smsLogId)
    {
        $this->afterCommit();
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(SemaphoreClient $client): void
    {
        $log = SmsLog::find($this->smsLogId);
        if (! $log || ! in_array($log->status, [SmsLog::STATUS_QUEUED, SmsLog::STATUS_PENDING], true)) {
            return;
        }

        if (! $client->isConfigured()) {
            $log->forceFill([
                'status' => SmsLog::STATUS_SKIPPED,
                'error_message' => 'SEMAPHORE_API_KEY is not configured — message recorded but not sent.',
            ])->save();

            return;
        }

        $result = $client->send($log->recipient, $log->message, $log->sender_name);

        $log->forceFill([
            'provider_message_id' => $result['message_id'],
            'status' => match ($result['status']) {
                'sent' => SmsLog::STATUS_SENT,
                'failed', 'refunded' => SmsLog::STATUS_FAILED,
                default => SmsLog::STATUS_PENDING,
            },
            'credits_used' => $log->segments,
            'sent_at' => $result['status'] === 'sent' ? now() : null,
            'error_message' => null,
        ])->save();
    }

    public function failed(Throwable $e): void
    {
        SmsLog::whereKey($this->smsLogId)->update([
            'status' => SmsLog::STATUS_FAILED,
            'error_message' => mb_substr($e->getMessage(), 0, 500),
            'updated_at' => now(),
        ]);
    }
}

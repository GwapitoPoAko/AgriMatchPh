<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class VerifyAuditChain extends Command
{
    protected $signature = 'agrimatch:audit-verify {--from= : Start verifying at this audit log id}';

    protected $description = 'Verify the SHA-256 hash chain of the audit trail (detects edited or deleted rows)';

    public function handle(AuditLogger $logger): int
    {
        $broken = $logger->verifyChain($this->option('from') ? (int) $this->option('from') : null);

        if ($broken) {
            $this->error("Audit chain BROKEN at entry #{$broken['id']}: {$broken['reason']}");
            Log::critical('Audit chain integrity failure', $broken);

            return self::FAILURE;
        }

        $this->info('Audit chain intact — ' . AuditLog::count() . ' entries verified.');

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Support\Audit\AuditChainVerifier;
use Illuminate\Console\Command;

class AuditVerify extends Command
{
    protected $signature = 'audit:verify';

    protected $description = 'Verifica a integridade da cadeia de hash de audit_logs (exit ≠ 0 e primeiro id divergente)';

    public function handle(AuditChainVerifier $verifier): int
    {
        $result = $verifier->verify();

        if ($result['ok']) {
            $this->info("Cadeia íntegra: {$result['checked']} registro(s) verificados.");

            return self::SUCCESS;
        }

        $this->error("Cadeia ADULTERADA: primeiro registro divergente id={$result['first_bad_id']} — {$result['reason']}");

        return self::FAILURE;
    }
}

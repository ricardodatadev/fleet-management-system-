<?php

namespace App\Support\Audit;

use Illuminate\Support\Facades\DB;

class AuditChainVerifier
{
    /**
     * Percorre a cadeia por id (keyset via chunkById — sem OFFSET, linear no tamanho da tabela). Retorna o primeiro registro divergente (se houver).
     *
     * @return array{ok: bool, checked: int, first_bad_id: ?int, reason: ?string}
     */
    public function verify(?string $connection = null): array
    {
        $expectedPrev = AuditHasher::GENESIS;
        $checked = 0;
        $badId = null;
        $reason = null;

        DB::connection($connection)->table('audit_logs')->chunkById(500, function ($rows) use (&$expectedPrev, &$checked, &$badId, &$reason) {
            foreach ($rows as $row) {
                $checked++;
                if ($row->prev_hash !== $expectedPrev) {
                    [$badId, $reason] = [(int) $row->id, 'prev_hash não corresponde ao hash do registro anterior (registro removido/reordenado?)'];

                    return false;
                }
                $recomputed = AuditHasher::hash($row->prev_hash, AuditHasher::fieldsFromRow($row));
                if ($row->hash !== $recomputed) {
                    [$badId, $reason] = [(int) $row->id, 'hash não confere com o conteúdo do registro (adulterado)'];

                    return false;
                }
                $expectedPrev = $row->hash;
            }

            return true;
        }, 'id');

        return ['ok' => $badId === null, 'checked' => $checked, 'first_bad_id' => $badId, 'reason' => $reason];
    }
}

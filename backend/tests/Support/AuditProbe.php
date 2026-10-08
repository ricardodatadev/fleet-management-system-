<?php

namespace Tests\Support;

use App\Support\Audit\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Model de teste auditável (tabela audit_probes criada pelos testes). */
class AuditProbe extends Model
{
    use Auditable;
    use SoftDeletes;

    protected $table = 'audit_probes';

    protected $guarded = [];
}

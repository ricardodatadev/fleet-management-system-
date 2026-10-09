<?php

namespace Tests\Support;

use App\Models\Concerns\BranchScoped;
use Illuminate\Database\Eloquent\Model;

/** Model de teste com escopo de filial (tabela scope_probes criada pelos testes). */
class ScopeProbe extends Model
{
    use BranchScoped;

    protected $table = 'scope_probes';

    protected $guarded = [];
}

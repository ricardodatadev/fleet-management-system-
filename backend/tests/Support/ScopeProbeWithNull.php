<?php

namespace Tests\Support;

/** Mesmo model, mas o não-admin enxerga também os registros sem filial (padrão do CostCenter). */
class ScopeProbeWithNull extends ScopeProbe
{
    protected bool $branchScopeIncludesNull = true;
}

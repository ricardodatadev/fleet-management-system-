<?php

namespace Tests\Support;

use App\Policies\ResourcePolicy;

class ScopeProbePolicy extends ResourcePolicy
{
    protected function resource(): string
    {
        return 'equipments';
    }
}

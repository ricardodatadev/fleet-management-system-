<?php

namespace Tests\Support;

/** Variante com $auditEvents/$auditExclude customizados. */
class AuditProbeLimited extends AuditProbe
{
    public array $auditEvents = ['created'];

    public array $auditExclude = ['notes'];
}

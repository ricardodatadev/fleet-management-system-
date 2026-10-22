<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Perfil Gestor/PCM (Fase 2A, D18): `manager` entra no CHECK de role e, como o admin, pode ficar sem
     * filial (nulo = todas as filiais).
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE users
                DROP CONSTRAINT users_role_check,
                ADD CONSTRAINT users_role_check CHECK (role IN ('operator', 'mechanic', 'leader', 'manager', 'admin')),
                DROP CONSTRAINT users_branch_required_check,
                ADD CONSTRAINT users_branch_required_check CHECK (role IN ('admin', 'manager') OR branch_id IS NOT NULL)
        SQL);
    }

    public function down(): void
    {
        // Volta à Fase 1; falha se já houver usuário manager.
        DB::unprepared(<<<'SQL'
            ALTER TABLE users
                DROP CONSTRAINT users_role_check,
                ADD CONSTRAINT users_role_check CHECK (role IN ('operator', 'mechanic', 'leader', 'admin')),
                DROP CONSTRAINT users_branch_required_check,
                ADD CONSTRAINT users_branch_required_check CHECK (role = 'admin' OR branch_id IS NOT NULL)
        SQL);
    }
};

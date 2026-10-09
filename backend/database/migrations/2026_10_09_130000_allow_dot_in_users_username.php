<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Username v1.9: minúsculas sem acento, números e ponto (não no início, no fim nem repetido), 3 a 30
     * caracteres. Mesmo formato de App\Support\Users\UsernameGenerator::REGEX (que usa lookahead para o
     * tamanho), escrito aqui como padrão + length(), o equivalente definido na spec.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE users
                DROP CONSTRAINT users_username_format_check,
                ADD CONSTRAINT users_username_format_check
                    CHECK (username ~ '^[a-z0-9]+(\.[a-z0-9]+)*$' AND length(username) BETWEEN 3 AND 30)
        SQL);
    }

    public function down(): void
    {
        // Volta ao formato da v1.7 (só [a-z0-9]); falha se já houver username com ponto.
        DB::unprepared(<<<'SQL'
            ALTER TABLE users
                DROP CONSTRAINT users_username_format_check,
                ADD CONSTRAINT users_username_format_check CHECK (username ~ '^[a-z0-9]{3,30}$')
        SQL);
    }
};

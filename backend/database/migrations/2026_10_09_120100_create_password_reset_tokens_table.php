<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Broker de senhas do Laravel (D.2 v1.7): um token (hash) por e-mail; um pedido novo substitui o anterior.
        DB::unprepared(<<<'SQL'
            CREATE TABLE password_reset_tokens (
                email varchar(190) PRIMARY KEY,
                token varchar(255) NOT NULL,
                created_at timestamptz NULL
            )
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS password_reset_tokens');
    }
};

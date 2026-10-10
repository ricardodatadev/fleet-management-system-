<?php

use App\Support\Users\UsernameGenerator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('ALTER TABLE users ADD COLUMN username varchar(30) NULL');

        // Backfill (D.2 v1.7): a partir da parte local do e-mail, sem colisão entre TODOS os usuários
        // (inclusive excluídos, para o restore não esbarrar no índice). Ordem por id = determinístico.
        $taken = [];
        foreach (DB::table('users')->orderBy('id')->get(['id', 'email']) as $user) {
            $username = UsernameGenerator::fromEmail($user->email, fn (string $candidate) => isset($taken[$candidate]));
            $taken[$username] = true;
            DB::table('users')->where('id', $user->id)->update(['username' => $username]);
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE users
                ALTER COLUMN username SET NOT NULL,
                ADD CONSTRAINT users_username_format_check CHECK (username ~ '^[a-z0-9]{3,30}$')
        SQL);
        DB::unprepared('CREATE UNIQUE INDEX users_username_unique ON users (username) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        DB::unprepared('DROP INDEX IF EXISTS users_username_unique');
        DB::unprepared('ALTER TABLE users DROP COLUMN IF EXISTS username');
    }
};

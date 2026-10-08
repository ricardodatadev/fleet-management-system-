<?php

namespace App\Console\Commands;

use App\Support\Api\OpenApi\OpenApiBuilder;
use Illuminate\Console\Command;

class GenerateOpenApi extends Command
{
    protected $signature = 'openapi:generate';

    protected $description = 'Gera docs/api/openapi.json (OpenAPI 3.0) a partir dos atributos PHP';

    public function handle(OpenApiBuilder $builder): int
    {
        $path = OpenApiBuilder::versionedPath();
        $dir = dirname($path);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            $this->error("Não foi possível criar {$dir}");

            return self::FAILURE;
        }

        file_put_contents($path, $builder->build());
        $this->info("OpenAPI gerada em {$path}");

        return self::SUCCESS;
    }
}

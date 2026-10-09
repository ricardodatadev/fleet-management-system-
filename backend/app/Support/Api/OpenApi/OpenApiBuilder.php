<?php

namespace App\Support\Api\OpenApi;

use L5Swagger\GeneratorFactory;
use RuntimeException;

/**
 * Gera a especificação via l5-swagger/swagger-php, injeta a marca do config em `info` e a
 * normaliza (JSON estável p/ diff).
 */
class OpenApiBuilder
{
    /** Marcadores usados em Spec (OA\Info) → chave do config com o valor. */
    public const BRAND_TOKENS = [
        '{app_name}' => 'app.name',
        '{app_full_name}' => 'app.full_name',
    ];

    public function __construct(private readonly GeneratorFactory $factory) {}

    /** Caminho do JSON versionado (docs/api/openapi.json do repositório). */
    public static function versionedPath(): string
    {
        return config('openapi.output', base_path('../docs/api/openapi.json'));
    }

    public function build(): string
    {
        $dir = sys_get_temp_dir().'/'.config('app.slug').'-openapi-'.bin2hex(random_bytes(6));
        $previous = config('l5-swagger.defaults.paths.docs');
        config(['l5-swagger.defaults.paths.docs' => $dir]);

        try {
            $this->factory->make('default')->generateDocs();
            $raw = file_get_contents($dir.'/'.config('l5-swagger.documentations.default.paths.docs_json'));
        } finally {
            config(['l5-swagger.defaults.paths.docs' => $previous]);
            array_map('unlink', glob($dir.'/*') ?: []);
            @rmdir($dir);
        }

        if ($raw === false) {
            throw new RuntimeException('Falha ao gerar a OpenAPI.');
        }

        return self::normalize(self::applyBrand($raw));
    }

    /** Preenche os marcadores de marca em `info.title` e `info.description`. */
    public static function applyBrand(string $json): string
    {
        $spec = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $values = array_map(fn (string $key): string => (string) config($key), self::BRAND_TOKENS);

        foreach (['title', 'description'] as $field) {
            if (isset($spec['info'][$field]) && is_string($spec['info'][$field])) {
                $spec['info'][$field] = strtr($spec['info'][$field], $values);
            }
        }

        return json_encode($spec, JSON_THROW_ON_ERROR);
    }

    public static function normalize(string $json): string
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    }
}

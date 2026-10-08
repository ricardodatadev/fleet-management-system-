<?php

return [
    // Destino do JSON versionado. No container: /var/www/docs/api/openapi.json (= docs/ do repositório).
    'output' => env('OPENAPI_OUTPUT', base_path('../docs/api/openapi.json')),
];

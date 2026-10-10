<?php

/*
 * Dados dos seeders (F1-17), lidos do ambiente. Nenhuma senha fica no código: sem as variáveis, o
 * AdminSeeder/DemoSeeder falham com mensagem clara. Ver .env.example e o README.
 */
return [
    'admin' => [
        'username' => env('SEED_ADMIN_USERNAME'),
        'email' => env('SEED_ADMIN_EMAIL'),
        'password' => env('SEED_ADMIN_PASSWORD'),
        'name' => env('SEED_ADMIN_NAME') ?: 'Administrador',
    ],
    'demo_password' => env('SEED_DEMO_PASSWORD'),
];

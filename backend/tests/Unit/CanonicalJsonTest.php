<?php

use App\Support\Audit\AuditHasher;
use App\Support\Audit\CanonicalJson;

it('ordena chaves em todos os níveis e é independente da ordem de entrada', function () {
    $a = ['b' => 1, 'a' => ['z' => null, 'y' => ['k2' => 2, 'k1' => 1]]];
    $b = ['a' => ['y' => ['k1' => 1, 'k2' => 2], 'z' => null], 'b' => 1];

    expect(CanonicalJson::encode($a))->toBe('{"a":{"y":{"k1":1,"k2":2},"z":null},"b":1}');
    expect(CanonicalJson::encode($b))->toBe(CanonicalJson::encode($a));
});

it('mantém null explícito, a ordem das listas e não escapa "/" nem unicode', function () {
    expect(CanonicalJson::encode(['x' => null, 'l' => [3, 1, 2], 's' => 'ação/é']))
        ->toBe('{"l":[3,1,2],"s":"ação/é","x":null}');
});

it('formata floats sem notação científica', function () {
    expect(CanonicalJson::encode(['a' => 1.0E+25, 'b' => 0.00001, 'c' => 1.5, 'd' => -2.5E-7, 'e' => 10]))
        ->toBe('{"a":10000000000000000000000000.0,"b":0.00001,"c":1.5,"d":-0.00000025,"e":10}');
});

it('trata [] raiz de campos-objeto como {}', function () {
    expect(CanonicalJson::encode([], true))->toBe('{}')->and(CanonicalJson::encode([]))->toBe('[]');
});

it('formata event_at em ISO-8601 UTC com microssegundos e sufixo Z', function () {
    expect(AuditHasher::eventAt('2026-10-08 14:03:22.123456+00'))->toBe('2026-10-08T14:03:22.123456Z');
    expect(AuditHasher::eventAt('2026-10-08 14:03:22.12+00'))->toBe('2026-10-08T14:03:22.120000Z');
    expect(AuditHasher::eventAt('2026-10-08 11:03:22-03'))->toBe('2026-10-08T14:03:22.000000Z');
});

it('canonical do registro: 15 campos, nulls explícitos, exclui id/prev_hash/hash e é determinístico', function () {
    $fields = [
        'request_id' => null, 'action' => 'created', 'uuid' => '0b0f6d3a-2f1b-4e0e-9a55-1d5f3b2c7a10',
        'event_at' => '2026-10-08T14:03:22.123456Z', 'source' => 'console',
        'new_values' => ['name' => 'X', 'a' => 1], 'metadata' => [], 'id' => 99, 'hash' => 'ignorado', 'prev_hash' => 'ignorado',
    ];
    $expected = '{"action":"created","actor_id":null,"actor_name":null,"actor_role":null,"auditable_id":null,"auditable_type":null,'
        .'"event_at":"2026-10-08T14:03:22.123456Z","ip":null,"metadata":{},"new_values":{"a":1,"name":"X"},"old_values":null,'
        .'"request_id":null,"source":"console","user_agent":null,"uuid":"0b0f6d3a-2f1b-4e0e-9a55-1d5f3b2c7a10"}';

    expect(AuditHasher::canonical($fields))->toBe($expected);
    expect(AuditHasher::canonical(array_reverse($fields, true)))->toBe($expected);
    expect(AuditHasher::hash(AuditHasher::GENESIS, $fields))->toBe(hash('sha256', AuditHasher::GENESIS.$expected));
});

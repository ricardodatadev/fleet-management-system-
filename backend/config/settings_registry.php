<?php

/*
 * Registry dos parâmetros (spec F.2): fonte de verdade de tipo, valores permitidos, default e escopos.
 * Sem motor de regra na Fase 1: nenhuma outra parte do código consome estas chaves (só a API e a tela).
 * A margem de antecedência e as tolerâncias da RN-001 ficam nas colunas da família (C.4), não aqui.
 *
 * type: 'enum' (value ∈ values) ou 'bool'. scopes: escopos em que a chave pode ter override.
 */
return [
    'preventive.dispatch_mode' => [
        'rule' => 'RN-001',
        'label' => 'Modo de disparo da preventiva',
        'description' => 'Como a preventiva vencida vira ordem de serviço: gerada automaticamente ou sugerida ao gestor.',
        'type' => 'enum',
        'values' => [
            'automatic' => 'Automático (gera a OS)',
            'suggestion' => 'Sugestão (o gestor aprova)',
        ],
        'default' => 'suggestion',
        'scopes' => ['global', 'family'],
        'placeholder' => false,
    ],
    'workorder.block_close_without_labor' => [
        'rule' => 'RN-002',
        'label' => 'Bloquear o fechamento de OS sem apontamento de mão de obra',
        'description' => 'Provisório (D14): será substituído pela matriz por Categoria de Serviço na fase de OS.',
        'type' => 'bool',
        'values' => null,
        'default' => false,
        'scopes' => ['global', 'branch'],
        'placeholder' => true,
    ],
    'stock.allow_issue_with_fiscal_pending' => [
        'rule' => 'RN-003',
        'label' => 'Permitir saída de estoque com pendência fiscal',
        'description' => 'Se a peça pode sair do almoxarifado antes de a nota fiscal ser regularizada.',
        'type' => 'bool',
        'values' => null,
        'default' => true,
        'scopes' => ['global', 'branch'],
        'placeholder' => false,
    ],
    'warranty.alert_mode' => [
        'rule' => 'RN-004',
        'label' => 'Tratamento de peça ou serviço em garantia',
        'description' => 'Ao usar peça ou serviço ainda em garantia: apenas alertar ou bloquear a operação.',
        'type' => 'enum',
        'values' => [
            'warning' => 'Apenas alertar',
            'hard_block' => 'Bloquear',
        ],
        'default' => 'warning',
        'scopes' => ['global', 'branch', 'family'],
        'placeholder' => false,
    ],
];

<?php

/*
 * Matriz perfil × permissão (spec E, RN-007 recortada à Fase 1). Fonte única para Gates/Policies (F1-10)
 * e para `permissions` de GET /auth/me.
 *
 * - Lista explícita por perfil, sem curingas, na ordem da tabela E.
 * - auth.* (login/me/logout/password) é implícito a qualquer autenticado e não entra na lista.
 * - restore e with_trashed estão contidos em *.manage.
 * - O escopo de filial é regra de policy (F1-10), não permissão.
 */
return [
    'roles' => [
        'operator' => [
            'branches.view',
            'equipments.view',
        ],
        'mechanic' => [
            'branches.view',
            'equipment_families.view',
            'equipments.view',
        ],
        'leader' => [
            'branches.view',
            'cost_centers.view',
            'equipment_families.view',
            'equipments.view',
            'employees.view',
            'settings.view',
        ],
        'admin' => [
            'branches.view',
            'branches.manage',
            'cost_centers.view',
            'cost_centers.manage',
            'equipment_families.view',
            'equipment_families.manage',
            'equipments.view',
            'equipments.manage',
            'employees.view',
            'employees.manage',
            'users.view',
            'users.manage',
            'settings.view',
            'settings.manage',
            'audit.view',
        ],
    ],
];

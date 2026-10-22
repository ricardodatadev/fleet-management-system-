<?php

namespace App\Models\Contracts;

/**
 * Cadastro global por natureza (Fase 2A, E.1): vale para todas as filiais, então só quem não tem escopo
 * de filial (admin, gestor sem filial) escreve nele. Ex.: famílias de equipamento e categorias de serviço.
 * Os cadastros com escopo de filial são globais só quando o `branch_id` é nulo (centros de custo).
 */
interface GlobalCatalog {}

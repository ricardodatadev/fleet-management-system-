<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

class MetaController extends Controller
{
    /**
     * Esqueleto: os enums (roles, statuses, categories...) chegam com os cadastros.
     * TODO(F1-10): proteger com auth:sanctum (spec D.2: perfil "auth"). Enquanto não existe
     * autenticação a rota é pública mas não expõe nada; o teste de varredura de rotas da
     * F1-10 falha se ela continuar sem permissão declarada.
     */
    public function enums(): JsonResponse
    {
        return ApiResponse::success(['enums' => (object) []]);
    }
}

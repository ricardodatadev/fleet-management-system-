<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Base dos Resources da API: sem wrapper "data" (o envelope é montado por ApiResponse).
 */
class ApiResource extends JsonResource
{
    public static $wrap = null;
}

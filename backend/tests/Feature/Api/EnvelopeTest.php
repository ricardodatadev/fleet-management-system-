<?php

use App\Exceptions\DomainConflictException;
use App\Http\Requests\ApiFormRequest;
use App\Support\Api\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Route;

class StoreProbeRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string'], 'qty' => ['required', 'integer', 'min:1']];
    }
}

beforeEach(function () {
    config(['app.debug' => false]);

    Route::get('api/v1/_t/unauthenticated', fn () => 'x')->middleware('auth');
    Route::get('api/v1/_t/forbidden', fn () => throw new AuthorizationException);
    Route::get('api/v1/_t/abort403', fn () => abort(403));
    Route::get('api/v1/_t/model-missing', fn () => throw (new ModelNotFoundException)->setModel('X', [1]));
    Route::get('api/v1/_t/conflict', fn () => throw new DomainConflictException('Filial possui equipamentos ativos.'));
    Route::post('api/v1/_t/validate', fn (StoreProbeRequest $r) => ApiResponse::success($r->validated()));
    Route::get('api/v1/_t/throttled', fn () => 'ok')->middleware('throttle:1,1');
    Route::get('api/v1/_t/boom', fn () => throw new RuntimeException('SQLSTATE[42P01]: select * from secret_table at /var/www/html/app/X.php'));
    Route::get('api/v1/_t/list', fn () => ApiResponse::paginated(new LengthAwarePaginator([['id' => 1], ['id' => 2]], 7, 2, 1)));
});

function assertEnvelope($response, string $status, int $code, bool $list = false): array
{
    $response->assertStatus($code);
    $body = $response->json();
    expect($body)->toHaveKeys(['status', 'message', 'errors', 'data']);
    expect($body['status'])->toBe($status);
    expect($body['message'])->toBeString()->not->toBe('');
    expect(array_key_exists('meta', $body))->toBe($list);
    if ($list) {
        expect($body['meta'])->toHaveKeys(['current_page', 'per_page', 'total', 'last_page']);
    }
    expect($response->headers->get('Content-Type'))->toContain('application/json');

    return $body;
}

it('401 não autenticado', function () {
    $body = assertEnvelope($this->getJson('/api/v1/_t/unauthenticated'), 'error', 401);
    expect($body['message'])->toBe('Não autenticado.');
});

it('403 via AuthorizationException e abort(403)', function () {
    assertEnvelope($this->getJson('/api/v1/_t/forbidden'), 'error', 403);
    assertEnvelope($this->getJson('/api/v1/_t/abort403'), 'error', 403);
});

it('404 rota inexistente e model não encontrado', function () {
    $body = assertEnvelope($this->getJson('/api/v1/xyz'), 'error', 404);
    expect($body['message'])->toBe('Recurso não encontrado.');
    assertEnvelope($this->getJson('/api/v1/_t/model-missing'), 'error', 404);
});

it('405 método não permitido', function () {
    assertEnvelope($this->postJson('/api/v1/health'), 'error', 405);
});

it('409 conflito de domínio', function () {
    $body = assertEnvelope($this->getJson('/api/v1/_t/conflict'), 'error', 409);
    expect($body['message'])->toBe('Filial possui equipamentos ativos.');
});

it('422 validação com errors por campo em pt-BR', function () {
    $body = assertEnvelope($this->postJson('/api/v1/_t/validate', ['qty' => 0]), 'error', 422);
    expect($body['errors'])->toHaveKeys(['name', 'qty']);
    expect($body['errors']['name'][0])->toBe('O campo name é obrigatório.');
    expect($body['data'])->toBeNull();
});

it('429 throttle com Retry-After', function () {
    $this->getJson('/api/v1/_t/throttled')->assertOk();
    $response = $this->getJson('/api/v1/_t/throttled');
    assertEnvelope($response, 'error', 429);
    expect($response->headers->has('Retry-After'))->toBeTrue();
});

it('500 sem vazar trace/SQL/paths com APP_DEBUG=false', function () {
    $response = $this->getJson('/api/v1/_t/boom');
    $body = assertEnvelope($response, 'error', 500);
    expect($body['message'])->toBe('Erro interno do servidor.');
    $raw = $response->getContent();
    foreach (['trace', 'SQLSTATE', 'secret_table', '/var/www', '.php', 'RuntimeException', 'debug'] as $leak) {
        expect($raw)->not->toContain($leak);
    }
});

it('500 com APP_DEBUG=true inclui bloco debug', function () {
    config(['app.debug' => true]);
    $body = $this->getJson('/api/v1/_t/boom')->assertStatus(500)->json();
    expect($body['debug']['exception'])->toBe(RuntimeException::class);
});

it('lista paginada traz meta', function () {
    $body = assertEnvelope($this->getJson('/api/v1/_t/list'), 'success', 200, true);
    expect($body['meta'])->toMatchArray(['current_page' => 1, 'per_page' => 2, 'total' => 7, 'last_page' => 4]);
    expect($body['data'])->toHaveCount(2);
});

it('Accept: text/html em /api/* ainda devolve JSON', function () {
    $response = $this->get('/api/v1/xyz', ['Accept' => 'text/html']);
    assertEnvelope($response, 'error', 404);
    $response = $this->post('/api/v1/_t/validate', [], ['Accept' => 'text/html']);
    assertEnvelope($response, 'error', 422);
    assertEnvelope($this->get('/api/v1/_t/boom', ['Accept' => 'text/html']), 'error', 500);
});

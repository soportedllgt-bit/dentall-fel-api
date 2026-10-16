<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\AppConfig;
use App\Core\Request;
use App\Core\Response;
use App\Core\Uuid;
use App\Services\ItemService;
use JsonException;

/**
 * Controlador del recurso `Item`.
 *
 * Responsabilidades:
 * - Extraer el cuerpo JSON de `php://input` y su `Content-Type`.
 * - Validar el formato del UUID de la ruta antes de operar.
 * - Traducir el sobre de resultado del servicio a una respuesta HTTP.
 *
 * Todas las rutas trabajan con el UUID público. El identificador interno nunca
 * se lee ni se devuelve.
 *
 * @package App\Controllers
 *
 * @author  fel-dentall
 * @license MIT
 */
final class ItemController
{
    /**
     * Servicio de negocio.
     *
     * @var ItemService
     */
    private $service;

    /**
     * Petición en curso.
     *
     * @var Request
     */
    private $request;

    /**
     * Parámetros de paginación configurados para el entorno activo.
     *
     * @var array{default_limit: int, max_limit: int}
     */
    private $pagination;

    /**
     * @param ItemService $service Servicio de negocio del recurso.
     * @param Request     $request Petición en curso.
     * @param AppConfig   $config  Configuración de la aplicación.
     */
    public function __construct(ItemService $service, Request $request, AppConfig $config)
    {
        $this->service    = $service;
        $this->request    = $request;
        $this->pagination = $config->getItemsConfig();
    }

    /**
     * `GET /api/items` — lista los items con paginación opcional.
     *
     * Parámetros de query admitidos:
     * - `limit`  : número de resultados (1 a 50 por defecto).
     * - `offset` : número de resultados a omitir (0 por defecto).
     *
     * @return void
     */
    public function index(): void
    {
        $pagination = $this->resolvePagination();

        $result = $this->service->listItems($pagination['limit'], $pagination['offset']);

        Response::success($result, 'Listado de items obtenido correctamente.', 200);
    }

    /**
     * `GET /api/items/{uuid}` — devuelve un item por su UUID público.
     *
     * @param string $uuid Identificador público UUID v4 de la ruta.
     *
     * @return void
     */
    public function show(string $uuid): void
    {
        $item = $this->service->getItem($uuid);

        if ($item === null) {
            Response::error('No existe un item con el UUID indicado.', 404);

            return;
        }

        Response::success($item, 'Item encontrado correctamente.', 200);
    }

    /**
     * `POST /api/items` — crea un item.
     *
     * @return void
     */
    public function create(): void
    {
        $payload = $this->readJsonBody();

        if ($payload === null) {
            return;
        }

        $result = $this->service->createItem($payload);

        if (!$result['ok']) {
            $this->respondValidationFailure($result);
            return;
        }

        Response::success($result['item'], 'Item creado correctamente.', 201);
    }

    /**
     * `PUT /api/items/{uuid}` — actualiza parcialmente un item existente.
     *
     * @param string $uuid Identificador público UUID v4 de la ruta.
     *
     * @return void
     */
    public function update(string $uuid): void
    {
        $payload = $this->readJsonBody();

        if ($payload === null) {
            return;
        }

        $result = $this->service->updateItem($uuid, $payload);

        if (!$result['ok']) {
            $this->respondValidationFailure($result);
            return;
        }

        if ($result['item'] === null) {
            Response::error('No existe un item con el UUID indicado.', 404);

            return;
        }

        Response::success($result['item'], 'Item actualizado correctamente.', 200);
    }

    /**
     * `DELETE /api/items/{uuid}` — elimina un item existente.
     *
     * @param string $uuid Identificador público UUID v4 de la ruta.
     *
     * @return void
     */
    public function delete(string $uuid): void
    {
        $deleted = $this->service->deleteItem($uuid);

        if (!$deleted) {
            Response::error('No existe un item con el UUID indicado.', 404);

            return;
        }

        Response::success(
            ['uuid' => Uuid::normalize($uuid)],
            'Item eliminado correctamente.',
            200
        );
    }

    /**
     * Lee y valida el cuerpo JSON de la petición.
 *
     * @return array<string, mixed>|null Objeto decodificado o `null` si ya se
     *                                   respondió con un error.
     */
    private function readJsonBody(): ?array
    {
        if (!$this->request->isJsonContentType()) {
            Response::error('El encabezado Content-Type debe ser "application/json".', 400);

            return null;
        }

        try {
            return $this->request->json();
        } catch (JsonException $exception) {
            // El mensaje ya viene redactado en español por `Request::json()`.
            Response::error($exception->getMessage(), 400);

            return null;
        }
    }

    /**
     * Traduce un fallo de validación a una respuesta `400`.
     *
     * @param array{ok: bool, item: array<string, mixed>|null, errors: array<string, array<int, string>>, message: string} $result
     *        Sobre devuelto por el servicio.
     *
     * @return void
     */
    private function respondValidationFailure(array $result): void
    {
        $message = $result['message'] !== ''
            ? $result['message']
            : 'Los datos enviados no son válidos.';

        Response::error($message, 400, $result['errors'] === [] ? null : $result['errors']);
    }

    /**
     * Normaliza los parámetros de paginación de la query string.
     *
     * @return array{limit: int, offset: int} Límite y desplazamiento acotados.
     */
    private function resolvePagination(): array
    {
        $limit  = (int) $this->request->query('limit', $this->pagination['default_limit']);
        $offset = (int) $this->request->query('offset', 0);

        // El límite se acota siempre al máximo configurado para el entorno y el
        // desplazamiento nunca puede ser negativo.
        $limit  = max(1, min($limit, $this->pagination['max_limit']));
        $offset = max(0, $offset);

        return ['limit' => $limit, 'offset' => $offset];
    }
}
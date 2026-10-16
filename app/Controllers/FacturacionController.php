<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\AnulacionService;
use App\Services\FacturacionService;
use App\Services\ReintentoService;
use JsonException;

/**
 * Controlador de la emisión de facturas.
 *
 * ## Códigos de respuesta
 *
 * | Código | Significado                                                    |
 * |--------|-----------------------------------------------------------------|
 * | `201`  | Factura autorizada por SAT.                                      |
 * | `200`  | Reintento que resolvió la factura.                               |
 * | `400`  | El JSON no cumple el contrato.                                   |
 * | `404`  | No existe una orden con ese UUID.                                |
 * | `409`  | La orden ya está resuelta, en curso, o agotó los intentos.       |
 * | `422`  | El proveedor rechazó el documento: los datos están mal.         |
 * | `429`  | Todavía no corresponde reintentar.                               |
 * | `503`  | No hubo respuesta concluyente del proveedor. Reintentable.      |
 *
 * ## El código HTTP no basta para decidir
 *
 * `422` y `503` se distinguen por lo que significan, no por su número:
 *
 * - `422` significa que el proveedor respondió y rechazó el documento. Los
 *   datos necesitan corrección y reintentar no va a ayudar.
 * - `503` significa que no se sabe: el servicio no respondió, la respuesta llegó
 *   vacía, o el proveedor devolvió el error 82, que también aparece al superar
 *   el límite de emisiones. El documento puede ser válido y reenviarlo es seguro.
 *
 * Confundirlos hacía que una caída del proveedor se informara al vendedor como un
 * rechazo de su factura. Por eso `503` significa «no concluyente» y nunca
 * «sus datos están mal».
 *
 * ## Todo fallo registrado lleva su contexto
 *
 * Cuando la orden llegó a persistirse, la respuesta incluye un bloque
 * `contexto` con `uuid`, `estado` y `reintentable`. Es lo que permite al POS
 * localizar la operación y reintentarla más tarde (CA3.3).
 *
 * @package App\Controllers
 *
 * @author  fel-dentall
 * @license MIT
 */
final class FacturacionController
{
    /**
     * Servicio de emisión.
     *
     * @var FacturacionService
     */
    private $service;

    /**
     * Servicio de reintento.
     *
     * @var ReintentoService
     */
    private $retryService;

    /**
     * Servicio de anulación.
     *
     * @var AnulacionService
     */
    private $annulmentService;

    /**
     * Petición en curso.
     *
     * @var Request
     */
    private $request;

    /**
     * @param FacturacionService $service          Servicio de emisión.
     * @param ReintentoService   $retryService     Servicio de reintento.
     * @param AnulacionService   $annulmentService Servicio de anulación.
     * @param Request            $request          Petición en curso.
     */
    public function __construct(
        FacturacionService $service,
        ReintentoService $retryService,
        AnulacionService $annulmentService,
        Request $request
    ) {
        $this->service          = $service;
        $this->retryService     = $retryService;
        $this->annulmentService = $annulmentService;
        $this->request          = $request;
    }

    /**
     * `POST /api/facturacion/ordenes` — recibe una orden y emite la factura.
     *
     * @return void
     */
    public function store(): void
    {
        $payload = $this->readJsonBody();

        if ($payload === null) {
            return;
        }

        $result = $this->service->facturar($payload);

        if (!$result['ok']) {
            Response::error(
                $result['message'],
                $result['status'],
                $result['errors'] === [] ? null : $result['errors'],
                $result['context']
            );

            return;
        }

        Response::success(
            [
                'orden'   => $result['order'],
                'factura' => $result['factura'],
            ],
            $result['message'],
            $result['status']
        );
    }

    /**
     * `POST /api/facturacion/ordenes/{uuid}/reintento` — reenvía el documento
     * almacenado de una orden con desenlace incierto.
     *
     * Devuelve `429` con la cabecera `Retry-After` cuando la espera progresiva aún
     * no ha transcurrido, para que el POS pueda programarse sin interpretar
     * reglas por su cuenta.
     *
     * @param string $uuid Identificador público UUID v4 de la ruta.
     *
     * @return void
     */
    public function retry(string $uuid): void
    {
        $result = $this->retryService->reintentar($uuid);

        if ($result['retry_after'] !== null) {
            Response::retryAfter((int) $result['retry_after']);
        }

        if (!$result['ok']) {
            Response::error(
                $result['message'],
                $result['status'],
                $result['errors'] === [] ? null : $result['errors'],
                $result['context']
            );

            return;
        }

        Response::success(
            [
                'orden'   => $result['order'],
                'factura' => $result['factura'],
            ],
            $result['message'],
            $result['status']
        );
    }

    /**
     * `POST /api/facturacion/ordenes/{uuid}/anular` — anula una factura autorizada.
     *
     * @param string $uuid Identificador público UUID v4 de la ruta.
     *
     * @return void
     */
    public function annul(string $uuid): void
    {
        $payload = $this->readJsonBody();

        if ($payload === null) {
            return;
        }

        $result = $this->annulmentService->anular($uuid, $payload);

        if (!$result['ok']) {
            Response::error(
                $result['message'],
                $result['status'],
                $result['errors'] === [] ? null : $result['errors'],
                $result['context']
            );

            return;
        }

        Response::success(
            [
                'orden'     => $result['order'],
                'anulacion' => $result['anulacion'],
            ],
            $result['message'],
            $result['status']
        );
    }

    /**
     * `GET /api/facturacion/ordenes/{uuid}` — consulta el estado de una orden.
     *
     * @param string $uuid Identificador público UUID v4 de la ruta.
     *
     * @return void
     */
    public function show(string $uuid): void
    {
        $order = $this->service->consultar($uuid);

        if ($order === null) {
            Response::error('No existe una orden con el UUID indicado.', 404);

            return;
        }

        Response::success($order, 'Orden encontrada correctamente.', 200);
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
}
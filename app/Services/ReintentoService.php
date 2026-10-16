<?php

declare(strict_types=1);

namespace App\Services;

use App\Integrations\Guatefacturas\CommunicationException;
use App\Integrations\Guatefacturas\ConfigurationException;
use App\Integrations\Guatefacturas\DocumentEmitter;
use App\Integrations\Guatefacturas\DocumentResult;
use App\Integrations\Guatefacturas\GuatefacturasConfig;
use App\Integrations\Guatefacturas\ProviderBusinessException;
use App\Integrations\Guatefacturas\ProviderResponseClassifier;
use App\Models\GfOrder;
use App\Repositories\GfOrderRepository;

/**
 * Reintento seguro de una orden con desenlace incierto.
 *
 * ## El reenvío es la consulta
 *
 * El proveedor solo expone `generaDocumento()` y `anulaDocumento()`: **no hay
 * operación para consultar el estado de un documento**. La idea de «consultar la
 * referencia antes de reenviar», que se había anunciado como pendiente al cerrar
* HU-3, no tiene operación que la respalde.
 *
 * Lo que sí está verificado es que **reenviar el mismo XML con la misma
 * `Referencia` devuelve el documento ya emitido**, con la misma serie, el mismo
 * preimpreso y la misma autorización. Eso convierte al reenvío en el mecanismo de
 * resolución: si la emisión se había perdido, ahora se emite; si ya se había
 * emitido, el proveedor devuelve lo que emitió. En los dos casos queda exactamente
 * una factura.
 *
 * ## Por qué se reenvía el XML guardado y no se reconstruye
 *
 * `sent_xml` es la copia exacta del primer envío. Reconstruir el documento desde
 * el JSON de la orden podría producir un XML que difiere en un céntimo por
 * redondeo, y el proveedor no lo reconocería como el mismo documento: emitiera un
 * segundo, que es justo lo que esta historia existe para evitar.
 *
 * ## Los cuatro bloqueos y por qué son distintos
 *
 * | `motivo`           | Significado                                        | ¿Se resuelve solo? |
 * |--------------------|----------------------------------------------------|--------------------|
 * | `ya_facturada`     | Ya hay factura. Reintentar la duplicaría.          | No, nunca          |
 * | `anulada`          | La factura fue anulada.                            | No, nunca          |
 * | `en_curso`         | Hay una emisión en curso para la misma referencia. | Sí, al terminar    |
 * | `nunca_enviada`    | No hay documento que reenviar.                     | No, con otro POST  |
 * | `intentos_agotados`| Se agotó el presupuesto de emisiones.              | No, con revisión   |
 * | `demasiado_pronto` | La espera progresiva aún no ha transcurrido.      | Sí, esperando      |
 *
 * @package App\Services
 *
 * @author  fel-dentall
 * @license MIT
 */
final class ReintentoService
{
    /**
     * Repositorio de órdenes.
     *
     * @var GfOrderRepository
     */
    private $repository;

    /**
     * Emisor de documentos.
     *
     * @var DocumentEmitter
     */
    private $client;

    /**
     * Política de reintentos.
     *
     * @var RetryPolicy
     */
    private $policy;

    /**
     * @param GfOrderRepository $repository Repositorio de órdenes.
     * @param DocumentEmitter   $client     Emisor de documentos.
     * @param RetryPolicy       $policy     Política de reintentos.
     */
    public function __construct(
        GfOrderRepository $repository,
        DocumentEmitter $client,
        RetryPolicy $policy
    ) {
        $this->repository = $repository;
        $this->client     = $client;
        $this->policy     = $policy;
    }

    /**
     * Reenvía al proveedor el documento exacto de una orden ya registrada.
     *
     * @param string $uuid Identificador público UUID v4 de la orden.
     *
     * @return array{
     *     ok: bool, status: int, order: array<string, mixed>|null,
     *     factura: array<string, mixed>|null, errors: array<string, array<int, string>>,
     *     message: string, context: array<string, mixed>|null, retry_after: int|null
     * } Sobre de resultado con el código HTTP sugerido.
     */
    public function reintentar(string $uuid): array
    {
        $order = $this->repository->findByUuid($uuid);

        if ($order === null) {
            return $this->failure(404, 'No existe una orden con el UUID indicado.');
        }

        // --- ¿Procede el reintento? ------------------------------------------
        $situacion = $this->policy->evaluate($order);

        if (!$situacion['permitido']) {
            return $this->blocked($order, $situacion);
        }

        // Un documento que nunca salió no se puede reenviar: no existe.
        if ($order->getSentXml() === null) {
            return $this->failure(409, sprintf(
                'La orden "%s" nunca llegó al proveedor, así que no hay documento que '
                . 'reenviar. Vuelva a enviarla por POST /api/facturacion/ordenes.',
                $order->getOrderCode()
            ), [], $this->context($order), null);
        }

        // --- Reenvío del documento almacenado ---------------------------------
        // La `<Referencia>` viaja dentro de `sent_xml`, así que el reenvío usa
        // automáticamente la misma clave de idempotencia del primer intento.
        $xml = (string) $order->getSentXml();

        $this->repository->markAsSent(
            $order->getUuid(),
            $xml,
            $order->getAttempts() + 1
        );

        try {
            $rawResult = $this->client->generaDocumento(
                $order->getDteType(),
                $xml,
                GuatefacturasConfig::RESPONSE_SUMMARY
            );
        } catch (ProviderBusinessException $exception) {
            // El proveedor respondió y rechazó. Con el error 82 puede que el
            // documento sea correcto y lo que se haya superado sea el límite de
            // emisiones, así que se distingue igual que en HU-3.
            $ambiguous = $exception->rawResult() !== null
                && ProviderResponseClassifier::classify($exception->rawResult())
                    === ProviderResponseClassifier::OUTCOME_RATE_LIMITED;

            $this->repository->markAsFailed(
                $order->getUuid(),
                $ambiguous
                    ? GfOrder::STATUS_RETRY_PENDING
                    : GfOrder::STATUS_PROVIDER_ERROR,
                $exception->getMessage(),
                $ambiguous,
                ProviderResponseClassifier::errorCode($exception->rawResult()),
                $exception->rawResult()
            );

            return $this->failure(422, sprintf(
                'El proveedor rechazó la factura: %s',
                $exception->getMessage()
            ), [], $this->context($order), null);
        } catch (CommunicationException $exception) {
            $this->repository->markAsFailed(
                $order->getUuid(),
                GfOrder::STATUS_RETRY_PENDING,
                $exception->getMessage(),
                $exception->isAmbiguous(),
                ProviderResponseClassifier::errorCode($exception->getMessage())
            );

            return $this->failure(503, sprintf(
                'El proveedor sigue sin responder. La orden conservó el UUID %s y el '
                . 'documento se reintentará sin duplicar la factura.',
                $order->getUuid()
            ), [], $this->context($order), null);
        } catch (ConfigurationException $exception) {
            $this->repository->markAsFailed(
                $order->getUuid(),
                GfOrder::STATUS_RETRY_PENDING,
                $exception->getMessage(),
                false,
                null
            );

            return $this->failure(503, $exception->getMessage(), [], $this->context($order), null);
        }

        // --- Resolución del desenlace ----------------------------------------
        // Si el documento ya estaba emitido, el proveedor devuelve la misma serie,
        // el mismo preimpreso y la misma autorización: la factura queda resuelta
        // sin haberse creado una segunda.
        $result = DocumentResult::fromResult($rawResult);

        $this->repository->markAsInvoiced(
            $order->getUuid(),
            $result->serie(),
            $result->preimpreso(),
            $result->autorizacion(),
            $rawResult
        );

        $final = $this->repository->findByUuid($order->getUuid()) ?? $order;

        return [
            'ok'      => true,
            'status'  => 200,
            'order'   => $final->toArrayPublic(),
            'factura' => $result->toArray(),
            'errors'  => [],
            'message' => sprintf(
                'Factura %s resuelta con el documento almacenado.',
                $result->documentId()
            ),
            'context' => null,
            'retry_after' => null,
        ];
    }

    /**
     * Traduce un bloqueo de la política a una respuesta concreta.
     *
     * @param GfOrder                        $order     Orden evaluada.
     * @param array<string, mixed>           $situacion Situacion devuelta por la política.
     *
     * @return array<string, mixed> Sobre de error con su código HTTP.
     */
    private function blocked(GfOrder $order, array $situacion): array
    {
        $codigo = (string) $situacion['motivo'];

        if ($codigo === 'demasiado_pronto') {
            $espera = (int) $situacion['esperar_segundos'];

            return $this->failure(429, sprintf(
                'Aún no corresponde reintentar. Faltan %d segundos desde el último intento.',
                $espera
            ), [], $this->context($order), $espera);
        }

        $mensajes = [
            'ya_facturada' => sprintf(
                'La orden "%s" ya cuenta con la factura %s. No hay nada que reintentar.',
                $order->getOrderCode(),
                (string) ($order->toArrayPublic()['documento'] ?? '')
            ),
            'anulada' => sprintf(
                'La factura de la orden "%s" fue anulada. No puede reemitirse.',
                $order->getOrderCode()
            ),
            'en_curso' => sprintf(
                'La orden "%s" tiene una emisión en curso. Espere a que termine antes de reintentar.',
                $order->getOrderCode()
            ),
            'nunca_enviada' => sprintf(
                'La orden "%s" nunca llegó al proveedor, así que no hay documento que reenviar. '
                . 'Vuelva a enviarla por POST /api/facturacion/ordenes.',
                $order->getOrderCode()
            ),
            'intentos_agotados' => sprintf(
                'La orden "%s" alcanzó el máximo de %d intentos y requiere revisión manual.',
                $order->getOrderCode(),
                $this->policy->maxAttempts()
            ),
        ];

        return $this->failure(
            409,
            $mensajes[$codigo] ?? 'La orden no admite reintento en su estado actual.',
            [],
            $this->context($order),
            null
        );
    }

    /**
     * Compone el contexto que recibe el POS junto al fallo.
     *
     * @param GfOrder $order Orden evaluada.
     *
     * @return array<string, mixed> Contexto con `uuid`, `estado` y la política aplicada.
     */
    private function context(GfOrder $order): array
    {
        $fresh  = $this->repository->findByUuid($order->getUuid()) ?? $order;
        $actual = $fresh->toArrayPublic();

        $actual['reintento'] = $this->policy->evaluate($fresh);

        return [
            'uuid'         => $fresh->getUuid(),
            'estado'       => $fresh->getStatus(),
            'reintentable' => $actual['reintento']['permitido'],
            'ambiguo'      => $fresh->isAmbiguous(),
            'orden'        => $actual,
        ];
    }

    /**
     * Construye un sobre de resultado con error.
     *
     * @param int                         $status     Código HTTP sugerido.
     * @param string                      $message    Mensaje general.
     * @param array<string, array<int, string>> $errors Errores por campo.
     * @param array<string, mixed>|null   $context    Contexto de la orden.
     * @param int|null                    $retryAfter Segundos que faltan, si aplica.
     *
     * @return array<string, mixed> Sobre con `ok` a `false`.
     */
    private function failure(
        int $status,
        string $message,
        array $errors = [],
        ?array $context = null,
        ?int $retryAfter = null
    ): array {
        return [
            'ok'      => false,
            'status'  => $status,
            'order'   => null,
            'factura' => null,
            'errors'  => $errors,
            'message' => $message,
            'context' => $context,
            'retry_after' => $retryAfter,
        ];
    }
}
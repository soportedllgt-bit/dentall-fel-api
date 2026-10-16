<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\AppConfig;
use App\Integrations\Guatefacturas\CommunicationException;
use App\Integrations\Guatefacturas\ConfigurationException;
use App\Integrations\Guatefacturas\DocumentEmitter;
use App\Integrations\Guatefacturas\GuatefacturasClient;
use App\Integrations\Guatefacturas\ProviderBusinessException;
use App\Models\GfOrder;
use App\Repositories\GfOrderRepository;

/**
 * Anulación de una factura ya autorizada.
 *
 * ## Por qué no recibe el XML
 *
 * `anulaDocumento()` no es la emisión con otro signo: identifica el documento por
 * serie y preimpreso y **no acepta el XML**. Por eso la serie, el preimpreso y el NIT
 * del comprador se leen de lo que ya quedó registrado en la orden, y no de la
 * petición: el POS no debe poder anular un documento distinto del que dice (CA5.4).
 *
 * ## Los datos que el proveedor exige
 *
 * Comprobados contra el entorno de pruebas, no deducidos del manual:
 *
 * | Parámetro        | Exigido                                        |
 * |------------------|------------------------------------------------|
 * | Fecha            | `AAAAMMDD`; `DD/MM/AAAA` se rechaza            |
 * | NIT del comprador| Obligatorio; vacío da `ORA-01400`              |
 * | Motivo           | Hasta 729 caracteres; 730 da `ORA-06502`        |
 *
 * ## La anulación es terminal
 *
 * Una factura anulada no se puede reemitir: la orden queda en `ANULADO`, con su
 * `codigo_orden` ocupado por la restricción de unicidad. Volver a facturar la misma
 * venta exige un código de orden distinto, que es responsabilidad del POS (CA5.8).
 *
 * @package App\Services
 *
 * @author  fel-dentall
 * @license MIT
 */
final class AnulacionService
{
    /**
     * Longitud mínima del motivo.
     *
     * Un motivo de una sola letra no explica nada en una factura anulada, que es un
     * documento que puede acabar revisándose a mano.
     *
     * @var int
     */
    public const REASON_MIN = 2;

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
     * Zona horaria del emisor, que es donde se fecha la anulación.
     *
     * @var \DateTimeZone
     */
    private $documentTimezone;

    /**
     * @param GfOrderRepository $repository Repositorio de órdenes.
     * @param DocumentEmitter   $client     Cliente del proveedor.
     * @param AppConfig         $config     Configuración de la aplicación.
     */
    public function __construct(
        GfOrderRepository $repository,
        DocumentEmitter $client,
        AppConfig $config
    ) {
        $this->repository       = $repository;
        $this->client           = $client;
        $this->documentTimezone = new \DateTimeZone(
            (string) $config->getBillingConfig()['document_timezone']
        );
    }

    /**
     * Anula la factura de una orden ya autorizada.
     *
     * @param string               $uuid  Identificador público UUID v4 de la orden.
     * @param array<string, mixed> $input Cuerpo de la petición.
     *
     * @return array{
     *     ok: bool, status: int, order: array<string, mixed>|null,
     *     anulacion: array<string, mixed>|null, errors: array<string, array<int, string>>,
     *     message: string, context: array<string, mixed>|null
     * } Sobre de resultado con el código HTTP sugerido.
     */
    public function anular(string $uuid, array $input): array
    {
        $order = $this->repository->findByUuid($uuid);

        if ($order === null) {
            return $this->failure(404, 'No existe una orden con el UUID indicado.');
        }

        // --- ¿Procede anular? -------------------------------------------------
        // Solo una factura autorizada es anulable. Anular lo que no se emitió
        // gasta una llamada y, peor, deja constancia de una anulación inexistente.
        if ($order->getStatus() !== GfOrder::STATUS_INVOICED) {
            return $this->failure(409, sprintf(
                'La orden "%s" está en estado %s y solo puede anularse una factura autorizada.',
                $order->getOrderCode(),
                $order->getStatus()
            ), [], $this->context($order));
        }

        if ($order->getSerie() === null || $order->getPreimpreso() === null) {
            // No debería ocurrir: `markAsInvoiced()` los escribe siempre. Si se
            // llega aquí, la fila está incompleta y no se puede llamar al proveedor.
            return $this->failure(409, sprintf(
                'La orden "%s" no conserva la serie ni el preimpreso del documento, '
                . 'así que no se puede anular.',
                $order->getOrderCode()
            ), [], $this->context($order));
        }

        // --- Motivo -----------------------------------------------------------
        $motivo = $this->validateReason($input);

        if ($motivo === null) {
            return $this->failure(400, 'El motivo de la anulación no es válido.', $this->reasonErrors($input));
        }

        // --- NIT del comprador ------------------------------------------------
        // Se toma del pedido original, nunca de la petición.
        $nit = $this->buyerNit($order);

        if ($nit === null) {
            return $this->failure(409, sprintf(
                'La orden "%s" no conserva el NIT del comprador, que el proveedor exige '
                . 'para anular. No se puede completar la anulación.',
                $order->getOrderCode()
            ), [], $this->context($order));
        }

        // --- Llamada al proveedor ---------------------------------------------
        $fecha = $this->fechaAnulacion();

        try {
            $rawResult = $this->client->anulaDocumento(
                $order->getSerie(),
                $order->getPreimpreso(),
                $nit,
                $fecha,
                $motivo
            );
        } catch (ProviderBusinessException $exception) {
            $this->repository->markAsAnnulmentRejected(
                $order->getUuid(),
                $exception->getMessage(),
                $exception->rawResult()
            );

            return $this->failure(422, sprintf(
                'El proveedor no anuló el documento: %s',
                $exception->getMessage()
            ), [], $this->context($order));
        } catch (CommunicationException | ConfigurationException $exception) {
            // La factura sigue autorizada: no se toca el estado terminal. Se deja
            // constancia del intento para que el reintento manual sea posible, pero
            // marcarla como anulada sería mentir sobre un documento que continúa
            // vigente ante la SAT.
            $this->repository->markAsAnnulmentFailed(
                $order->getUuid(),
                $exception->getMessage()
            );

            return $this->failure(503, sprintf(
                'No se pudo completar la anulación porque el proveedor no respondió. '
                . 'La factura %s-%s sigue vigente.',
                (string) $order->getSerie(),
                (string) $order->getPreimpreso()
            ), [], $this->context($order));
        }

        // --- Constancia -------------------------------------------------------
        $this->repository->markAsCancelled($order->getUuid(), $motivo, $rawResult);

        $final = $this->repository->findByUuid($order->getUuid()) ?? $order;

        return [
            'ok'        => true,
            'status'    => 200,
            'order'     => $final->toArrayPublic(),
            'anulacion' => [
                'documento'  => $order->getSerie() . '-' . $order->getPreimpreso(),
                'fecha'      => $fecha,
                'motivo'     => $motivo,
                'respuesta'  => mb_substr(trim($rawResult), 0, 1000),
            ],
            'errors'    => [],
            'message'   => sprintf(
                'Factura %s-%s anulada.',
                (string) $order->getSerie(),
                (string) $order->getPreimpreso()
            ),
            'context'   => null,
        ];
    }

    /**
     * Valida el motivo de la anulación.
     *
     * @param array<string, mixed> $input Cuerpo de la petición.
     *
     * @return string|null Motivo limpio, o `null` si no es válido.
     */
    private function validateReason(array $input): ?string
    {
        if (!array_key_exists('motivo', $input)) {
            return null;
        }

        $motivo = $input['motivo'];

        if (!is_string($motivo)) {
            return null;
        }

        $motivo = trim($motivo);

        if ($motivo === '' || mb_strlen($motivo, 'UTF-8') < self::REASON_MIN) {
            return null;
        }

        // El propio cliente del proveedor lo comprueba; aquí se adelanta para que
        // el POS reciba un `400` con el campo nombrado en vez de una excepción.
        if (mb_strlen($motivo, 'UTF-8') > GuatefacturasClient::MAX_ANNULMENT_REASON) {
            return null;
        }

        return $motivo;
    }

    /**
     * Compone los errores de validación del motivo.
     *
     * @param array<string, mixed> $input Cuerpo de la petición.
     *
     * @return array<string, array<int, string>> Errores por campo.
     */
    private function reasonErrors(array $input): array
    {
        $motivo = $input['motivo'] ?? null;

        if ($motivo === null) {
            return ['motivo' => ['El campo "motivo" es obligatorio.']];
        }

        if (!is_string($motivo)) {
            return ['motivo' => ['El campo "motivo" debe ser una cadena de texto.']];
        }

        $motivo = trim($motivo);

        if ($motivo === '') {
            return ['motivo' => ['El campo "motivo" no puede quedar vacío.']];
        }

        if (mb_strlen($motivo, 'UTF-8') < self::REASON_MIN) {
            return ['motivo' => [sprintf(
                'El motivo debe tener al menos %d caracteres.',
                self::REASON_MIN
            )]];
        }

        return ['motivo' => [sprintf(
            'El motivo no puede superar los %d caracteres.',
            GuatefacturasClient::MAX_ANNULMENT_REASON
        )]];
    }

    /**
     * Extrae el NIT del comprador del pedido original.
     *
     * @param GfOrder $order Orden con su carga útil.
     *
     * @return string|null NIT registrado, o `null` si no lo hay.
     */
    private function buyerNit(GfOrder $order): ?string
    {
        $payload = $order->getPayload();
        $nit     = $payload['cliente']['nit'] ?? null;

        if (!is_string($nit)) {
            return null;
        }

        $nit = trim($nit);

        return $nit === '' ? null : $nit;
    }

    /**
     * Calcula la fecha de anulación en la zona del emisor.
     *
     * ## Por qué no se usa `gmdate()`
     *
     * El proveedor exige `AAAAMMDD` y compara esa fecha con la suya, que es hora de
     * Guatemala. Entre las 18:00 y las 24:00 la fecha UTC ya es la del día siguiente,
     * de modo que una anulación fechada en UTC se rechazaría en ese mismo tramo que
     * ya obliga a documentar los billetes en hora local.
     *
     * @return string Fecha en formato `AAAAMMDD`.
     */
    private function fechaAnulacion(): string
    {
        return (new \DateTimeImmutable('now', $this->documentTimezone))->format('Ymd');
    }

    /**
     * Compone el contexto que recibe el POS junto al fallo.
     *
     * @param GfOrder $order Orden evaluada.
     *
     * @return array<string, mixed> Contexto con `uuid`, `estado` y la orden.
     */
    private function context(GfOrder $order): array
    {
        $fresh = $this->repository->findByUuid($order->getUuid()) ?? $order;

        return [
            'uuid'         => $fresh->getUuid(),
            'estado'       => $fresh->getStatus(),
            'reintentable' => $fresh->isRetryable(),
            'ambiguo'      => $fresh->isAmbiguous(),
            'orden'        => $fresh->toArrayPublic(),
        ];
    }

    /**
     * Construye un sobre de resultado con error.
     *
     * @param int                         $status  Código HTTP sugerido.
     * @param string                      $message Mensaje general.
     * @param array<string, array<int, string>> $errors Errores por campo.
     * @param array<string, mixed>|null   $context Contexto de la orden.
     *
     * @return array<string, mixed> Sobre con `ok` a `false`.
     */
    private function failure(
        int $status,
        string $message,
        array $errors = [],
        ?array $context = null
    ): array {
        return [
            'ok'        => false,
            'status'    => $status,
            'order'     => null,
            'anulacion' => null,
            'errors'    => $errors,
            'message'   => $message,
            'context'   => $context,
        ];
    }
}
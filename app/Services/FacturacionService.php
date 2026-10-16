<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\AppConfig;
use App\Core\Uuid;
use App\Integrations\Guatefacturas\CommunicationException;
use App\Integrations\Guatefacturas\ConfigurationException;
use App\Integrations\Guatefacturas\DocumentEmitter;
use App\Integrations\Guatefacturas\DocumentResult;
use App\Integrations\Guatefacturas\GuatefacturasConfig;
use App\Integrations\Guatefacturas\OrderPayload;
use App\Integrations\Guatefacturas\ProviderBusinessException;
use App\Integrations\Guatefacturas\ProviderResponseClassifier;
use App\Integrations\Guatefacturas\XmlBuilder;
use App\Models\GfOrder;
use App\Repositories\GfOrderRepository;

/**
 * Orquestador de la emisión de facturas.
 *
 * Es la única capa que conoce el orden completo de la operación:
 *
 * 1. **Validar y normalizar** el JSON del POS ({@see OrderPayload}).
 * 2. **Comprobar la idempotencia**: si el código de orden ya tiene una factura,
 *    devolver `409` sin volver a llamar al proveedor. Dos llamadas del POS con
 *    la misma orden no pueden producir dos facturas.
 * 3. **Persistir** la orden recibida en estado `PENDIENTE`, antes de salir a
 *    la red. Si la emisión se pierde a mitad, el registro queda y permite
 *    reconstruir el documento.
 * 4. **Construir el XML** ({@see XmlBuilder}).
 * 5. **Enviarlo por SOAP** ({@see GuatefacturasClient}).
 * 6. **Registrar la respuesta**: con éxito, `FACTURADO` con serie, preimpreso y
 *    autorización; con fallo, `ERROR_PROVEEDOR` conservando el motivo.
 *
 * ## El XML se guarda antes de enviarlo
 *
 * `markAsSent()` se invoca con el XML exacto justo antes de la llamada. Ese
 * documento es el insumo del reintento con `<Reversion>` y de la anulación: debe
 * poder reenviarse sin volver a construirse, porque una reconstrucción podría
 * diferir en un céntimo y producir un documento que el proveedor no reconoce.
 *
 * ## Distinción entre fallo limpio y fallo ambiguo
 *
 * Es la decisión central de esta historia, y es la que no se puede dejar al
 * código HTTP. Antes de HU-3 los dos casos devolvían lo mismo:
 *
 * | Situación                                         | Antes                  | Ahora                     |
 * |---------------------------------------------------|------------------------|---------------------------|
 * | `502` o tiempo de espera agotado                   | `422` «rechazó»       | `503` + UUID reintentable  |
 * | `<Resultado></Resultado>` vacío                    | `422` «rechazó»       | `503` + UUID reintentable  |
 * | `82-ERROR CALCULO…` (puede ser límite de emisiones)| `422` «rechazó»       | `503` + UUID reintentable  |
 * | `56-FECHA…` o `ERROR el TAG1…`                     | `422` «rechazó»       | `422` + UUID              |
 *
 * Informar de un rechazo de negocio cuando el servicio estaba caído no es un
 * detalle cosmético: el vendedor ve su venta rechazada, revisa los datos de su
 * cliente, no encuentra el error y pierde la venta. Peor aún, el sistema no
 * tiene nada que reintentar porque se le dijo que el documento era inválido.
 *
 * La clasificación la hace {@see ProviderResponseClassifier} y decide tres
 * cosas a la vez: el estado persistido, el código HTTP y si el POS puede
 * reenviar la orden.
 *
 * @package App\Services
 *
 * @author  fel-dentall
 * @license MIT
 */
final class FacturacionService
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
     * Depende del contrato {@see DocumentEmitter} y no de la implementación
     * concreta, para que la matriz de fallos pueda probarse con un emidor
     * simulado sin llamar al proveedor.
     *
     * @var DocumentEmitter
     */
    private $client;

    /**
     * Reglas de facturación del entorno activo.
     *
     * @var array<string, mixed>
     */
    private $rules;

    /**
     * Política de reintentos, que orienta al POS sobre la orden consultada.
     *
     * @var RetryPolicy
     */
    private $retryPolicy;

    /**
     * Zona horaria en la que se fecha el documento.
     *
     * @var \DateTimeZone
     */
    private $documentTimezone;

    /**
     * Antigüedad tras la cual una orden en `EN_PROCESO` se considera atascada.
     *
     * @var int
     */
    private $processingTimeout;

    /**
     * Número máximo de intentos de emisión por orden.
     *
     * @var int
     */
    private $maxAttempts;

    /**
     * @param GfOrderRepository $repository Repositorio de órdenes.
     * @param DocumentEmitter   $client     Emisor de documentos.
     * @param AppConfig         $config     Configuración de la aplicación.
     */
    public function __construct(
        GfOrderRepository $repository,
        DocumentEmitter $client,
        AppConfig $config
    ) {
        $this->repository         = $repository;
        $this->client             = $client;
        $this->rules              = $config->getBillingConfig();
        $this->documentTimezone   = new \DateTimeZone((string) $this->rules['document_timezone']);
        $this->processingTimeout  = (int) ($this->rules['processing_timeout'] ?? 300);
        $this->maxAttempts        = (int) ($this->rules['max_attempts'] ?? 5);
        $this->retryPolicy        = RetryPolicy::fromRules($this->rules);
    }

    /**
     * Recibe una orden del POS y la emite contra el proveedor.
     *
     * @param array<string, mixed> $input Cuerpo JSON de la petición.
     *
     * @return array{
     *     ok: bool, status: int, order: array<string, mixed>|null, factura: array<string, mixed>|null,
     *     errors: array<string, array<int, string>>, message: string
     * }
     *         Sobre de resultado con el código HTTP sugerido y la factura emitida.
     */
    public function facturar(array $input): array
    {
        // --- 1. Validación y normalización ----------------------------------
        $parsed = OrderPayload::fromArray($input, $this->rules);

        if (!$parsed['ok']) {
            return $this->failure(400, $parsed['message'], $parsed['errors']);
        }

        /** @var OrderPayload $order */
        $order = $parsed['order'];

        // --- 2. Idempotencia --------------------------------------------------
        $existing = $this->repository->findByOrderCode($order->orderCode());

        if ($existing !== null && $existing->isTerminal()) {
            return $this->alreadyResolved($existing);
        }

        // Una orden en curso no se vuelve a procesar: hacerlo enviaría dos
        // documentos por la misma referencia a la vez. La excepción es una
        // orden atascada, que se describe más abajo.
        if ($existing !== null
            && $existing->getStatus() === GfOrder::STATUS_PROCESSING
            && !$existing->isStuckProcessing($this->processingTimeout)
        ) {
            return $this->failure(409, sprintf(
                'La orden "%s" está siendo procesada en este momento.',
                $order->orderCode()
            ), [], $this->context($existing));
        }

        if ($existing !== null && $existing->getAttempts() >= $this->maxAttempts) {
            // Tope de intentos: sin él, un POS que reintenta en bucle consume
            // emisiones del sandbox y del proveedor real sin límite.
            return $this->failure(409, sprintf(
                'La orden "%s" alcanzó el máximo de %d intentos. Requiere revisión manual.',
                $order->orderCode(),
                $this->maxAttempts
            ), [], $this->context($existing));
        }

        // --- 3. Persistencia inicial -----------------------------------------
        if ($existing === null) {
            $entity = new GfOrder(
                Uuid::v4(),
                $order->orderCode(),
                $order->dteType(),
                GfOrder::STATUS_PENDING,
                $order->toArray(),
                self::totalsInCents($order),
                $order->totals()->lineCount(),
                gmdate('Y-m-d H:i:s')
            );

            $stored = $this->repository->create($entity);
        } else {
            // Reintento de una orden que ya existe y no está en curso.
            $stored = $existing;
        }

        // --- 4. Construcción del XML -----------------------------------------
        $builder = new XmlBuilder(
            $order->customer(),
            $order->invoicing(),
            $order->delivery(),
            $order->orderCode(),
            $this->documentDate(),
            $order->lines(),
            $order->totals()
        );

        $xml = $builder->build();

        // --- 5. Envío por SOAP -----------------------------------------------
        $this->repository->markAsSent(
            $stored->getUuid(),
            $xml,
            $stored->getAttempts() + 1
        );

        try {
            $rawResult = $this->client->generaDocumento(
                $order->dteType(),
                $xml,
                GuatefacturasConfig::RESPONSE_SUMMARY
            );
        } catch (ProviderBusinessException $exception) {
            // Rechazo limpio: el proveedor respondió y no emitió. Los datos son
            // incorrectos; el POS debe corregirlos, no reintentar a ciegas.
            $this->repository->markAsFailed(
                $stored->getUuid(),
                GfOrder::STATUS_PROVIDER_ERROR,
                $exception->getMessage(),
                false,
                ProviderResponseClassifier::errorCode($exception->rawResult()),
                $exception->rawResult()
            );

            return $this->failure(422, sprintf(
                'El proveedor rechazó la factura: %s',
                $exception->getMessage()
            ), [], $this->context($stored));
        } catch (CommunicationException $exception) {
            // Fallo no concluyente: no sabemos si llegó a emitir. Se registra y
            // se devuelve el UUID para que el POS pueda reintentar o consultar.
            $this->repository->markAsFailed(
                $stored->getUuid(),
                GfOrder::STATUS_RETRY_PENDING,
                $exception->getMessage(),
                $exception->isAmbiguous(),
                ProviderResponseClassifier::errorCode($exception->getMessage())
            );

            return $this->failure(503, sprintf(
                'El proveedor no está disponible. La orden quedó registrada con el UUID %s '
                . 'y puede reintentarse sin duplicar la factura.',
                $stored->getUuid()
            ), [], $this->context($stored));
        } catch (ConfigurationException $exception) {
            // Sin credenciales o con el WSDL inaccesible no se emitió nada, pero la orden
            // queda reintentable: en cuanto se corrija la configuración, reenviarla
            // la resuelve. El fallo es de este lado, no del documento.
            $this->repository->markAsFailed(
                $stored->getUuid(),
                GfOrder::STATUS_RETRY_PENDING,
                $exception->getMessage(),
                false,
                null
            );

            return $this->failure(
                503,
                $exception->getMessage(),
                [],
                $this->context($stored)
            );
        }

        // --- 6. Registro de la respuesta --------------------------------------
        $result = DocumentResult::fromResult($rawResult);

        $this->repository->markAsInvoiced(
            $stored->getUuid(),
            $result->serie(),
            $result->preimpreso(),
            $result->autorizacion(),
            $rawResult
        );

        $final = $this->repository->findByUuid($stored->getUuid());

        return [
            'ok'      => true,
            'status'  => 201,
            'order'   => $final === null ? $stored->toArrayPublic() : $final->toArrayPublic(),
            'factura' => $this->invoiceDigest($result),
            'errors'  => [],
            'message' => sprintf(
                'Factura %s autorizada por SAT.',
                $result->documentId()
            ),
            'context' => null,
        ];
    }

    /**
     * Recupera una orden por su identificador público.
     *
     * La respuesta incluye la situación de reintento para que el POS pueda
     * programarse sin tener que deducir la espera ni el número de intentos
     * restantes por su cuenta (CA4.9).
     *
     * @param string $uuid Identificador público UUID v4.
     *
     * @return array<string, mixed>|null Representación pública o `null`.
     */
    public function consultar(string $uuid): ?array
    {
        $order = $this->repository->findByUuid($uuid);

        if ($order === null) {
            return null;
        }

        $public = $order->toArrayPublic();

        $public['reintento'] = $this->retryPolicy->evaluate($order);

        return $public;
    }

    /**
     * Compone el digest de la factura que recibe el POS (CA3.1).
     *
     * ## Qué necesita la tablet para seguir vendiendo
     *
     * Al autorizar una venta el POS tiene que poder imprimir el recibo y cerrar
     * la operación sin volver a preguntar. Eso exige tres cosas, que se
     * agrupan aquí para que el cliente no tenga que buscarlas en objetos anidados:
     *
     * 1. **El estado del documento.** El proveedor no lo devuelve como campo, pero
     *    se deduce: si hay serie, preimpreso y autorización, SAT lo autorizó.
     * 2. **Los datos de autorización**, tal cual los devuelve el proveedor. No se
     *    reformatean ni se completan con guiones porque no hay una convención
     *    verificable a la que atenerse: un número de autorización inventado es
     *    peor que ninguno.
     * 3. **Un resumen imprimible**, con los valores literales que el proveedor
     *    devolvió y su etiqueta en español.
     *
     * @param DocumentResult $result Resultado digerido del proveedor.
     *
     * @return array<string, mixed> Digest de la factura.
     */
    private function invoiceDigest(DocumentResult $result): array
    {
        $digest = $result->toArray();

        $digest['estado'] = 'AUTORIZADA';
        $digest['resumen_impresion'] = trim(sprintf(
            'Serie: %s  Preimpreso: %s  Autorización: %s',
            $result->serie(),
            $result->preimpreso(),
            $result->autorizacion()
        ));

        // El XML firmado se conserva íntegro en la base de datos; incluirlo en
        // cada respuesta lo multiplicaría por cada reintento del POS. Se indica
        // únicamente si está disponible.
        $digest['xml_firmado_disponible'] = $result->signedXml() !== null;

        return $digest;
    }

    /**
     * Competa el contexto que el POS recibe junto a un fallo (CA3.3).
     *
     * La orden se vuelve a leer de la base de datos en lugar de usar la entidad
     * en memoria: `markAsFailed()` actualizó el estado en SQL y la copia local
     * sigue diciendo `EN_PROCESO`. Devolver el estado desactualizado haría que el
     * POS creyera que la operación sigue en curso.
     *
     * @param GfOrder $order Orden sobre la que se registró el fallo.
     *
     * @return array<string, mixed> Contexto con `uuid`, `estado`, `orden` y `reintentable`.
     */
    private function context(GfOrder $order): array
    {
        $fresh = $this->repository->findByUuid($order->getUuid()) ?? $order;

        $public = [
            'uuid'         => $fresh->getUuid(),
            'estado'       => $fresh->getStatus(),
            'reintentable' => $fresh->isRetryable(),
            'ambiguo'      => $fresh->isAmbiguous(),
            'orden'        => $fresh->toArrayPublic(),
        ];

        $public['orden']['reintento'] = $this->retryPolicy->evaluate($fresh);

        return $public;
    }

    /**
     * Construye la respuesta cuando la orden ya está resuelta.
     *
     * Una orden `ANULADO` se responde con `409` igual que una `FACTURADO`, pero
     * con un mensaje distinto: en el primer caso el POS debe reemitir
     * una factura que ya no existe, y en el segundo debe imprimir la que hay.
     *
     * @param GfOrder $order Orden ya resuelta.
     *
     * @return array<string, mixed> Sobre de error con código `409`.
     */
    private function alreadyResolved(GfOrder $order): array
    {
        if ($order->getStatus() === GfOrder::STATUS_CANCELLED) {
            return $this->failure(409, sprintf(
                'La factura de la orden "%s" fue anulada. No puede reemitirse.',
                $order->getOrderCode()
            ), [], $this->context($order));
        }

        $document = (string) ($order->toArrayPublic()['documento'] ?? '');

        return $this->failure(409, sprintf(
            'La orden "%s" ya cuenta con la factura %s.',
            $order->getOrderCode(),
            $document === '' ? 'autorizada' : $document
        ), [], $this->context($order));
    }

    /**
     * Calcula la fecha del documento en la zona horaria del emisor.
     *
     * ## Por qué no se usa `gmdate()`
     *
     * La fecha del documento es una fecha **local del emisor**, no un instante
     * en UTC. El proveedor la compara con la fecha de su propio sistema y
     * rechaza el documento si es posterior:
     *
     * ```
     * 56-FECHA DE DOCUMENTO NO PUEDE SER MAYOR A LA FECHA DEL SISTEMA
     * ```
     *
     * Ese rechazo se produjo en esta integración con `gmdate('d/m/Y')`, que
     * devuelve la fecha en UTC. Guatemala está seis horas por detrás de UTC, de
     * modo que entre las 18:00 y las 24:00 hora local la fecha UTC ya es la del
     * día siguiente y **toda factura emitida en ese tramo sería rechazada**. Es
     * un fallo que solo se manifiesta de noche y que ninguna prueba diurna
     * detectó.
     *
     * @return string Fecha en formato `DD/MM/AAAA`.
     */
    private function documentDate(): string
    {
        return (new \DateTimeImmutable('now', $this->documentTimezone))->format('d/m/Y');
    }

    /**
     * Convierte los totales de la orden a centavos enteros.
     *
     * @param OrderPayload $order Orden normalizada.
     *
     * @return array<string, int> Totales indexados por sufijo de columna.
     */
    private static function totalsInCents(OrderPayload $order): array
    {
        $totals = $order->totals();

        return [
            'gross'    => $totals->gross()->cents(),
            'discount' => $totals->discount()->cents(),
            'exempt'   => $totals->exempt()->cents(),
            'others'   => $totals->others()->cents(),
            'net'      => $totals->net()->cents(),
            'tax'      => $totals->tax()->cents(),
            'total'    => $totals->total()->cents(),
        ];
    }

    /**
     * Construye un sobre de resultado con error.
     *
     * El cuarto parámetro es el contexto de CA3.3: sin él, el POS recibe un
     * `uuid` embebido en el texto del mensaje y no tiene forma programática de
     * localizar la operación. Un mensaje no es un contrato: cambia con las
     * traducciones y no se puede consultar de forma fiable.
     *
     * @param int                               $status  Código HTTP sugerido.
     * @param string                            $message Mensaje general.
     * @param array<string, array<int, string>> $errors  Errores por campo.
     * @param array<string, mixed>|null         $context Contexto de la orden.
     *
     * @return array{
     *     ok: bool, status: int, order: array<string, mixed>|null, factura: array<string, mixed>|null,
     *     errors: array<string, array<int, string>>, message: string, context: array<string, mixed>|null
     * }
     *         Sobre con `ok` a `false`.
     */
    private function failure(
        int $status,
        string $message,
        array $errors = [],
        ?array $context = null
    ): array {
        return [
            'ok'      => false,
            'status'  => $status,
            'order'   => null,
            'factura' => null,
            'errors'  => $errors,
            'message' => $message,
            'context' => $context,
        ];
    }
}
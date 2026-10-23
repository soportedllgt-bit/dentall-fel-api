<?php

declare(strict_types=1);

namespace App\Models;

use App\Integrations\Guatefacturas\Decimal;

/**
 * Entidad de dominio de una orden de facturación electrónica.
 *
 * Representa un documento que la API ha recibido del POS y que ha enviado a
 * través del proveedor. La tabla que la respalda es `gf_orders`
 * (véase `sql/migrations/001_gf_orders.sql`).
 *
 * MODELO DE IDENTIFICACIÓN:
 * - `$id` es el identificador interno. NUNCA se expone al cliente.
 * - `$uuid` es el identificador público UUID v4 de la operación.
 * - `$orderCode` es el código único que envía el POS. Viaja al proveedor como
 *   `<Referencia>` y es la clave de idempotencia: dos peticiones con el mismo
 *   código no pueden producir dos facturas.
 *
 * ESTADOS:
 *
 * | Estado                  | Significado                                            |
 * |-------------------------|--------------------------------------------------------|
 * | `PENDIENTE`             | Recibida y validada; aún no enviada al proveedor.       |
 * | `EN_PROCESO`            | Enviada; la respuesta aún no se ha registrado.          |
 * | `FACTURADO`             | Autorizada por SAT. Estado terminal de éxito.           |
 * | `ERROR_PROVEEDOR`       | El proveedor rechazó el documento. Datos a corregir.    |
 * | `PENDIENTE_REINTENTO`   | Fallo no concluyente. Reintentable con el mismo XML.    |
 * | `ANULADO`               | Documento anulado en el proveedor. Estado terminal.     |
 *
 * ## Por qué `ERROR_PROVEEDOR` y `PENDIENTE_REINTENTO` están separados
 *
 * Ambos son fallos del proveedor, pero no admiten la misma reacción:
 *
 * - `ERROR_PROVEEDOR` significa que el proveedor **respondió y rechazó**: los
 *   datos son incorrectos y reenviar el mismo XML volverá a fallar.
 * - `PENDIENTE_REINTENTO` significa que **no sabemos**: el servicio no
 *   respondió, la respuesta llegó vacía, o el proveedor devolvió el error 82,
 *   que en el sandbox aparece tanto por un importe incorrecto como por superar
 *   el límite de emisiones. Reenviar el mismo XML es seguro y puede ser la
 *   solución.
 *
 * Antes de HU-3 ambos casos terminaban en `ERROR_PROVEEDOR` con HTTP `422`, y el
 * POS recibía «el proveedor rechazó la factura» incluso cuando el proveedor
 * estaba caído. El vendedor veía un rechazo de sus datos sin nada que reintentar.
 *
 *
 * @package App\Models
 *
 * @author  fel-dentall
 * @license MIT
 */
final class GfOrder
{
    /**
     * Recibida y validada, sin enviar al proveedor.
     *
     * @var string
     */
    public const STATUS_PENDING = 'PENDIENTE';

    /**
     * Enviada al proveedor, sin respuesta registrada todavía.
     *
     * @var string
     */
    public const STATUS_PROCESSING = 'EN_PROCESO';

    /**
     * Autorizada por SAT.
     *
     * @var string
     */
    public const STATUS_INVOICED = 'FACTURADO';

    /**
     * El proveedor rechazó el documento; los datos deben corregirse.
     *
     * @var string
     */
    public const STATUS_PROVIDER_ERROR = 'ERROR_PROVEEDOR';

    /**
     * Fallo no concluyente: reintentable con el mismo XML.
     *
     * No se puede afirmar que el documento no se haya emitido, de modo que
     * reenviarlo es seguro: el proveedor reconoce la `Referencia` y no genera
     * una segunda factura.
     *
     * @var string
     */
    public const STATUS_RETRY_PENDING = 'PENDIENTE_REINTENTO';

    /**
     * Documento anulado.
     *
     * @var string
     */
    public const STATUS_CANCELLED = 'ANULADO';

    /**
     * Estados en los que la orden ya tiene una factura y no debe repetirse.
     *
     * @var array<int, string>
     */
    public const TERMINAL_STATES = [self::STATUS_INVOICED, self::STATUS_CANCELLED];

    /**
     * Estados en los que el POS puede reenviar la orden sin riesgo de duplicar
     * la factura.
     *
     * @var array<int, string>
     */
    public const RETRYABLE_STATES = [
        self::STATUS_PENDING,
        self::STATUS_RETRY_PENDING,
        self::STATUS_PROVIDER_ERROR,
    ];

    /**
     * Identificador interno.
     *
     * @var int|null
     */
    private $id;

    /**
     * Identificador público UUID v4.
     *
     * @var string
     */
    private $uuid;

    /**
     * Código único de la orden del POS.
     *
     * @var string
     */
    private $orderCode;

    /**
     * Tipo de documento como código numérico.
     *
     * @var int
     */
    private $dteType;

    /**
     * Estado actual de la operación.
     *
     * @var string
     */
    private $status;

    /**
     * Copia normalizada de la orden en JSON.
     *
     * @var array<string, mixed>
     */
    private $payload;

    /**
     * Serie autorizada.
     *
     * @var string|null
     */
    private $serie;

    /**
     * Número correlativo autorizado.
     *
     * @var string|null
     */
    private $preimpreso;

    /**
     * Número de autorización entregado por SAT.
     *
     * @var string|null
     */
    private $autorizacion;

    /**
     * Texto íntegro devuelto por el proveedor.
     *
     * @var string|null
     */
    private $providerResponse;

    /**
     * XML exacto enviado al proveedor.
     *
     * @var string|null
     */
    private $sentXml;

    /**
     * Último error registrado.
     *
     * @var string|null
     */
    private $errorMessage;

    /**
     * Número de intentos de emisión.
     *
     * @var int
     */
    private $attempts;

    /**
     * Si el último fallo impide afirmar que el documento no se emitió.
     *
     * @var bool
     */
    private $ambiguous;

    /**
     * Código de error del proveedor en el último fallo.
     *
     * @var string|null
     */
    private $lastErrorCode;

    /**
     * Motivo con el que se anuló la factura.
     *
     * @var string|null
     */
    private $annulmentReason;

    /**
     * Respuesta íntegra del proveedor a la anulación.
     *
     * @var string|null
     */
    private $annulmentResponse;

    /**
     * Momento de la anulación en UTC.
     *
     * @var string|null
     */
    private $annulledAt;

    /**
     * Totales de la orden, indexados por sufijo de columna.
     *
     * @var array<string, int>
     */
    private $totals;

    /**
     * Número de líneas del carrito.
     *
     * @var int
     */
    private $itemCount;

    /**
     * Fecha de recepción en UTC.
     *
     * @var string
     */
    private $createdAt;

    /**
     * Fecha de la última actualización en UTC.
     *
     * @var string
     */
    private $updatedAt;

    /**
     * Momento de la autorización en UTC, o `null` si no se ha autorizado.
     *
     * @var string|null
     */
private $invoicedAt;

    /**
     * Modo de respuesta solicitado al proveedor.
     *
     * @var string `R` o `D`.
     */
    private $responseType = 'R';

    /**
     * @param string                $uuid       Identificador pǧblico UUID v4.
     * @param string                $orderCode  C��digo ǧnico de la orden del POS.
     * @param int                   $dteType    C��digo numǸrico del tipo de DTE.
     * @param string                $status     Estado inicial.
     * @param array<string, mixed>  $payload    Orden normalizada.
     * @param array<string, int>    $totals     Totales en centavos.
     * @param int                   $itemCount  Nǧmero de l��neas.
     * @param string                $createdAt  Fecha de recepci��n en UTC.
     * @param string                $responseType Modo de respuesta al proveedor.
     */
    public function __construct(
        string $uuid,
        string $orderCode,
        int $dteType,
        string $status,
        array $payload,
        array $totals,
        int $itemCount,
        string $createdAt,
        string $responseType = 'R'
    ) {
        $this->id            = null;
        $this->uuid          = $uuid;
        $this->orderCode     = $orderCode;
        $this->dteType       = $dteType;
        $this->status        = $status;
        $this->payload       = $payload;
        $this->totals        = $totals;
        $this->itemCount     = $itemCount;
        $this->createdAt     = $createdAt;
        $this->updatedAt     = $createdAt;
        $this->serie         = null;
        $this->preimpreso    = null;
        $this->autorizacion  = null;
        $this->providerResponse = null;
        $this->sentXml       = null;
        $this->errorMessage  = null;
        $this->attempts      = 0;
        $this->ambiguous     = false;
        $this->lastErrorCode = null;
        $this->invoicedAt    = null;
        $this->responseType  = $responseType === '' ? 'R' : $responseType;
    }

    /**
     * Construye la entidad a partir de una fila de `gf_orders`.
     *
     * @param array<string, mixed> $row Fila devuelta por PDO.
     *
     * @return self Entidad hidratada.
     */
    public static function fromDb(array $row): self
    {
        $payload = [];
        $decoded = json_decode((string) ($row['payload'] ?? ''), true);

        if (is_array($decoded)) {
            $payload = $decoded;
        }

        $order = new self(
            (string) $row['uuid'],
            (string) $row['order_code'],
            (int) $row['dte_type'],
            (string) $row['status'],
            $payload,
            [
                'gross'    => (int) ($row['totals_gross'] ?? 0),
                'discount' => (int) ($row['totals_discount'] ?? 0),
                'exempt'   => (int) ($row['totals_exempt'] ?? 0),
                'others'   => (int) ($row['totals_others'] ?? 0),
                'net'      => (int) ($row['totals_net'] ?? 0),
                'tax'      => (int) ($row['totals_tax'] ?? 0),
                'total'    => (int) ($row['totals_total'] ?? 0),
            ],
            (int) ($row['item_count'] ?? 0),
            (string) $row['created_at']
        );

        $order->id               = isset($row['id']) ? (int) $row['id'] : null;
        $order->updatedAt        = (string) ($row['updated_at'] ?? $row['created_at']);
        $order->serie            = self::nullableString($row, 'serie');
        $order->preimpreso       = self::nullableString($row, 'preimpreso');
        $order->autorizacion     = self::nullableString($row, 'autorizacion');
        $order->providerResponse = self::nullableString($row, 'provider_response');
        $order->sentXml          = self::nullableString($row, 'sent_xml');
        $order->errorMessage     = self::nullableString($row, 'error_message');
        $order->attempts         = (int) ($row['attempts'] ?? 0);
        $order->ambiguous       = (int) ($row['ambiguous'] ?? 0) === 1;
        $order->lastErrorCode   = self::nullableString($row, 'last_error_code');
        $order->annulmentReason = self::nullableString($row, 'annulment_reason');
        $order->annulmentResponse = self::nullableString($row, 'annulment_response');
        $order->annulledAt      = self::nullableString($row, 'annulled_at');
        $order->invoicedAt      = self::nullableString($row, 'invoiced_at');
        $order->responseType    = strtoupper((string) ($row['response_type'] ?? 'R'));
        if ($order->responseType !== 'R' && $order->responseType !== 'D') {
            $order->responseType = 'R';
        }

        return $order;
    }

    /**
     * Lee una columna opcional que puede venir nula.
     *
     * @param array<string, mixed> $row Fila de la base de datos.
     * @param string               $key Nombre de la columna.
     *
     * @return string|null Valor o `null` si la columna está vacía.
     */
    private static function nullableString(array $row, string $key): ?string
    {
        if (!isset($row[$key])) {
            return null;
        }

        $value = (string) $row[$key];

        return $value === '' ? null : $value;
    }

    /**
     * Devuelve la representación pública de la orden.
     *
     * `payload` no se expone: contiene los datos fiscales completos del cliente
     * y no aporta nada al POS, que ya los conoce. El XML firmado tampoco: pesa
     * decenas de kilobytes y se conserva en la base de datos.
     *
     * ## Los tres campos que el POS necesita para decidir
     *
     * | Campo          | Para qué sirve                                          |
     * |----------------|---------------------------------------------------------|
     * | `reintentable`  | Si puede reenviar la orden sin duplicar la factura.     |
     * | `ambiguo`       | Si hay que consultar al proveedor antes de reenviar.     |
     * | `codigo_error`  | Código del proveedor, para clasificar sin leer el texto. |
     *
     * Sin `reintentable` el POS tiene que deducirlo del código HTTP, y el mismo
     * `503` puede ser recuperable o no según la causa. Sin `uuid` —que sí
     * viaja— no tiene forma de consultar la orden después.
     *
     * @return array<string, mixed> Representación pública.
     */
    public function toArrayPublic(): array
    {
        return [
            'uuid'         => $this->uuid,
            'codigo_orden' => $this->orderCode,
            'estado'       => $this->status,
            'tipo_documento' => $this->dteType,
            'serie'        => $this->serie,
            'preimpreso'   => $this->preimpreso,
            'autorizacion' => $this->autorizacion,
            'documento'    => $this->serie !== null && $this->preimpreso !== null
                ? $this->serie . '-' . $this->preimpreso
                : null,
            'totales'      => $this->totalsToArray(),
            'lineas'       => $this->itemCount,
            'intentos'     => $this->attempts,
            'reintentable' => $this->isRetryable(),
            'ambiguo'      => $this->ambiguous,
            'error'        => $this->errorMessage,
            'codigo_error' => $this->lastErrorCode,
            'created_at'   => $this->createdAt,
            'updated_at'   => $this->updatedAt,
            'invoiced_at'  => $this->invoicedAt,
            'anulacion'    => $this->annulmentReason === null ? null : [
                'motivo'    => $this->annulmentReason,
                'fecha'     => $this->annulledAt,
                'respuesta' => mb_substr((string) ($this->annulmentResponse ?? ''), 0, 1000),
            ],
        ];
    }

    /**
     * Devuelve los totales como cadenas decimales.
     *
     * @return array<string, string> Totales formateados.
     */
    public function totalsToArray(): array
    {
        $keys = [
            'gross'    => 'bruto',
            'discount' => 'descuento',
            'exempt'   => 'exento',
            'others'   => 'otros',
            'net'      => 'neto',
            'tax'      => 'iva',
            'total'    => 'total',
        ];

        $totals = [];

        foreach ($keys as $field => $label) {
            // Se formatea con aritmética entera, no dividiendo en coma
            // flotante: los totales que se muestran al POS deben coincidir
            // exactamente con los que se enviaron al proveedor.
            $totals[$label] = Decimal::fromCents((int) ($this->totals[$field] ?? 0))->toString();
        }

        return $totals;
    }

    /**
     * Devuelve el identificador interno.
     *
     * @return int|null Identificador interno o `null` si no está persistido.
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * Asigna el identificador interno.
     *
     * @param int|null $id Identificador interno.
     *
     * @return void
     */
    public function setId(?int $id): void
    {
        $this->id = $id;
    }

    /**
     * Devuelve el identificador público.
     *
     * @return string UUID v4 de la operación.
     */
    public function getUuid(): string
    {
        return $this->uuid;
    }

    /**
     * Devuelve el código único de la orden del POS.
     *
     * @return string Código de la orden.
     */
    public function getOrderCode(): string
    {
        return $this->orderCode;
    }

    /**
     * Devuelve el tipo de documento.
     *
     * @return int Código numérico del tipo de DTE.
     */
    public function getDteType(): int
    {
        return $this->dteType;
    }

    /**
     * Devuelve el modo de respuesta solicitado al proveedor.
     *
     * @return string `R` (resumen) o `D` (detalle).
     */
    public function getResponseType(): string
    {
        return $this->responseType;
    }

    /**
     * Devuelve el estado actual.
     *
     * @return string Estado de la operación.
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * Devuelve la serie autorizada del documento.
     *
     * @return string|null Serie o `null` si la orden no está facturada.
     */
    public function getSerie(): ?string
    {
        return $this->serie;
    }

    /**
     * Devuelve el número correlativo autorizado.
     *
     * @return string|null Preimpreso o `null` si la orden no está facturada.
     */
    public function getPreimpreso(): ?string
    {
        return $this->preimpreso;
    }

    /**
     * Devuelve la marca de la última actualización, en UTC.
     *
     * Es la referencia desde la que se cuenta la espera antes de un reintento,
     * porque es el momento en que se registró el último desenlace conocido.
     *
     * @return string Fecha y hora en `YYYY-MM-DD HH:MM:SS` (UTC).
     */
    public function getUpdatedAt(): string
    {
        return $this->updatedAt;
    }

    /**
     * Devuelve la copia normalizada de la orden.
     *
     * @return array<string, mixed> Orden en su forma interna.
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    /**
     * Devuelve el número de intentos de emisión realizados.
     *
     * @return int Número de intentos.
     */
    public function getAttempts(): int
    {
        return $this->attempts;
    }

    /**
     * Devuelve el XML exacto enviado al proveedor.
     *
     * @return string|null XML enviado o `null` si aún no se ha enviado.
     */
    public function getSentXml(): ?string
    {
        return $this->sentXml;
    }

    /**
     * Devuelve el texto íntegro devuelto por el proveedor.
     *
     * @return string|null Respuesta o `null` si no hay ninguna.
     */
    public function getProviderResponse(): ?string
    {
        return $this->providerResponse;
    }

    /**
     * Indica si la orden ya alcanzó un estado terminal.
     *
     * @return bool `true` si ya no debe reintentarse.
     */
    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATES, true);
    }

    /**
     * Indica si el POS puede reenviar la orden sin duplicar la factura.
     *
     * ## Por qué todos los fallos son reintentables
     *
     * También lo es `ERROR_PROVEEDOR`, que parece un rechazo definitivo. Reenviar
     * el mismo XML con la misma `Referencia` no genera una segunda factura: el
     * proveedor reconoce la referencia y devuelve el documento ya emitido, o
     * vuelve a rechazarlo por la misma causa. El peor caso de un reintento
     * innecesario es una respuesta igual de negativa; nunca una factura doble.
     *
     * Lo que el reintento no arregla es la causa, y por eso la orden conserva el
     * mensaje del proveedor y su código de error.
     *
     * @return bool `true` si puede reenviarse.
     */
    public function isRetryable(): bool
    {
        return in_array($this->status, self::RETRYABLE_STATES, true);
    }

    /**
     * Indica si el último fallo deja duda sobre si se emitió el documento.
     *
     * Cuando es `true`, reenviar es seguro, pero lo prudente es consultar al
     * proveedor con la `Referencia` antes de hacerlo (HU-4).
     *
     * @return bool `true` si no puede afirmarse que el documento no se emitió.
     */
    public function isAmbiguous(): bool
    {
        return $this->ambiguous;
    }

    /**
     * Devuelve el código de error del proveedor en el último fallo.
     *
     * @return string|null Código o `null` si no hay ninguno.
     */
    public function getLastErrorCode(): ?string
    {
        return $this->lastErrorCode;
    }

    /**
     * Marca el desenlace del último intento de emisión.
     *
     * @param string      $status   Estado a persistir.
     * @param string|null $message  Mensaje del fallo, o `null` si no hubo.
     * @param bool        $ambiguous Si no puede afirmarse que no se emitió.
     * @param string|null $code     Código de error del proveedor.
     *
     * @return void
     */
    public function setOutcome(
        string $status,
        ?string $message,
        bool $ambiguous,
        ?string $code = null
    ): void {
        $this->status        = $status;
        $this->errorMessage  = $message;
        $this->ambiguous     = $ambiguous;
        $this->lastErrorCode = $code;
    }

    /**
     * Indica si la orden lleva demasiado tiempo en `EN_PROCESO`.
     *
     * ## Por qué importa
     *
     * El estado `EN_PROCESO` se escribe antes de salir a la red y se sustituye
     * al registrar la respuesta. Si el proceso muere entre ambas operaciones
     * —un `max_execution_time`, un apagón del servidor— la orden queda
     * bloqueada en `EN_PROCESO` para siempre, y cualquier reintento del POS
     * recibiría un `409` sin ninguna vía de salida. La orden no se bloquea: se
     * puede volver a emitir porque su `Referencia` es la misma.
     *
     * @param int $timeoutSeconds Antigüedad a partir de la cual se considera
     *                            atascada.
     *
     * @return bool `true` si lleva en `EN_PROCESO` más que el tiempo indicado.
     */
    public function isStuckProcessing(int $timeoutSeconds): bool
    {
        if ($this->status !== self::STATUS_PROCESSING || $timeoutSeconds <= 0) {
            return false;
        }

        $deadline = strtotime($this->updatedAt . ' UTC');
        $now      = time();

        return $deadline !== false && ($now - $deadline) >= $timeoutSeconds;
    }
}
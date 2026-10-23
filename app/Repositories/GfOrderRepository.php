<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Integrations\Guatefacturas\Decimal;
use App\Integrations\Guatefacturas\ProviderResponseClassifier;
use App\Models\GfOrder;

/**
 * Repositorio de persistencia de las órdenes de facturación (`gf_orders`).
 *
 * TODAS las consultas usan *prepared statements* con marcadores nombrados y
 * `PDO::ATTR_EMULATE_PREPARES => false`. Ningún valor de la petición se
 * concatena nunca en el SQL, ni siquiera el código de la orden: un código de
 * pedido contiene texto libre y terminaría en el documento fiscal.
 *
 * @package App\Repositories
 *
 * @author  fel-dentall
 * @license MIT
 */
final class GfOrderRepository
{
    /**
     * Columnas públicas de la tabla `gf_orders`.
     *
     * @var string
     */
    private const PUBLIC_COLUMNS = 'id, uuid, order_code, dte_type, status, payload,
        totals_gross, totals_discount, totals_exempt, totals_others, totals_net,
        totals_tax, totals_total, item_count, serie, preimpreso, autorizacion,
        provider_response, sent_xml, error_message, ambiguous, last_error_code,
        attempts, created_at, updated_at, invoiced_at, annulment_reason,
        annulment_response, annulled_at, response_type';

    /**
     * Fábrica de conexiones.
     *
     * @var Database
     */
    private $db;

    /**
     * @param Database $db Fábrica de conexiones PDO.
     */
    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Registra una orden recibida y validada, en estado `PENDIENTE`.
     *
     * @param GfOrder $order Orden a persistir.
     *
     * @return GfOrder Orden persistida, con su identificador interno asignado.
     *
     * @throws \PDOException Si la inserción falla.
     */
    public function create(GfOrder $order): GfOrder
    {
        $payload = json_encode(
            $order->getPayload(),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if ($payload === false) {
            throw new \PDOException('No se pudo serializar la orden para guardarla.');
        }

        $totals = $order->totalsToArray();

        $sql = 'INSERT INTO gf_orders (
                    uuid, order_code, dte_type, status, payload,
                    totals_gross, totals_discount, totals_exempt, totals_others,
                    totals_net, totals_tax, totals_total, item_count,
                    attempts, response_type, created_at, updated_at
                ) VALUES (
                    :uuid, :order_code, :dte_type, :status, :payload,
                    :totals_gross, :totals_discount, :totals_exempt, :totals_others,
                    :totals_net, :totals_tax, :totals_total, :item_count,
                    :attempts, :response_type, :created_at, :updated_at
                )';

        $this->db->getConnection()->prepare($sql)->execute([
            ':uuid'           => $order->getUuid(),
            ':order_code'     => $order->getOrderCode(),
            ':dte_type'       => $order->getDteType(),
            ':status'         => $order->getStatus(),
            ':payload'        => $payload,
            ':totals_gross'   => self::centsOf($totals, 'bruto'),
            ':totals_discount' => self::centsOf($totals, 'descuento'),
            ':totals_exempt'  => self::centsOf($totals, 'exento'),
            ':totals_others'  => self::centsOf($totals, 'otros'),
            ':totals_net'     => self::centsOf($totals, 'neto'),
            ':totals_tax'     => self::centsOf($totals, 'iva'),
            ':totals_total'   => self::centsOf($totals, 'total'),
            ':item_count'     => $order->toArrayPublic()['lineas'],
            ':attempts'       => $order->getAttempts(),
            ':response_type'  => $order->getResponseType(),
            ':created_at'     => gmdate('Y-m-d H:i:s'),
            ':updated_at'     => gmdate('Y-m-d H:i:s'),
        ]);

        $created = $this->findByUuid($order->getUuid());

        if ($created === null) {
            throw new \PDOException('La orden se insertó pero no pudo releerse.');
        }

        return $created;
    }

    /**
     * Busca una orden por su identificador público.
     *
     * @param string $uuid Identificador público UUID v4.
     *
     * @return GfOrder|null Entidad o `null` si no existe.
     */
    public function findByUuid(string $uuid): ?GfOrder
    {
        $sql = sprintf('SELECT %s FROM gf_orders WHERE uuid = :uuid LIMIT 1', self::PUBLIC_COLUMNS);

        $statement = $this->db->getConnection()->prepare($sql);
        $statement->execute([':uuid' => $uuid]);

        $row = $statement->fetch();

        return is_array($row) ? GfOrder::fromDb($row) : null;
    }

    /**
     * Busca una orden por el código único del POS.
     *
     * Este método sostiene la idempotencia: antes de emitir, la API comprueba
     * si el código ya tiene una factura.
     *
     * @param string $orderCode Código único de la orden.
     *
     * @return GfOrder|null Entidad o `null` si no existe.
     */
    public function findByOrderCode(string $orderCode): ?GfOrder
    {
        $sql = sprintf('SELECT %s FROM gf_orders WHERE order_code = :code LIMIT 1', self::PUBLIC_COLUMNS);

        $statement = $this->db->getConnection()->prepare($sql);
        $statement->execute([':code' => $orderCode]);

        $row = $statement->fetch();

        return is_array($row) ? GfOrder::fromDb($row) : null;
    }

    /**
     * Marca una orden como enviada al proveedor, sin respuesta todavía.
     *
     * @param string  $uuid   Identificador público de la orden.
     * @param string  $xml    XML exacto que se envió.
     * @param int     $attempts Número de intentos acumulados.
     *
     * @return bool `true` si se actualizó alguna fila.
     *
     * @throws \PDOException Si la actualización falla.
     */
    public function markAsSent(string $uuid, string $xml, int $attempts): bool
    {
        $sql = 'UPDATE gf_orders
                SET status = :status, sent_xml = :sent_xml, attempts = :attempts, updated_at = :updated_at
                WHERE uuid = :uuid';

        $statement = $this->db->getConnection()->prepare($sql);
        $statement->execute([
            ':status'     => GfOrder::STATUS_PROCESSING,
            ':sent_xml'   => $xml,
            ':attempts'   => $attempts,
            ':updated_at' => gmdate('Y-m-d H:i:s'),
            ':uuid'       => $uuid,
        ]);

        return $statement->rowCount() > 0;
    }

    /**
     * Registra la autorización de SAT y marca la orden como `FACTURADO`.
     *
     * @param string $uuid          Identificador público de la orden.
     * @param string $serie         Serie autorizada.
     * @param string $preimpreso    Número correlativo autorizado.
     * @param string $autorizacion  Número de autorización de SAT.
     * @param string $rawResponse   Texto íntegro devuelto por el proveedor.
     *
     * @return bool `true` si se actualizó alguna fila.
     *
     * @throws \PDOException Si la actualización falla.
     */
    public function markAsInvoiced(
        string $uuid,
        string $serie,
        string $preimpreso,
        string $autorizacion,
        string $rawResponse
    ): bool {
        $sql = 'UPDATE gf_orders
                SET status = :status, serie = :serie, preimpreso = :preimpreso,
                    autorizacion = :autorizacion, provider_response = :provider_response,
                    error_message = NULL, ambiguous = 0, last_error_code = NULL,
                    invoiced_at = :invoiced_at, updated_at = :updated_at
                WHERE uuid = :uuid';

        $statement = $this->db->getConnection()->prepare($sql);
        $statement->execute([
            ':status'           => GfOrder::STATUS_INVOICED,
            ':serie'            => $serie,
            ':preimpreso'       => $preimpreso,
            ':autorizacion'     => $autorizacion,
            ':provider_response' => $rawResponse,
            ':invoiced_at'      => gmdate('Y-m-d H:i:s'),
            ':updated_at'       => gmdate('Y-m-d H:i:s'),
            ':uuid'             => $uuid,
        ]);

        return $statement->rowCount() > 0;
    }

    /**
     * Registra el fallo y deja la orden en el estado que indique el servicio.
     *
     * ## Por qué el estado no se deduce de la ambigüedad
     *
     * Hay tres desenlaces distintos y dos no se deducen del mismo dato:
     *
     * - `ERROR_PROVEEDOR`: el proveedor respondió y rechazó. Los datos están mal.
     * - `PENDIENTE_REINTENTO` y ambiguo: no sabemos si emitió. Reenviar es seguro.
     * - `PENDIENTE_REINTENTO` y no ambiguo: nunca llegó a emitir porque faltó
     *   la configuración. El fallo es nuestro, no del documento.
     *
     * Los dos últimos comparten estado y se distinguen por `ambiguous`, así que
     * el estado no basta para deducirse. Lo decide quien conoce la causa: el
     * servicio.
     *
     * El mensaje se acota porque un error con el XML firmado dentro llenaría el
     * log; el texto íntegro se conserva en la respuesta del proveedor cuando
     * existe, y la referencia de la orden permite localizar el documento.
     *
     * @param string      $uuid      Identificador público de la orden.
     * @param string      $status    Estado a persistir.
     * @param string      $message   Error a registrar.
     * @param bool        $ambiguous Si no puede afirmarse que no se emitió.
     * @param string|null $code      Código de error del proveedor.
     * @param string|null $rawResponse Respuesta íntegra del proveedor, si la hubo.
     *
     * @return bool `true` si se actualizó alguna fila.
     *
     * @throws \PDOException Si la actualización falla.
     */
    public function markAsFailed(
        string $uuid,
        string $status,
        string $message,
        bool $ambiguous,
        ?string $code = null,
        ?string $rawResponse = null
    ): bool {
        $sql = 'UPDATE gf_orders
                SET status = :status, error_message = :error_message,
                    ambiguous = :ambiguous, last_error_code = :last_error_code,
                    updated_at = :updated_at';

        $params = [
            ':status'          => $status,
            ':error_message'   => mb_substr($message, 0, 500, 'UTF-8'),
            ':ambiguous'       => $ambiguous ? 1 : 0,
            ':last_error_code' => $code,
            ':updated_at'      => gmdate('Y-m-d H:i:s'),
            ':uuid'            => $uuid,
        ];

        if ($rawResponse !== null) {
            $sql .= ', provider_response = :provider_response';
            $params[':provider_response'] = $rawResponse;
        }

        $sql .= ' WHERE uuid = :uuid';

        $statement = $this->db->getConnection()->prepare($sql);

        return $statement->execute($params) && $statement->rowCount() > 0;
    }

    /**
     * Registra la anulación de la factura y marca la orden como `ANULADO`.
     *
     * La fecha se guarda en UTC, igual que el resto de marcas de tiempo de la tabla.
     * El valor que se envió al proveedor es la fecha local del emisor en `AAAAMMDD`,
     * que no se almacena por ser derivable.
     *
     * @param string $uuid        Identificador público de la orden.
     * @param string $reason      Motivo de la anulación.
     * @param string $rawResponse Respuesta íntegra del proveedor.
     *
     * @return bool `true` si se actualizó alguna fila.
     *
     * @throws \PDOException Si la actualización falla.
     */
    public function markAsCancelled(string $uuid, string $reason, string $rawResponse): bool
    {
        $sql = 'UPDATE gf_orders
                SET status = :status, annulment_reason = :reason,
                    annulment_response = :response, annulled_at = :annulled_at,
                    updated_at = :updated_at
                WHERE uuid = :uuid';

        $statement = $this->db->getConnection()->prepare($sql);
        $statement->execute([
            ':status'      => GfOrder::STATUS_CANCELLED,
            ':reason'      => mb_substr($reason, 0, 500, 'UTF-8'),
            ':response'    => $rawResponse,
            ':annulled_at' => gmdate('Y-m-d H:i:s'),
            ':updated_at'  => gmdate('Y-m-d H:i:s'),
            ':uuid'        => $uuid,
        ]);

        return $statement->rowCount() > 0;
    }

    /**
     * Registra que el proveedor rechazó la anulación.
     *
     * No cambia el estado: la factura sigue autorizada. El error queda en el registro
     * para el seguimiento, pero marcar la orden como `ANULADO` sería afirmar que una
     * factura dejó de estar vigente cuando no lo está.
     *
     * @param string $uuid        Identificador público de la orden.
     * @param string $message     Motivo del rechazo.
     * @param string $rawResponse Respuesta íntegra del proveedor.
     *
     * @return bool `true` si se actualizó alguna fila.
     *
     * @throws \PDOException Si la actualización falla.
     */
    public function markAsAnnulmentRejected(string $uuid, string $message, ?string $rawResponse): bool
    {
        $sql = 'UPDATE gf_orders
                SET error_message = :error_message, last_error_code = :code,
                    updated_at = :updated_at';

        $params = [
            ':error_message' => mb_substr($message, 0, 500, 'UTF-8'),
            ':code'          => ProviderResponseClassifier::errorCode((string) $rawResponse),
            ':updated_at'    => gmdate('Y-m-d H:i:s'),
            ':uuid'          => $uuid,
        ];

        if ($rawResponse !== null) {
            $sql .= ', annulment_response = :response';
            $params[':response'] = $rawResponse;
        }

        $sql .= ' WHERE uuid = :uuid';

        $statement = $this->db->getConnection()->prepare($sql);

        return $statement->execute($params) && $statement->rowCount() > 0;
    }

    /**
     * Registra que la anulación no pudo completarse por un fallo técnico.
     *
     * Igual que un rechazo, no altera el estado: la factura continúa vigente. La
     * orden queda marcada como ambigua respecto de la anulación, no de la emisión.
     *
     * @param string $uuid    Identificador público de la orden.
     * @param string $message Detalle del fallo.
     *
     * @return bool `true` si se actualizó alguna fila.
     *
     * @throws \PDOException Si la actualización falla.
     */
    public function markAsAnnulmentFailed(string $uuid, string $message): bool
    {
        $sql = 'UPDATE gf_orders
                SET error_message = :error_message, updated_at = :updated_at
                WHERE uuid = :uuid';

        $statement = $this->db->getConnection()->prepare($sql);
        $statement->execute([
            ':error_message' => mb_substr($message, 0, 500, 'UTF-8'),
            ':updated_at'    => gmdate('Y-m-d H:i:s'),
            ':uuid'          => $uuid,
        ]);

        return $statement->rowCount() > 0;
    }

    /**
     * Elimina todas las órdenes. Reservado para las pruebas funcionales.
     *
     * @return void
     *
     * @throws \PDOException Si el borrado falla.
     */
    public function truncate(): void
    {
        $this->db->getConnection()->exec('DELETE FROM gf_orders');
    }

    /**
     * Convierte un importe decimal en centavos enteros.
     *
     * @param array<string, string> $totals Totales formateados.
     * @param string                $key    Clave del total a convertir.
     *
     * @return int Cantidad de centavos.
     */
    private static function centsOf(array $totals, string $key): int
    {
        return (int) Decimal::parse((string) ($totals[$key] ?? '0.00'), 2, $key)->cents();
    }
}
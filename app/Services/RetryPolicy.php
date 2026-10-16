<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\GfOrder;

/**
 * Política de reintentos ante un desenlace incierto.
 *
 * ## Por qué una clase propia
 *
 * La decisión de «¿puedo reintentar esto y cuándo?» tiene tres entradas —el estado
 * de la orden, su número de intentos y su última actualización— y gobierna tres
 * salidas distintas: dejar pasar el reintento, rechazarlo por demasiado pronto, o
 * rechazarlo porque ya no procede. Mezclarla dentro del servicio la haría
 * imposible de probar sin base de datos y difícil de leer.
 *
 * Es además el sitio donde vive la espera progresiva, que no es un detalle
 * cosmético: el entorno de pruebas **rechaza con el error 82 los documentos
 * válidos que llegan encadenados**, de modo que un reintento inmediato consume una
 * emisión y vuelve a fallar por un motivo que no tiene que ver con el documento.
 *
 * @package App\Services
 *
 * @author  fel-dentall
 * @license MIT
 */
final class RetryPolicy
{
    /**
     * Espera en segundos según el número de intentos ya realizados.
     *
     * El primer reintento espera un minuto; a partir de ahí la espera se duplica
     * hasta el tope de diez minutos. La progresión refleja que los primeros
     * reintentos suelen resolver una caída puntual del proveedor, mientras que un
* fallo que persiste ya no es transitorio y conviene no insistir consumiendo
 * emisiones.
     *
     * @var array<int, int>
     */
    private const BACKOFF = [60, 120, 300, 600];

    /**
     * Espera máxima aplicada cuando se agotan los pasos de la progresión.
     *
     * @var int
     */
    private const BACKOFF_MAX = 600;

    /**
     * Número máximo de emisiones por orden.
     *
     * @var int
     */
    private $maxAttempts;

    /**
     * Antigüedad a partir de la cual una orden en curso se considera atascada.
     *
     * @var int
     */
    private $processingTimeout;

    /**
     * @param int $maxAttempts        Tope de emisiones por orden.
     * @param int $processingTimeout  Antigüedad de una orden atascada, en segundos.
     */
    public function __construct(int $maxAttempts = 5, int $processingTimeout = 300)
    {
        $this->maxAttempts       = $maxAttempts > 0 ? $maxAttempts : 1;
        $this->processingTimeout = $processingTimeout;
    }

    /**
     * Crea la política desde la configuración de facturación.
     *
     * @param array<string, mixed> $rules Reglas de facturación del entorno.
     *
     * @return self Política configurada.
     */
    public static function fromRules(array $rules): self
    {
        return new self(
            (int) ($rules['max_attempts'] ?? 5),
            (int) ($rules['processing_timeout'] ?? 300)
        );
    }

    /**
     * Describe si la orden admite reintento y con qué espera.
     *
     * ## Los tres motivos de rechazo
     *
     * | Motivo                            | Dónde se comprueba |
     * |-----------------------------------|--------------------|
     * | Ya está resuelta                  | {@see self::blocks()} |
     * | Todavía en curso                  | {@see self::blocks()} |
     * | Agotó los intentos                | {@see self::blocks()} |
     * | Todavía no se puede por la espera | {@see self::mayBeTooSoon()} |
     *
     * Los tres primeros son permanentes o dependen de que termine la emisión en
     * curso; solo la espera puede desbloquear la orden por sí sola.
     *
     * @param GfOrder $order  Orden a evaluar.
     * @param int|null $now   Instante actual en Unix; `null` usa el reloj del sistema.
     *
     * @return array{
     *     permitido: bool, motivo: string|null, esperar_segundos: int,
     *     intentos_restantes: int, ultimo_intento: bool
     * } Situación de la orden frente a la política.
     */
    public function evaluate(GfOrder $order, ?int $now = null): array
    {
        $now      = $now ?? time();
        $attempts = $order->getAttempts();

        $attemptsLeft = max(0, $this->maxAttempts - $attempts);

        $situation = [
            'permitido'          => true,
            'motivo'             => null,
            'esperar_segundos'   => 0,
            'intentos_restantes' => $attemptsLeft,
            'ultimo_intento'     => $attemptsLeft <= 1,
        ];

        $block = $this->blocks($order);

        if ($block !== null) {
            $situation['permitido'] = false;
            $situation['motivo']    = $block;

            return $situation;
        }

        $wait = $this->remainingWait($order, $now);

        if ($wait > 0) {
            $situation['permitido']        = false;
            $situation['motivo']           = 'demasiado_pronto';
            $situation['esperar_segundos'] = $wait;
        }

        return $situation;
    }

    /**
     * Calcula los segundos que faltan para poder reintentar.
     *
     * La espera se mide desde la última actualización de la orden, que es el
     * momento en que se registró el fallo. Medirla desde el primer envío daría por
     * satisfecha una espera que en realidad no ha empezado.
     *
     * @param GfOrder $order Orden a evaluar.
     * @param int     $now   Instante actual en Unix.
     *
     * @return int Segundos que faltan, o `0` si ya se puede reintentar.
     */
    public function remainingWait(GfOrder $order, int $now): int
    {
        $required = $this->requiredWait($order->getAttempts());

        if ($required <= 0) {
            return 0;
        }

        $lastUpdate = strtotime($order->getUpdatedAt() . ' UTC');

        if ($lastUpdate === false) {
            // Una orden sin marca de tiempo legible no debe quedar bloqueada para
            // siempre: se permite reintentar y que el proveedor decida.
            return 0;
        }

        $elapsed = $now - $lastUpdate;

        return $elapsed >= $required ? 0 : $required - $elapsed;
    }

    /**
     * Devuelve la espera exigida según el número de intentos ya realizados.
     *
     * @param int $attempts Intentos ya realizados.
     *
     * @return int Espera requerida en segundos.
     */
    public function requiredWait(int $attempts): int
    {
        $index = $attempts - 1;

        if ($index < 0) {
            return self::BACKOFF[0];
        }

        return isset(self::BACKOFF[$index]) ? self::BACKOFF[$index] : self::BACKOFF_MAX;
    }

    /**
     * Explica por qué una orden no debe reintentarse, o `null` si puede.
     *
     * @param GfOrder $order Orden a evaluar.
     *
     * @return string|null Motivo del bloqueo, o `null` si no hay bloqueo.
     */
    public function blocks(GfOrder $order): ?string
    {
        if ($order->getStatus() === GfOrder::STATUS_INVOICED) {
            return 'ya_facturada';
        }

        if ($order->getStatus() === GfOrder::STATUS_CANCELLED) {
            return 'anulada';
        }

        if ($order->getStatus() === GfOrder::STATUS_PROCESSING
            && !$order->isStuckProcessing($this->processingTimeout)
        ) {
            return 'en_curso';
        }

        if ($order->getStatus() === GfOrder::STATUS_PENDING) {
            // Nunca salió hacia el proveedor: no hay documento que reenviar.
            return 'nunca_enviada';
        }

        if ($order->getAttempts() >= $this->maxAttempts) {
            return 'intentos_agotados';
        }

        return null;
    }

    /**
     * Devuelve el número máximo de emisiones por orden.
     *
     * @return int Tope de intentos.
     */
    public function maxAttempts(): int
    {
        return $this->maxAttempts;
    }

    /**
     * Devuelve la antigüedad a partir de la cual una orden en curso se considera
     * atascada.
     *
     * @return int Segundos de antigüedad.
     */
    public function processingTimeout(): int
    {
        return $this->processingTimeout;
    }
}
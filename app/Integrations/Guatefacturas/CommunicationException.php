<?php

declare(strict_types=1);

namespace App\Integrations\Guatefacturas;

/**
 * Fallo de comunicación con el proveedor.
 *
 * Se divide en dos categorías porque el tratamiento posterior es distinto:
 *
 * - **Pérdida de conexión** (`kind = 'connection'`): la petición no llegó al
 *   proveedor, con certeza no emitió nada.
 * - **Resultado incierto** (`kind = 'timeout'`): la petición pudo procesarse y
 *   la respuesta perderse. El documento puede existir aunque no lo sepamos.
 *
 * La segunda categoría es la que obliga a reintentar con la misma `Referencia`,
 * que el proveedor reconoce y no duplica.
 *
 * @package App\Integrations\Guatefacturas
 *
 * @author  fel-dentall
 * @license MIT
 */
final class CommunicationException extends GuatefacturasException
{
    /**
     * La petición no alcanzó al proveedor.
     *
     * @var string
     */
    public const KIND_CONNECTION = 'connection';

    /**
     * El resultado es incierto: puede haber documento emitido.
     *
     * @var string
     */
    public const KIND_TIMEOUT = 'timeout';

    /**
     * El proveedor respondió rechazando por límite de emisiones.
     *
     * No se sabe si el documento se emitió. El sandbox devuelve
     * `82-ERROR CALCULO DE IMPORTE BRUTO INCORRECTO` tanto ante un importe
     * realmente incorrecto como al superar el límite de emisiones del periodo,
     * y ambas causas son indistinguibles por el mensaje. Se clasifica aparte
     * del tiempo de espera agotado porque la respuesta **sí** llegó: lo que se
     * perdió fue la confirmación, no la conversación.
     *
     * @var string
     */
    public const KIND_RATE_LIMITED = 'rate_limit';

    /**
     * Categoría del fallo.
     *
     * @var string
     */
    private $kind;

    /**
     * @param string $kind Categoría del fallo.
     * @param string $detalle Detalle técnico ya saneado.
     */
    public function __construct(string $kind, string $detalle)
    {
        $this->kind = $kind;

        parent::__construct($detalle);
    }

    /**
     * Devuelve la categoría del fallo.
     *
     * @return string Una de las constantes `KIND_*`.
     */
    public function kind(): string
    {
        return $this->kind;
    }

    /**
     * Indica si el resultado es incierto.
     *
     * @return bool `true` si puede haber documento emitido sin conocerlo.
     */
    public function isAmbiguous(): bool
    {
        return $this->kind === self::KIND_TIMEOUT
            || $this->kind === self::KIND_RATE_LIMITED;
    }
}
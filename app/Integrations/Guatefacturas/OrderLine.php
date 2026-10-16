<?php

declare(strict_types=1);

namespace App\Integrations\Guatefacturas;

use InvalidArgumentException;

/**
 * Línea del carrito, con sus importes ya calculados.
 *
 * ## Fórmula de la línea
 *
 * ```
 * ImpBruto      = Cantidad × PrecioUnitario
 * ImpDescuento  = ImpBruto × PorcDescuento / 100
 * ImpOtros      = (ImpBruto − ImpDescuento) × PorcOtros / 100
 * ImpNeto       = ImpBruto − ImpDescuento − ImpOtros
 * ImpExento     = ImpNeto, si la línea es exenta; 0 en caso contrario
 * ImpIva        = (ImpNeto − ImpExento) × Tasa / 100
 * ImpTotal      = ImpNeto + ImpIva
 * ```
 *
 * ## Estos importes son la base NETA, no lo que se envía
 *
 * `PrecioUnitario` es el precio que manda el POS, **sin IVA**, y sobre él se
 * calculan estos importes. Son los importes de negocio: los que se guardan en la
 * base de datos y los que se devuelven al POS.
 *
 * El documento que viaja al proveedor los expresa de otra forma: el precio
 * unitario y el bruto van **con el IVA incluido**, que es lo que exige la guía
 * operativa. La conversión vive en {@see ImportesProveedor}, que documenta la regla
 * con el ejemplo del manual y explica por qué el neto y el total no cambian.
 *
 * El precio del POS sigue siendo sin IVA. Es una decisión de contrato, no un
 * requisito del proveedor: el POS manda el importe que cobra a la clínica y es la
 * API la que conoce la convención del certificador. Exigir al POS que multiplique
 * por `1.12` trasladaría al cliente un detalle de integración y abriría la puerta
 * a que alguien lo haga dos veces.
 *
 * @package App\Integrations\Guatefacturas
 *
 * @author  fel-dentall
 * @license MIT
 */
final class OrderLine
{
    /**
     * Código del producto o servicio.
     *
     * @var string
     */
    private $code;

    /**
     * Descripción legible de la línea.
     *
     * @var string
     */
    private $description;

    /**
     * Cantidad, en escala {@see Decimal::SCALE_QUANTITY}.
     *
     * @var Decimal
     */
    private $quantity;

    /**
     * Precio unitario SIN IVA, en escala {@see Decimal::SCALE_MONEY}.
     *
     * @var Decimal
     */
    private $unitPrice;

    /**
     * Porcentaje de descuento de la línea.
     *
     * @var Decimal
     */
    private $discountPercent;

    /**
     * Porcentaje de retención de ISR (`ImpOtros`).
     *
     * @var Decimal
     */
    private $otherPercent;

    /**
     * Porcentaje de IVA aplicado a la base imponible de la línea.
     *
     * @var Decimal
     */
    private $taxPercent;

    /**
     * Indica si la línea está exenta de IVA.
     *
     * @var bool
     */
    private $exempt;

    /**
     * `Cantidad × PrecioUnitario`.
     *
     * @var Decimal
     */
    private $gross;

    /**
     * Descuento aplicado a la línea.
     *
     * @var Decimal
     */
    private $discountAmount;

    /**
     * Retención de ISR aplicada a la línea.
     *
     * @var Decimal
     */
    private $otherAmount;

    /**
     * Parte exenta de la línea.
     *
     * @var Decimal
     */
    private $exemptAmount;

    /**
     * Base imponible de la línea.
     *
     * @var Decimal
     */
    private $net;

    /**
     * IVA de la línea.
     *
     * @var Decimal
     */
    private $tax;

    /**
     * Importe total de la línea.
     *
     * @var Decimal
     */
    private $total;

    /**
     * Construye la línea y calcula sus importes.
     *
     * @param string  $code            Código del producto o servicio.
     * @param string  $description     Descripción legible.
     * @param Decimal $quantity        Cantidad (escala 3).
     * @param Decimal $unitPrice       Precio unitario sin IVA (escala 2).
     * @param Decimal $discountPercent Descuento en porcentaje (escala 4).
     * @param Decimal $otherPercent    Retención de ISR en porcentaje (escala 4).
     * @param Decimal $taxPercent      IVA en porcentaje (escala 2).
     * @param bool    $exempt          Si la línea está exenta de IVA.
     *
     * @throws InvalidArgumentException Si algún valor es negativo.
     */
    public function __construct(
        string $code,
        string $description,
        Decimal $quantity,
        Decimal $unitPrice,
        Decimal $discountPercent,
        Decimal $otherPercent,
        Decimal $taxPercent,
        bool $exempt
    ) {
        foreach (['cantidad' => $quantity, 'precio' => $unitPrice] as $label => $value) {
            if ($value->isNegative()) {
                throw new InvalidArgumentException(sprintf(
                    'El %s de la línea "%s" no puede ser negativo.',
                    $label,
                    $code
                ));
            }
        }

        foreach ([
            'descuento' => $discountPercent,
            'retencion' => $otherPercent,
            'iva'       => $taxPercent,
        ] as $label => $value) {
            if ($value->isNegative()) {
                throw new InvalidArgumentException(sprintf(
                    'El porcentaje de %s de la línea "%s" no puede ser negativo.',
                    $label,
                    $code
                ));
            }
        }

        $this->code            = $code;
        $this->description     = $description;
        $this->quantity        = $quantity;
        $this->unitPrice       = $unitPrice;
        $this->discountPercent = $discountPercent;
        $this->otherPercent    = $otherPercent;
        $this->taxPercent      = $taxPercent;
        $this->exempt          = $exempt;

        $this->calculate();
    }

    /**
     * Calcula todos los importes de la línea a partir del precio y los
     * porcentajes.
     *
     * @return void
     */
    private function calculate(): void
    {
        $zero = Decimal::zero(Decimal::SCALE_MONEY);

        // La cantidad tiene escala 3 y el precio escala 2; el resultado es dinero.
        $this->gross = $this->quantity->multiply($this->unitPrice, Decimal::SCALE_MONEY);

        $this->discountAmount = $this->gross->percentOf($this->discountPercent);

        // El ISR se retiene sobre el importe ya descontado.
        $taxableForOthers = $this->gross->minus($this->discountAmount);

        $this->otherAmount = $taxableForOthers->percentOf($this->otherPercent);

        $this->net = $this->gross
            ->minus($this->discountAmount)
            ->minus($this->otherAmount);

        // Una línea exente lo es en su totalidad: el SAT no admite exención
        // parcial dentro de una misma línea, de modo que quien necesita partly
        // exento debe dividir el concepto en dos líneas.
        $this->exemptAmount = $this->exempt ? $this->net : $zero;

        $this->tax = $this->net
            ->minus($this->exemptAmount)
            ->percentOf($this->taxPercent);

        $this->total = $this->net->plus($this->tax);
    }

    /**
     * Devuelve el código del producto o servicio.
     *
     * @return string Código de la línea.
     */
    public function code(): string
    {
        return $this->code;
    }

    /**
     * Devuelve la descripción de la línea.
     *
     * @return string Descripción legible.
     */
    public function description(): string
    {
        return $this->description;
    }

    /**
     * Devuelve la cantidad.
     *
     * @return Decimal Cantidad en escala 3.
     */
    public function quantity(): Decimal
    {
        return $this->quantity;
    }

    /**
     * Devuelve el precio unitario sin IVA.
     *
     * @return Decimal Precio en escala 2.
     */
    public function unitPrice(): Decimal
    {
        return $this->unitPrice;
    }

    /**
     * Devuelve el porcentaje de descuento.
     *
     * @return Decimal Porcentaje en escala 4.
     */
    public function discountPercent(): Decimal
    {
        return $this->discountPercent;
    }

    /**
     * Devuelve el porcentaje de retención de ISR.
     *
     * @return Decimal Porcentaje en escala 4.
     */
    public function otherPercent(): Decimal
    {
        return $this->otherPercent;
    }

    /**
     * Devuelve el porcentaje de IVA aplicado.
     *
     * @return Decimal Porcentaje en escala 2.
     */
    public function taxPercent(): Decimal
    {
        return $this->taxPercent;
    }

    /**
     * Devuelve el bruto de la línea.
     *
     * @return Decimal Importe bruto.
     */
    public function gross(): Decimal
    {
        return $this->gross;
    }

    /**
     * Devuelve el descuento de la línea.
     *
     * @return Decimal Importe del descuento.
     */
    public function discountAmount(): Decimal
    {
        return $this->discountAmount;
    }

    /**
     * Devuelve la retención de ISR de la línea.
     *
     * @return Decimal Importe retenido.
     */
    public function otherAmount(): Decimal
    {
        return $this->otherAmount;
    }

    /**
     * Devuelve la parte exenta de la línea.
     *
     * @return Decimal Importe exento.
     */
    public function exemptAmount(): Decimal
    {
        return $this->exemptAmount;
    }

    /**
     * Devuelve la base imponible de la línea.
     *
     * @return Decimal Importe neto.
     */
    public function net(): Decimal
    {
        return $this->net;
    }

    /**
     * Devuelve el IVA de la línea.
     *
     * @return Decimal Importe del IVA.
     */
    public function tax(): Decimal
    {
        return $this->tax;
    }

    /**
     * Devuelve el importe total de la línea.
     *
     * @return Decimal Importe total.
     */
    public function total(): Decimal
    {
        return $this->total;
    }

    /**
     * Indica si la línea está exenta de IVA.
     *
     * @return bool `true` si la línea no genera IVA.
     */
    public function isExempt(): bool
    {
        return $this->exempt;
    }

    /**
     * Devuelve la línea como array serializable, con importes como cadenas.
     *
     * Los importes viajan como cadenas decimales, no como números, para que el
     * documento guardado sea idéntico al que se envió al proveedor.
     *
     * @return array<string, string> Representación de la línea.
     */
    public function toArray(): array
    {
        return [
            'codigo'            => $this->code,
            'descripcion'       => $this->description,
            'cantidad'          => $this->quantity->toString(),
            'precio_unitario'   => $this->unitPrice->toString(),
            'porc_descuento'    => $this->discountPercent->toString(),
            'porc_otros'        => $this->otherPercent->toString(),
            'porc_iva'          => $this->taxPercent->toString(),
            'exento'            => $this->exempt ? '1' : '0',
            'bruto'             => $this->gross->toString(),
            'descuento'         => $this->discountAmount->toString(),
            'otros'             => $this->otherAmount->toString(),
            'exento_importe'    => $this->exemptAmount->toString(),
            'neto'              => $this->net->toString(),
            'iva'               => $this->tax->toString(),
            'total'             => $this->total->toString(),
        ];
    }
}
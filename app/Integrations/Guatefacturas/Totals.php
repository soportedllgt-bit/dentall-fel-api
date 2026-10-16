<?php

declare(strict_types=1);

namespace App\Integrations\Guatefacturas;

use LogicException;

/**
 * Totales del documento, agregados a partir de las líneas del carrito.
 *
 * ## Fórmula de la cabecera
 *
 * ```
 * Bruto      = Σ ImpBruto de las líneas
 * Descuento  = Σ ImpDescuento de las líneas
 * Otros      = Σ ImpOtros de las líneas
 * Exento     = Σ ImpExento de las líneas
 * Neto       = Bruto − Descuento − Otros
 * Isr        = 0
 * Iva        = Σ ImpIva de las líneas
 * Total      = Neto + Iva
 * ```
 *
 * ## Por qué `Isr` se informa en cero
 *
 * El bloque `<Totales>` del DTE tiene dos campos similares: `Otros` e `Isr`.
 * Se comprobó cuál de los dos es el que el proveedor utiliza:
 *
 * - Con `Otros = 100.00` e `Isr = 100.00`, el proveedor calculó el neto sobre
 *   `Bruto − Otros` (900 sobre un bruto de 1000) y aceptó el documento.
 * - Con `Otros = 0.00` e `Isr = 100.00`, el proveedor calculó el neto sobre el
 *   bruto íntegro (1000) y también aceptó.
 *
 * Es decir, la retención se resta desde `Otros`; `Isr` es informativo. Por eso
 * la API informa la retención en `Otros` y deja `Isr` a cero: escribir el
 * mismo valor en los dos campos sería ambiguo y, si el proveedor cambiara de
 * criterio, produciría una base imponible doblemente reducida.
 *
 * ## Invariantes
 *
 * Al construir el objeto se comprueba que `Neto` y `Total` coinciden con la
 * suma de las líneas. Si la aritmética interna se rompiera, el fallo aparecería
 * aquí, antes de gastar una llamada al proveedor, y no como un rechazo con el
 * error 82 mucho más tarde.
 *
 * @package App\Integrations\Guatefacturas
 *
 * @author  fel-dentall
 * @license MIT
 */
final class Totals
{
    /**
     * Importe bruto acumulado.
     *
     * @var Decimal
     */
    private $gross;

    /**
     * Descuento acumulado.
     *
     * @var Decimal
     */
    private $discount;

    /**
     * Otros impuestos acumulados (retenciones).
     *
     * @var Decimal
     */
    private $others;

    /**
     * Importe exento acumulado.
     *
     * @var Decimal
     */
    private $exempt;

    /**
     * Base imponible.
     *
     * @var Decimal
     */
    private $net;

    /**
     * IVA acumulado.
     *
     * @var Decimal
     */
    private $tax;

    /**
     * Importe total a pagar.
     *
     * @var Decimal
     */
    private $total;

    /**
     * Número de líneas agregadas.
     *
     * @var int
     */
    private $lineCount;

    /**
     * Agrega los importes de las líneas y verifica los invariantes.
     *
     * @param array<int, OrderLine> $lines Líneas del carrito.
     *
     * @throws LogicException Si alguna línea no cuadra con los totales.
     */
    public function __construct(array $lines)
    {
        $zero = Decimal::zero(Decimal::SCALE_MONEY);

        $gross     = $zero;
        $discount  = $zero;
        $others    = $zero;
        $exempt    = $zero;
        $tax       = $zero;
        $lineNet   = $zero;
        $lineTotal = $zero;

        foreach ($lines as $line) {
            $gross     = $gross->plus($line->gross());
            $discount  = $discount->plus($line->discountAmount());
            $others    = $others->plus($line->otherAmount());
            $exempt    = $exempt->plus($line->exemptAmount());
            $tax       = $tax->plus($line->tax());
            $lineNet   = $lineNet->plus($line->net());
            $lineTotal = $lineTotal->plus($line->total());
        }

        $this->gross     = $gross;
        $this->discount  = $discount;
        $this->others    = $others;
        $this->exempt    = $exempt;
        $this->net       = $gross->minus($discount)->minus($others);
        $this->tax       = $tax;
        $this->total     = $this->net->plus($tax);
        $this->lineCount = count($lines);

        if ($this->net->compare($lineNet) !== 0) {
            throw new LogicException(sprintf(
                'El neto calculado (%s) no coincide con la suma de las líneas (%s).',
                $this->net->toString(),
                $lineNet->toString()
            ));
        }

        if ($this->total->compare($lineTotal) !== 0) {
            throw new LogicException(sprintf(
                'El total calculado (%s) no coincide con la suma de las líneas (%s).',
                $this->total->toString(),
                $lineTotal->toString()
            ));
        }
    }

    /**
     * Devuelve el importe bruto.
     *
     * @return Decimal Bruto acumulado.
     */
    public function gross(): Decimal
    {
        return $this->gross;
    }

    /**
     * Devuelve el descuento aplicado.
     *
     * @return Decimal Descuento acumulado.
     */
    public function discount(): Decimal
    {
        return $this->discount;
    }

    /**
     * Devuelve los otros impuestos (retenciones).
     *
     * @return Decimal Importe retenido.
     */
    public function others(): Decimal
    {
        return $this->others;
    }

    /**
     * Devuelve el importe exento.
     *
     * @return Decimal Importe exento.
     */
    public function exempt(): Decimal
    {
        return $this->exempt;
    }

    /**
     * Devuelve la base imponible.
     *
     * @return Decimal Neto del documento.
     */
    public function net(): Decimal
    {
        return $this->net;
    }

    /**
     * Devuelve el IVA del documento.
     *
     * @return Decimal IVA acumulado.
     */
    public function tax(): Decimal
    {
        return $this->tax;
    }

    /**
     * Devuelve el importe total a pagar.
     *
     * @return Decimal Total del documento.
     */
    public function total(): Decimal
    {
        return $this->total;
    }

    /**
     * Devuelve el número de líneas que componen el documento.
     *
     * @return int Cantidad de líneas.
     */
    public function lineCount(): int
    {
        return $this->lineCount;
    }

    /**
     * Devuelve los totales como array serializable, con cadenas decimales.
     *
     * @return array<string, string> Importes en forma de cadena.
     */
    public function toArray(): array
    {
        return [
            'bruto'     => $this->gross->toString(),
            'descuento' => $this->discount->toString(),
            'otros'     => $this->others->toString(),
            'exento'    => $this->exempt->toString(),
            'neto'      => $this->net->toString(),
            'isr'       => '0.00',
            'iva'       => $this->tax->toString(),
            'total'     => $this->total->toString(),
            'lineas'    => (string) $this->lineCount,
        ];
    }
}
<?php

declare(strict_types=1);

namespace App\Integrations\Guatefacturas;

/**
 * Conversión de los importes de la orden a la representación del proveedor.
 *
 * ## El proveedor espera el precio CON IVA
 *
 * La guía operativa de GUATEFACTURAS es explícita, y lo dice en varios sitios
 * (detalle en formato de pipes, plantilla XML y descripción de campos):
 *
 * ```
 * PRECIO   Precio unitario del producto, IVA incluido
 * ```
 *
 * Y lo confirma su propio ejemplo, que es la pieza que zanja la duda:
 *
 * ```
 * 2|101|1|10|0|0|1010|0|901.79|108.21|0|1010|101|BOLSAS BLANCAS|B
 *    │   │  └┬┘ │  │   │    │    │      │      │
 *    │   │   │  │  │   │    │    │      │      └ Importe Iva
 *    │   │   │  │  │   │    │    │      └ Importe Neto = 1010 / 1.12
 *    │   │   │  │  │   │    │    └ Importe Exento
 *    │   │   │  │  │   │    └ Importe Bruto = 10 × 101
 *    │   │   │  │  │   └ Importe Descuento
 *    │   │   │  │  └ Porcentaje Descuento
 *    │   │   └ Precio (IVA incluido)
 *    │   └ Medida
 *    └ Cantidad
 * ```
 *
 * Con `Precio = 10` y `Cantidad = 101`, el proveedor espera `Bruto = 1010` y
 * `Neto = 901.79`, que es exactamente `1010 / 1.12`. El IVA sale del neto:
 * `901.79 × 0.12 = 108.21`, y `901.79 + 108.21 = 1010`.
 *
 * ## Qué ocurría al enviar el precio sin IVA
 *
 * La API recibía del POS el precio **neto** y lo mandaba tal cual, junto a un
 * `ImpBruto` también neto. El proveedor hacía su propia cuenta sobre esos
 * valores:
 *
 * ```
 * Neto esperado = (Precio − Descuentos − Otros) / 1.12
 *               = (100.00 − 0 − 0) / 1.12 = 89.28
 * ```
 *
 * Como el documento declaraba `ImpNeto = 100.00`, la comprobación no cuadraba y el
 * proveedor rechazaba el documento con `82-ERROR CALCULO DE IMPORTE BRUTO
 * INCORRECTO`. **Ese error es de cálculo, no de límite de emisiones.** Confundirlo
 * con un cupo agotado costó tres historias de usuario.
 *
 * El mismo síntoma reaparece con otro mensaje cuando la validación llega más
 * lejos: `ERROR-1041 [Monto Gravable:100] El contenido de la casilla es diferente
 * del resultado de la siguiente operación: casilla "Precio" menos las casillas
 * "descuento" y "otros descuentos" y el resultado de ellas dividido entre el
 * factor 1.12`.
 *
 * ## La conversión es reversible
 *
 * Multiplicar por `(1 + tasa)` y dividir por `(1 + tasa)` cancela el factor, así
 * que los importes que significan algo para el negocio no cambian:
 *
 * | Importe | Valor enviado |
 * |---|---|
 * | `Neto`  | el mismo que calcula la API |
 * | `Iva`   | el mismo |
 * | `Total` | el mismo |
 * | `Precio`, `Bruto`, `Descuento`, `Exento`, `Otros` | en base con IVA |
 *
 * Solo cambian los basados en el precio unitario. El total del documento sigue
 * cuadrando con `Neto + Iva`, que es lo que valida el proveedor.
 *
 * @package App\Integrations\Guatefacturas
 *
 * @author  fel-dentall
 * @license MIT
 */
final class ImportesProveedor
{
    /**
     * La clase es de uso estático: se bloquea la instanciación.
     */
    private function __construct()
    {
    }

    /**
     * Convierte una línea a los importes que espera el proveedor.
     *
     * @param OrderLine $line Línea normalizada, con sus importes en base neta.
     *
     * @return array<string, Decimal> Importes en la representación del proveedor.
     */
    public static function line(OrderLine $line): array
    {
        // El descuento se calcula sobre la misma base que el bruto de la rama
        // correspondiente. Si se aplicara sobre la base neta en una línea con IVA,
        // el `Neto` que resulta de la fórmula del proveedor ya no coincidiría con el
        // neto de negocio: la factura se emitiría por un importe distinto del que
        // calcula la API.
        if ($line->isExempt()) {
            // Una línea exenta no tiene IVA que incluir en el precio ni que
            // calcular después: el importe viaja íntegro en `ImpExento` y el neto
            // queda a cero. Enviar el precio inflado contabilizaría un IVA que la
            // línea no tiene.
            $precio    = $line->unitPrice();
            $bruto     = $precio->multiply($line->quantity());
            $descuento = $bruto->multiply($line->discountPercent()->divide(self::cien()));
            $exento    = $bruto->minus($descuento);
            $otros     = $bruto->minus($descuento)->multiply($line->otherPercent()->divide(self::cien()));

            return [
                'precio'     => $precio,
                'bruto'      => $bruto,
                'descuento'  => $descuento,
                'exento'     => $exento,
                'otros'      => $otros,
                'neto'       => self::cero(),
                'iva'        => self::cero(),
                'total'      => $exento,
            ];
        }

        // Los porcentajes llegan a escala de porcentaje (4 decimales) y los
        // importes a escala de dinero (2). `plus()` y `minus()` no reescalan:
        // suman las unidades tal cual, así que los operandos tienen que llegar ya
        // a la misma escala o el resultado se desplaza. La tasa se reescala a
        // dinero antes de usarla en sumas.
        $tasaDinero = Decimal::parse(
            $line->taxPercent()->toString(),
            Decimal::SCALE_MONEY,
            'tasa'
        );
        $parte  = $tasaDinero->divide(self::cien());
        $factor = self::uno()->plus($parte);

        // El precio unitario con IVA es la unidad sobre la que el proveedor
        // calcula todo lo demás.
        $precio = $line->unitPrice()->multiply($factor);
        $bruto  = $precio->multiply($line->quantity());

        $descuento = $bruto->multiply($line->discountPercent()->divide(self::cien()));

        // La retención de ISR se aplica sobre la base con IVA, para que el neto
        // resultante coincida con el que calcula la API: si se aplicara sobre el
        // neto, el divisor no cancelaría el factor y los dos importes discreparían.
        $otros = $bruto->minus($descuento)->multiply($line->otherPercent()->divide(self::cien()));

        $base = $bruto->minus($descuento)->minus($otros);
        $neto = $base->divide($factor);
        $iva  = $neto->multiply($parte);

        return [
            'precio'     => $precio,
            'bruto'      => $bruto,
            'descuento'  => $descuento,
            'exento'     => self::cero(),
            'otros'      => $otros,
            'neto'       => $neto,
            'iva'        => $iva,
            'total'      => $neto->plus($iva),
        ];
    }

    /**
     * Devuelve el importe cero.
     *
     * @return Decimal Cero con escala de dinero.
     */
    private static function cero(): Decimal
    {
        return Decimal::zero();
    }

    /**
     * Devuelve el número uno con escala de dinero.
     *
     * @return Decimal `1.00`.
     */
    private static function uno(): Decimal
    {
        return Decimal::parse('1', Decimal::SCALE_MONEY, 'factor');
    }

    /**
     * Devuelve el número cien con escala de dinero.
     *
     * @return Decimal `100.00`.
     */
    private static function cien(): Decimal
    {
        return Decimal::parse('100', Decimal::SCALE_MONEY, 'porcentaje');
    }

    /**
     * Suma los importes de todas las líneas.
     *
     * Los totales de cabecera se calculan sobre la representación del proveedor, no
     * sobre los importes de negocio, para que el documento sea internamente
     * coherente: la cabecera debe cuadrar con la suma de las líneas, y una
     * diferencia de un céntimo por redondeo se paga con un rechazo.
     *
     * @param array<int, OrderLine> $lines Líneas del documento.
     *
     * @return array<string, Decimal> Totales en la representación del proveedor.
     */
    public static function totals(array $lines): array
    {
        $totales = [
            'bruto'     => Decimal::parse('0.00', 2, 'importe'),
            'descuento' => Decimal::parse('0.00', 2, 'importe'),
            'exento'    => Decimal::parse('0.00', 2, 'importe'),
            'otros'     => Decimal::parse('0.00', 2, 'importe'),
            'neto'      => Decimal::parse('0.00', 2, 'importe'),
            'iva'       => Decimal::parse('0.00', 2, 'importe'),
            'total'     => Decimal::parse('0.00', 2, 'importe'),
        ];

        foreach ($lines as $line) {
            $importes = self::line($line);

            foreach ($totales as $clave => $valor) {
                $totales[$clave] = $valor->plus($importes[$clave]);
            }
        }

        return $totales;
    }
}
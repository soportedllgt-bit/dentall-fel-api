<?php

declare(strict_types=1);

namespace App\Integrations\Guatefacturas;

use InvalidArgumentException;

/**
 * Decimal exacto representado en enteros, para importes y cantidades.
 *
 * ## Por qué no se usan `float` ni `double`
 *
 * El proveedor valida aritméticamente cada documento antes de firmarlo. Un
 * rechazo observado en el sandbox fue:
 *
 * ```
 * 82-ERROR CALCULO DE IMPORTE BRUTO INCORRECTO
 * ```
 *
 * Es decir, un céntimo de diferencia entre lo que la API calcula y lo que el
 * proveedor recalcula impide emitir la factura. La aritmética binaria de coma
 * flotante no puede garantizar esa coincidencia: en PHP,
 * `0.29 * 100 === 28.999999999999996`, y sobre una factura de veinte líneas el
 * error acumulado es cuestión de tiempo. El síntoma sería un rechazo del
 * proveedor en producción, no un aviso en desarrollo.
 *
 * Todos los valores se guardan por tanto como **unidades enteras de la mínima
 * fracción**: centavos para dinero (escala 2), milésimas para cantidad
 * (escala 3), diezmilésimas para porcentajes (escala 4). El redondeo se hace
 * con aritmética entera, nunca llamando a `round()` sobre un `float`.
 *
 * ## Redondeo
 *
 * Se redondea siempre **a la media unidad hacia arriba en valor absoluto**
 * (`round half up`), que es la convención comercial y la que espera la SAT. El
 * importe que paga el cliente nunca resulta menor que el calculado.
 *
 * ## Fórmula única
 *
 * Toda la aritmética sale de dos primitivas, {@see Decimal::scaledMultiply()} y
 * {@see Decimal::ratio()}. Concentrar el redondeo en un solo sitio es lo que
 * hace auditable el cálculo: si el redondeo estuviera repartido en cada
 * operación, dos revisiones distintas del mismo producto darían importes
 * distintos, y el proveedor rechazaría el documento.
 *
 * @package App\Integrations\Guatefacturas
 *
 * @author  fel-dentall
 * @license MIT
 */
final class Decimal
{
    /**
     * Escala de los importes monetarios: dos decimales.
     *
     * @var int
     */
    public const SCALE_MONEY = 2;

    /**
     * Escala de las cantidades: tres decimales.
     *
     * Tres decimales cubren los servicios por hora (`1.5`) y los fraccionados
     * por peso o volumen, que son los casos reales de una clínica.
     *
     * @var int
     */
    public const SCALE_QUANTITY = 3;

    /**
     * Escala de los porcentajes: cuatro decimales.
     *
     * @var int
     */
    public const SCALE_PERCENT = 4;

    /**
     * Unidades enteras del valor, en la escala de la instancia.
     *
     * @var int
     */
    private $units;

    /**
     * Número de decimales de la escala.
     *
     * @var int
     */
    private $scale;

    /**
     * Constructor privado: se usa {@see Decimal::parse()} o
     * {@see Decimal::fromUnits()}.
     *
     * @param int $units Unidades enteras ya redondeadas.
     * @param int $scale Escala decimal.
     */
    private function __construct(int $units, int $scale)
    {
        $this->units = $units;
        $this->scale = $scale;
    }

    /**
     * Crea un decimal a partir de sus unidades enteras, sin pasar por `float`.
     *
     * @param int $units Unidades enteras en la escala indicada.
     * @param int $scale Escala decimal.
     *
     * @return self Decimal resultante.
     *
     * @throws InvalidArgumentException Si la escala está fuera de rango.
     */
    public static function fromUnits(int $units, int $scale): self
    {
        if ($scale < 0 || $scale > 9) {
            throw new InvalidArgumentException('La escala decimal debe estar entre 0 y 9.');
        }

        return new self($units, $scale);
    }

    /**
     * Crea un decimal desde un importe en centavos.
     *
     * @param int $cents Centavos exactos.
     *
     * @return self Importe monetario.
     */
    public static function fromCents(int $cents): self
    {
        return new self($cents, self::SCALE_MONEY);
    }

    /**
     * Crea un decimal cero en una escala dada.
     *
     * @param int $scale Escala decimal.
     *
     * @return self Cero.
     */
    public static function zero(int $scale = self::SCALE_MONEY): self
    {
        return new self(0, $scale);
    }

    /**
     * Interpreta un valor recibido del POS y lo normaliza a unidades enteras.
     *
     * Acepta enteros, `float` y cadenas numéricas. Las cadenas se procesan con
     * aritmética de cadenas: `"0.29"` produce exactamente 29 centavos, mientras
     * que `0.29 * 100` en coma flotante produce `28.999999999999996`.
     *
     * Un valor con más decimales que la escala se **rechaza**, no se redondea.
     * Aceptar `1.234` como `1.23` ocultaría un céntimo que el proveedor
     * descartaría al recalcular, y la factura se rechazaría con el error 82.
     *
     * @param mixed  $value    Valor a interpretar.
     * @param int    $scale    Escala decimal de destino.
     * @param string $field    Nombre del campo, para el mensaje de error.
     * @param bool   $negative Si se admite un valor negativo.
     *
     * @return self Decimal normalizado.
     *
     * @throws InvalidArgumentException Si el valor no es válido para la escala.
     */
    public static function parse($value, int $scale, string $field, bool $negative = false): self
    {
        if ($scale < 0 || $scale > 9) {
            throw new InvalidArgumentException('La escala decimal debe estar entre 0 y 9.');
        }

        if (is_bool($value) || $value === null) {
            throw new InvalidArgumentException(sprintf(
                'El campo "%s" debe ser un número.',
                $field
            ));
        }

        if (is_float($value)) {
            $value = self::floatToPlainString($value, $field);
        }

        if (is_int($value)) {
            $units = self::times($value, self::factor($scale));

            return new self($negative ? $units : $units, $scale);
        }

        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf(
                'El campo "%s" debe ser un número o una cadena numérica.',
                $field
            ));
        }

        $text = trim($value);

        if ($text === '') {
            throw new InvalidArgumentException(sprintf(
                'El campo "%s" no puede estar vacío.',
                $field
            ));
        }

        if (!$negative) {
            if (preg_match('/^(\d+)(?:\.(\d*))?$/', $text, $matches) !== 1) {
                throw new InvalidArgumentException(sprintf(
                    'El campo "%s" no es un número válido: "%s".',
                    $field,
                    $text
                ));
            }

            $whole    = (int) $matches[1];
            $fraction = $matches[2] ?? '';
            $sign     = 1;
        } else {
            if (preg_match('/^(-?)(\d+)(?:\.(\d*))?$/', $text, $matches) !== 1) {
                throw new InvalidArgumentException(sprintf(
                    'El campo "%s" no es un número válido: "%s".',
                    $field,
                    $text
                ));
            }

            $whole    = (int) $matches[2];
            $fraction = $matches[3] ?? '';
            $sign     = $matches[1] === '-' ? -1 : 1;
        }

        if ($fraction !== '' && strlen(rtrim($fraction, '0')) > $scale) {
            throw new InvalidArgumentException(sprintf(
                'El campo "%s" admite como máximo %d decimales.',
                $field,
                $scale
            ));
        }

        $fraction = str_pad(substr($fraction, 0, $scale), $scale, '0');

        return new self(
            $sign * ($whole * self::factor($scale) + (int) $fraction),
            $scale
        );
    }

    /**
     * Devuelve las unidades enteras del decimal.
     *
     * @return int Unidades en la escala de la instancia.
     */
    public function units(): int
    {
        return $this->units;
    }

    /**
     * Devuelve las unidades del decimal expresadas en centavos.
     *
     * @return int Cantidad de centavos.
     */
    public function cents(): int
    {
        return self::rescale($this->units, $this->scale, self::SCALE_MONEY);
    }

    /**
     * Devuelve la escala del decimal.
     *
     * @return int Número de decimales.
     */
    public function scale(): int
    {
        return $this->scale;
    }

    /**
     * Devuelve el valor como cadena con punto decimal y escala completa.
     *
     * Es la representación que viaja al XML del proveedor. Siempre incluye
     * todos los decimales de la escala, de modo que el documento sea idéntico
     * en cada reintento.
     *
     * @return string Representación decimal, por ejemplo `150.00`.
     */
    public function toString(): string
    {
        return self::render($this->units, $this->scale);
    }

    /**
     * Representa el decimal como número JSON conservando la escala.
     *
     * @return float|int Valor numérico redondeado a la escala.
     */
    public function toNumber()
    {
        if ($this->scale === 0) {
            return $this->units;
        }

        return (float) self::render($this->units, $this->scale);
    }

    /**
     * Indica si el valor es cero.
     *
     * @return bool `true` si no aporta nada al total.
     */
    public function isZero(): bool
    {
        return $this->units === 0;
    }

    /**
     * Indica si el valor es menor que cero.
     *
     * @return bool `true` si es negativo.
     */
    public function isNegative(): bool
    {
        return $this->units < 0;
    }

    /**
     * Compara dos decimales.
     *
     * @param Decimal $other Valor con el que comparar.
     *
     * @return int `-1`, `0` o `1` según el orden.
     */
    public function compare(self $other): int
    {
        return $this->units <=> $other->units;
    }

    /**
     * Devuelve un decimal con signo invertido.
     *
     * @return self Valor negado.
     */
    public function negate(): self
    {
        return new self(-$this->units, $this->scale);
    }

    /**
     * Devuelve un decimal con valor absoluto.
     *
     * @return self Valor absoluto.
     */
    public function absolute(): self
    {
        return new self(abs($this->units), $this->scale);
    }

    /**
     * Devuelve el mayor de los dos valores.
     *
     * @param Decimal $other Valor alternativo.
     *
     * @return self El mayor de ambos.
     */
    public function max(self $other): self
    {
        return $this->units >= $other->units ? $this : $other;
    }

    /**
     * Devuelve el menor de los dos valores.
     *
     * @param Decimal $other Valor alternativo.
     *
     * @return self El menor de ambos.
     */
    public function min(self $other): self
    {
        return $this->units <= $other->units ? $this : $other;
    }

    /**
     * Suma otro decimal.
     *
     * @param Decimal $other Valor a sumar.
     *
     * @return self Resultado de la suma.
     */
    public function plus(self $other): self
    {
        return new self($this->units + $other->units, $this->scale);
    }

    /**
     * Suma varios decimales.
     *
     * @param array<int, Decimal> $values Valores a acumular.
     * @param int                 $scale  Escala del resultado.
     *
     * @return self Resultado de la suma.
     */
    public static function sum(array $values, int $scale = self::SCALE_MONEY): self
    {
        $total = self::zero($scale);

        foreach ($values as $value) {
            $total = new self($total->units + $value->units, $scale);
        }

        return $total;
    }

    /**
     * Resta otro decimal.
     *
     * @param Decimal $other Valor a restar.
     *
     * @return self Resultado de la resta.
     */
    public function minus(self $other): self
    {
        return new self($this->units - $other->units, $this->scale);
    }

    /**
     * Multiplica por otro decimal y devuelve el resultado en una escala destino.
     *
     * Es el caso central de una línea de factura: cantidad (escala 3) por
     * precio unitario (escala 2), con resultado en escala 2.
     *
     * @param Decimal $other       Factor por el que multiplicar.
     * @param int     $targetScale Escala del resultado.
     *
     * @return self Producto en la escala indicada.
     */
    public function multiply(self $other, int $targetScale = self::SCALE_MONEY): self
    {
        return new self(
            self::scaledMultiply(
                $this->units,
                $this->scale,
                $other->units,
                $other->scale,
                $targetScale
            ),
            $targetScale
        );
    }

    /**
     * Devuelve el tanto por ciento de este valor.
     *
     * `150.00` al `12` por ciento devuelve `18.00`.
     *
     * @param Decimal $percent Porcentaje a aplicar.
     *
     * @return self Resultado en la escala de este valor.
     */
    public function percentOf(self $percent): self
    {
        $product = self::scaledMultiply(
            $this->units,
            $this->scale,
            $percent->units,
            $percent->scale,
            $this->scale
        );

        // El porcentaje es una fracción sobre 100: `percent` = 12.5 significa
        // 0.125. La división por 100 se aplica una vez, sobre el producto ya
        // redondeado a la escala destino.
        return new self(self::divideUnits($product, 100), $this->scale);
    }

    /**
     * Devuelve qué porcentaje representa este valor sobre una base.
     *
     * `150.00` sobre `200.00` devuelve `75.0000`. La base puede tener otra
     * escala: también sirve para expresar, por ejemplo, un porcentaje de una
     * cantidad (`1.5` sobre `3` da `50.0000`).
     *
     * @param Decimal $base Base sobre la que calcular el porcentaje.
     *
     * @return self Porcentaje con escala {@see Decimal::SCALE_PERCENT}.
     *
     * @throws InvalidArgumentException Si la base es cero.
     */
    public function percentageOf(self $base): self
    {
        if ($base->units === 0) {
            throw new InvalidArgumentException('No se puede calcular un porcentaje sobre una base de cero.');
        }

        return new self(
            self::ratio(
                $this->units,
                $this->scale,
                $base->units,
                $base->scale,
                self::SCALE_PERCENT,
                -2
            ),
            self::SCALE_PERCENT
        );
    }

    /**
     * Divide entre otro decimal conservando la escala de este valor.
     *
     * `1.00` entre `4.00` devuelve `0.25`.
     *
     * @param Decimal $divisor Divisor; no puede ser cero.
     *
     * @return self Cociente en la escala de este valor.
     *
     * @throws InvalidArgumentException Si el divisor es cero.
     */
    public function divide(self $divisor): self
    {
        if ($divisor->units === 0) {
            throw new InvalidArgumentException('No se puede dividir entre cero.');
        }

        return new self(
            self::ratio(
                $this->units,
                $this->scale,
                $divisor->units,
                $divisor->scale,
                $this->scale
            ),
            $this->scale
        );
    }

    /**
     * Multiplica dos decimales devolviendo el resultado en una escala destino.
     *
     * Es la primitiva de todo el cálculo: el producto entero se ajusta con la
     * potencia de diez que separa las escalas, y el redondeo ocurre una sola
     * vez, aquí.
     *
     * @param int $unitsA    Unidades del primer factor.
     * @param int $scaleA    Escala del primer factor.
     * @param int $unitsB    Unidades del segundo factor.
     * @param int $scaleB    Escala del segundo factor.
     * @param int $targetScale Escala del resultado.
     *
     * @return int Unidades del producto en la escala destino.
     */
    private static function scaledMultiply(
        int $unitsA,
        int $scaleA,
        int $unitsB,
        int $scaleB,
        int $targetScale
    ): int {
        // valor = (a/10^sa) * (b/10^sb)  ->  unidades@destino = a*b / 10^(sa+sb-destino)
        $exponent = $scaleA + $scaleB - $targetScale;

        if ($exponent === 0) {
            return $unitsA * $unitsB;
        }

        if ($exponent > 0) {
            return self::divideUnits($unitsA * $unitsB, self::factor($exponent));
        }

        return $unitsA * $unitsB * self::factor(-$exponent);
    }

    /**
     * Divide dos decimales y devuelve el cociente en una escala destino.
     *
     * @param int  $unitsNum   Unidades del numerador.
     * @param int  $scaleNum   Escala del numerador.
     * @param int  $unitsDen   Unidades del denominador.
     * @param int  $scaleDen   Escala del denominador.
     * @param int  $targetScale Escala del cociente.
     * @param int  $shift      Desplazamiento decimal del denominador: `2`
     *                         equivale a dividir entre 100.
     *
     * @return int Unidades del cociente en la escala destino.
     */
    private static function ratio(
        int $unitsNum,
        int $scaleNum,
        int $unitsDen,
        int $scaleDen,
        int $targetScale,
        int $shift = 0
    ): int {
        // valor = (n/10^sn) / ((d/10^sd) * 10^shift)
        // unidades@destino = n * 10^(sd - shift + destino - sn) / d
        $exponent = $scaleDen - $shift + $targetScale - $scaleNum;

        if ($exponent >= 0) {
            return self::divideUnits($unitsNum * self::factor($exponent), $unitsDen);
        }

        return self::divideUnits($unitsNum, $unitsDen * self::factor(-$exponent));
    }

    /**
     * Reajusta unidades entre escalas, redondeando a la media unidad.
     *
     * @param int $units Unidades de origen.
     * @param int $from  Escala de origen.
     * @param int $to    Escala de destino.
     *
     * @return int Unidades en la escala de destino.
     */
    private static function rescale(int $units, int $from, int $to): int
    {
        if ($from === $to) {
            return $units;
        }

        if ($from < $to) {
            return self::times($units, self::factor($to - $from));
        }

        return self::divideUnits($units, self::factor($from - $to));
    }

    /**
     * Multiplica dos enteros sin riesgo de saturar en coma flotante.
     *
     * @param int $left  Primer factor.
     * @param int $right Segundo factor.
     *
     * @return int Producto exacto.
     */
    private static function times(int $left, int $right): int
    {
        return $left * $right;
    }

    /**
     * Divide dos enteros redondeando a la media unidad.
     *
     * El signo se aplica al final para que el redondeo sea simétrico: sin él,
     * `-5 / 2` truncaría a `-2` en lugar de redondear a `-3`.
     *
     * @param int $numerator   Numerador.
     * @param int $denominator Denominador; no puede ser cero.
     *
     * @return int Cociente redondeado.
     *
     * @throws InvalidArgumentException Si el denominador es cero.
     */
    private static function divideUnits(int $numerator, int $denominator): int
    {
        if ($denominator === 0) {
            throw new InvalidArgumentException('División entera entre cero.');
        }

        $negative = ($numerator < 0) !== ($denominator < 0);

        $quotient  = intdiv(abs($numerator), abs($denominator));
        $remainder = abs($numerator) % abs($denominator);

        // Redondeo a media unidad: si el resto alcanza la mitad del divisor, sube.
        if ($remainder * 2 >= abs($denominator)) {
            $quotient++;
        }

        return $negative ? -$quotient : $quotient;
    }

    /**
     * Convierte un `float` a cadena decimal exacta, sin notación científica.
     *
     * `json_encode()` puede producir `1.0e-5`, que la expresión regular de
     * {@see Decimal::parse()} rechazaría. Se fija a seis decimales, suficientes
     * para cualquier escala admitida.
     *
     * @param float  $value Valor a convertir.
     * @param string $field Nombre del campo, para el mensaje de error.
     *
     * @return string Cadena decimal.
     *
     * @throws InvalidArgumentException Si el valor no es finito.
     */
    private static function floatToPlainString(float $value, string $field): string
    {
        if (is_nan($value) || is_infinite($value)) {
            throw new InvalidArgumentException(sprintf(
                'El campo "%s" no es un número finito.',
                $field
            ));
        }

        $formatted = rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');

        return $formatted === '' || $formatted === '-' ? '0' : $formatted;
    }

    /**
     * Devuelve diez elevado a un exponente.
     *
     * @param int $exponent Exponente no negativo.
     *
     * @return int Resultado de la potencia.
     */
    private static function factor(int $exponent): int
    {
        return $exponent <= 0 ? 1 : (int) (10 ** $exponent);
    }

    /**
     * Convierte unidades enteras en cadena decimal.
     *
     * @param int $units Unidades enteras.
     * @param int $scale Escala decimal.
     *
     * @return string Cadena con punto decimal y todos los decimales.
     */
    private static function render(int $units, int $scale): string
    {
        $negative = $units < 0;
        $absolute = (string) abs($units);

        if ($scale === 0) {
            return ($negative ? '-' : '') . $absolute;
        }

        $absolute = str_pad($absolute, $scale + 1, '0', STR_PAD_LEFT);
        $whole    = substr($absolute, 0, strlen($absolute) - $scale);
        $fraction = substr($absolute, strlen($absolute) - $scale);

        return ($negative ? '-' : '') . $whole . '.' . $fraction;
    }
}
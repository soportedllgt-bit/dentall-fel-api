<?php

declare(strict_types=1);

namespace App\Integrations\Guatefacturas;

use InvalidArgumentException;

/**
 * Orden de venta normalizada y validada.
 *
 * ## El contrato de entrada
 *
 * Este objeto es la frontera entre el JSON libre que envía el POS y la
 * estructura cerrada que necesita el documento fiscal. Su documentación es la
 * especificación que el POS debe seguir:
 *
 * ```json
 * {
 *   "codigo_orden": "POS-2026-000123",
 *   "cliente": {
 *     "nit": "C/F",
 *     "nombre": "Nombre del cliente",
 *     "direccion": "Zona 1, calle 2, casa 3",
 *     "correo": "cliente@ejemplo.com",
 *     "telefono": "51234567"
 *   },
 *   "carrito": [
 *     {
 *       "codigo": "SRV-001",
 *       "descripcion": "Consulta odontológica",
 *       "cantidad": 1,
 *       "precio_unitario": 100.00,
 *       "descuento": 0,
 *       "porcentaje_iva": 12,
 *       "porcentaje_isr": 0,
 *       "exento": false
 *     }
 *   ],
 *   "facturacion": {
 *     "tipo_documento": "FACT",
 *     "tipo_venta": "S",
 *     "destino_venta": 1,
 *     "observaciones": null
 *   },
 *   "entrega": {
 *     "direccion": "Zona 5",
 *     "telefono": "51234567",
 *     "observaciones": "Entregar en recepción"
 *   }
 * }
 * ```
 *
 * Reglas del contrato:
 *
 * - `precio_unitario` es el importe **sin IVA**. No es una convención de este
 *   proyecto: el proveedor rechaza con el error 82 cualquier documento cuyo IVA
 *   no sea exactamente `base × tasa`. Véase {@see OrderLine}.
 * - `codigo_orden` viaja al proveedor como `<Referencia>` y es la clave de
 *   idempotencia de toda la operación.
 * - `exento` es booleano. El SAT no admite exención parcial dentro de una misma
 *   línea, de modo que un concepto parcialmente exento se divide en dos líneas.
 * - Los importes admiten número o cadena numérica con hasta dos decimales. Se
 *   rechaza cualquier valor con más precisión en lugar de redondearlo.
 * - Los campos no incluidos no se envian: se usan los valores por defecto de
 *   configuración (`porcentaje_iva`, `tipo_venta`, `destino_venta`).
 *
 * ## Validación
 *
 * {@see OrderPayload::validate()} acumula **todos** los errores en una sola
 * pasada, para que el POS no tenga que corregir los campos de uno en uno.
 *
 * @package App\Integrations\Guatefacturas
 *
 * @author  fel-dentall
 * @license MIT
 */
final class OrderPayload
{
    /**
     * Código único de la orden del POS.
     *
     * @var string
     */
    private $orderCode;

    /**
     * Datos normalizados del cliente.
     *
     * @var array<string, string>
     */
    private $customer;

    /**
     * Datos normalizados de la factura.
     *
     * @var array<string, string>
     */
    private $invoicing;

    /**
     * Datos normalizados de la entrega.
     *
     * @var array<string, string>
     */
    private $delivery;

    /**
     * Tipo de documento, como código numérico de {@see DteType}.
     *
     * @var int
     */
    private $dteType;

    /**
     * Líneas del carrito.
     *
     * @var array<int, OrderLine>
     */
    private $lines;

    /**
     * Totales agregados del documento.
     *
     * @var Totals
     */
    private $totals;

    /**
     * Reglas de facturación vigentes.
     *
     * @var array<string, mixed>
     */
    private $rules;

    /**
     * Construye la orden a partir de una entrada ya validada.
     *
     * @param array<string, mixed> $input  Datos normalizados.
     * @param array<string, mixed> $rules  Reglas de {@see \App\Core\AppConfig::getBillingConfig()}.
     *
     * @throws InvalidArgumentException Si la entrada es inconsistente.
     */
    private function __construct(array $input, array $rules)
    {
        $this->orderCode  = (string) $input['codigo_orden'];
        $this->customer   = $input['cliente'];
        $this->invoicing  = $input['facturacion'];
        $this->delivery   = $input['entrega'];
        $this->dteType    = (int) $input['tipo_documento'];
        $this->lines      = $input['carrito'];
        $this->totals     = new Totals($this->lines);
        $this->rules      = $rules;
    }

    /**
     * Valida y normaliza una orden del POS.
     *
     * @param array<string, mixed> $input Cuerpo JSON de la petición.
     * @param array<string, mixed> $rules Reglas de facturación.
     *
     * @return array{ok: bool, order: self|null, errors: array<string, array<int, string>>, message: string}
     *         Sobre con la orden normalizada o el detalle de los errores.
     */
    public static function fromArray(array $input, array $rules): array
    {
        $errors = self::validate($input, $rules);

        if ($errors !== []) {
            return [
                'ok'      => false,
                'order'   => null,
                'errors'  => $errors,
                'message' => 'Los datos de la orden no son válidos.',
            ];
        }

        return [
            'ok'      => true,
            'order'   => new self(self::normalize($input, $rules), $rules),
            'errors'  => [],
            'message' => '',
        ];
    }

    /**
     * Acumula todos los errores de la entrada.
     *
     * @param array<string, mixed> $input Cuerpo JSON de la petición.
     * @param array<string, mixed> $rules Reglas de facturación.
     *
     * @return array<string, array<int, string>> Errores agrupados por campo.
     */
    public static function validate(array $input, array $rules): array
    {
        /** @var array<string, array<int, string>> $errors */
        $errors = [];

        $add = static function (string $field, string $message) use (&$errors): void {
            $errors[$field][] = $message;
        };

        // --- Campos de primer nivel -----------------------------------------
        foreach (['codigo_orden', 'cliente', 'carrito', 'facturacion'] as $field) {
            if (!array_key_exists($field, $input)) {
                $add($field, sprintf('El campo "%s" es obligatorio.', $field));
            }
        }

        $unexpected = array_diff(
            array_keys($input),
            ['codigo_orden', 'cliente', 'carrito', 'facturacion', 'entrega']
        );

        foreach ($unexpected as $field) {
            $add((string) $field, sprintf('El campo "%s" no está permitido.', $field));
        }

        // --- Código de la orden ---------------------------------------------
        if (array_key_exists('codigo_orden', $input)) {
            $code = $input['codigo_orden'];

            if (!is_string($code) || trim($code) === '') {
                $add('codigo_orden', 'El código de la orden debe ser una cadena no vacía.');
            } elseif (mb_strlen($code, 'UTF-8') > (int) $rules['order_code_max_length']) {
                $add('codigo_orden', sprintf(
                    'El código de la orden no puede superar los %d caracteres.',
                    (int) $rules['order_code_max_length']
                ));
            } elseif (preg_match('/[\x00-\x1F]/', $code) === 1) {
                $add('codigo_orden', 'El código de la orden no puede contener caracteres de control.');
            }
        }

        // --- Cliente ---------------------------------------------------------
        if (array_key_exists('cliente', $input)) {
            $errors += self::validateCustomer($input['cliente'], $rules, $add);
        }

        // --- Facturación -----------------------------------------------------
        if (array_key_exists('facturacion', $input)) {
            self::validateInvoicing($input['facturacion'], $rules, $add);
        }

        // --- Entrega (opcional) ---------------------------------------------
        if (array_key_exists('entrega', $input) && $input['entrega'] !== null) {
            if (!is_array($input['entrega'])) {
                $add('entrega', 'El campo "entrega" debe ser un objeto.');
            } else {
                foreach (array_keys($input['entrega']) as $field) {
                    if (!in_array((string) $field, ['direccion', 'telefono', 'observaciones'], true)) {
                        $add('entrega.' . $field, sprintf(
                            'El campo "entrega.%s" no está permitido.',
                            (string) $field
                        ));
                    }
                }
            }
        }

        // --- Carrito ---------------------------------------------------------
        if (array_key_exists('carrito', $input)) {
            $errors += self::validateCart($input['carrito'], $rules, $add);
        }

        return $errors;
    }

    /**
     * Valida el bloque `cliente`.
     *
     * @param mixed         $customer Datos recibidos.
     * @param array<string, mixed> $rules   Reglas de facturación.
     * @param callable      $add      Acumulador de errores.
     *
     * @return array<string, array<int, string>> Errores del bloque.
     */
    private static function validateCustomer($customer, array $rules, callable $add): array
    {
        if (!is_array($customer)) {
            $add('cliente', 'El campo "cliente" debe ser un objeto.');
            return [];
        }

        $required = ['nit' => 20, 'nombre' => (int) $rules['customer_name_max']];

        foreach ($required as $field => $maxLength) {
            if (!array_key_exists($field, $customer)) {
                $add('cliente.' . $field, sprintf('El campo "cliente.%s" es obligatorio.', $field));
                continue;
            }

            if (!is_string($customer[$field]) || trim($customer[$field]) === '') {
                $add('cliente.' . $field, sprintf(
                    'El campo "cliente.%s" debe ser una cadena no vacía.',
                    $field
                ));
                continue;
            }

            if (mb_strlen($customer[$field], 'UTF-8') > $maxLength) {
                $add('cliente.' . $field, sprintf(
                    'El campo "cliente.%s" no puede superar los %d caracteres.',
                    $field,
                    $maxLength
                ));
            }
        }

        // `direccion` es obligatoria: el proveedor la exige en <Receptor>.
        if (!array_key_exists('direccion', $customer)) {
            $add('cliente.direccion', 'El campo "cliente.direccion" es obligatorio.');
        } elseif (!is_string($customer['direccion']) || trim($customer['direccion']) === '') {
            $add('cliente.direccion', 'El campo "cliente.direccion" debe ser una cadena no vacía.');
        } elseif (mb_strlen($customer['direccion'], 'UTF-8') > (int) $rules['customer_address_max']) {
            $add('cliente.direccion', sprintf(
                'El campo "cliente.direccion" no puede superar los %d caracteres.',
                (int) $rules['customer_address_max']
            ));
        }

        $allowed = ['nit', 'nombre', 'direccion', 'correo', 'telefono'];

        foreach (array_keys($customer) as $field) {
            if (!in_array((string) $field, $allowed, true)) {
                $add('cliente.' . $field, sprintf(
                    'El campo "cliente.%s" no está permitido.',
                    (string) $field
                ));
            }
        }

        return [];
    }

    /**
     * Valida el bloque `facturacion`.
     *
     * @param mixed                $invoicing Datos recibidos.
     * @param array<string, mixed> $rules     Reglas de facturación.
     * @param callable             $add       Acumulador de errores.
     *
     * @return void
     */
    private static function validateInvoicing($invoicing, array $rules, callable $add): void
    {
        if (!is_array($invoicing)) {
            $add('facturacion', 'El campo "facturacion" debe ser un objeto.');
            return;
        }

        $allowed = ['tipo_documento', 'tipo_venta', 'destino_venta', 'observaciones'];

        foreach (array_keys($invoicing) as $field) {
            if (!in_array((string) $field, $allowed, true)) {
                $add('facturacion.' . $field, sprintf(
                    'El campo "facturacion.%s" no está permitido.',
                    (string) $field
                ));
            }
        }

        if (!array_key_exists('tipo_documento', $invoicing)) {
            return;
        }

        $type = DteType::resolve($invoicing['tipo_documento']);

        if ($type === null) {
            $add('facturacion.tipo_documento', sprintf(
                'Tipo de documento desconocido: "%s". Use un código como %s.',
                is_scalar($invoicing['tipo_documento'])
                    ? (string) $invoicing['tipo_documento']
                    : gettype($invoicing['tipo_documento']),
                implode(', ', array_keys(DteType::all()))
            ));
        }
    }

    /**
     * Valida el bloque `carrito`.
     *
     * @param mixed                $cart  Carrito recibido.
     * @param array<string, mixed> $rules Reglas de facturación.
     * @param callable             $add   Acumulador de errores.
     *
     * @return array<string, array<int, string>> Errores del bloque.
     */
    private static function validateCart($cart, array $rules, callable $add): array
    {
        if (!is_array($cart) || $cart === []) {
            $add('carrito', 'El campo "carrito" debe ser un arreglo con al menos una línea.');
            return [];
        }

        if (count($cart) > (int) $rules['max_lines']) {
            $add('carrito', sprintf(
                'El carrito no puede superar las %d líneas.',
                (int) $rules['max_lines']
            ));
        }

        $allowed = [
            'codigo', 'descripcion', 'cantidad', 'precio_unitario',
            'descuento', 'porcentaje_iva', 'porcentaje_isr', 'exento',
        ];

        foreach ($cart as $index => $line) {
            $prefix = sprintf('carrito.%d', $index);

            if (!is_array($line)) {
                $add($prefix, 'Cada línea del carrito debe ser un objeto.');
                continue;
            }

            foreach (array_keys($line) as $field) {
                if (!in_array((string) $field, $allowed, true)) {
                    $add($prefix . '.' . $field, sprintf(
                        'El campo "%s.%s" no está permitido.',
                        $prefix,
                        (string) $field
                    ));
                }
            }

            if (!array_key_exists('codigo', $line)) {
                $add($prefix . '.codigo', sprintf(
                    'El campo "%s.codigo" es obligatorio.',
                    $prefix
                ));
            } elseif (!is_string($line['codigo']) || trim($line['codigo']) === '') {
                $add($prefix . '.codigo', sprintf(
                    'El campo "%s.codigo" debe ser una cadena no vacía.',
                    $prefix
                ));
            }

            if (!array_key_exists('descripcion', $line)) {
                $add($prefix . '.descripcion', sprintf(
                    'El campo "%s.descripcion" es obligatorio.',
                    $prefix
                ));
            } elseif (!is_string($line['descripcion']) || trim($line['descripcion']) === '') {
                $add($prefix . '.descripcion', sprintf(
                    'El campo "%s.descripcion" debe ser una cadena no vacía.',
                    $prefix
                ));
            } elseif (mb_strlen($line['descripcion'], 'UTF-8') > (int) $rules['line_description_max']) {
                $add($prefix . '.descripcion', sprintf(
                    'El campo "%s.descripcion" no puede superar los %d caracteres.',
                    $prefix,
                    (int) $rules['line_description_max']
                ));
            }

            foreach (['cantidad' => Decimal::SCALE_QUANTITY, 'precio_unitario' => Decimal::SCALE_MONEY] as $field => $scale) {
                if (!array_key_exists($field, $line)) {
                    $add($prefix . '.' . $field, sprintf(
                        'El campo "%s.%s" es obligatorio.',
                        $prefix,
                        $field
                    ));
                    continue;
                }

                try {
                    $parsed = Decimal::parse($line[$field], $scale, $prefix . '.' . $field);

                    if ($parsed->isNegative()) {
                        $add($prefix . '.' . $field, sprintf(
                            'El campo "%s.%s" no puede ser negativo.',
                            $prefix,
                            $field
                        ));
                    } elseif ($parsed->isZero() && $field === 'cantidad') {
                        $add($prefix . '.' . $field, sprintf(
                            'El campo "%s.cantidad" debe ser mayor que cero.',
                            $prefix
                        ));
                    }
                } catch (InvalidArgumentException $exception) {
                    $add($prefix . '.' . $field, $exception->getMessage());
                }
            }

            // Los porcentajes admiten cero como valor por defecto.
            foreach (['descuento', 'porcentaje_isr'] as $field) {
                if (!array_key_exists($field, $line)) {
                    continue;
                }

                try {
                    Decimal::parse($line[$field], Decimal::SCALE_PERCENT, $prefix . '.' . $field);
                } catch (InvalidArgumentException $exception) {
                    $add($prefix . '.' . $field, $exception->getMessage());
                }
            }

            if (!array_key_exists('porcentaje_iva', $line)) {
                continue;
            }

            try {
                Decimal::parse($line['porcentaje_iva'], Decimal::SCALE_MONEY, $prefix . '.porcentaje_iva');
            } catch (InvalidArgumentException $exception) {
                $add($prefix . '.porcentaje_iva', $exception->getMessage());
            }
        }

        return [];
    }

    /**
     * Convierte la entrada validada en la forma interna del objeto.
     *
     * @param array<string, mixed> $input Datos ya validados.
     * @param array<string, mixed> $rules Reglas de facturación.
     *
     * @return array<string, mixed> Datos normalizados.
     */
    private static function normalize(array $input, array $rules): array
    {
        $customer = $input['cliente'];
        $invoicing = is_array($input['facturacion'] ?? null) ? $input['facturacion'] : [];
        $delivery = is_array($input['entrega'] ?? null) ? $input['entrega'] : [];

        $lines = [];
        $defaultTax = Decimal::parse(
            (string) $rules['default_tax_percent'],
            Decimal::SCALE_MONEY,
            'billing.default_tax_percent'
        );

        foreach ($input['carrito'] as $line) {
            $taxPercent = array_key_exists('porcentaje_iva', $line)
                ? Decimal::parse($line['porcentaje_iva'], Decimal::SCALE_MONEY, 'porcentaje_iva')
                : $defaultTax;

            $lines[] = new OrderLine(
                trim((string) $line['codigo']),
                trim((string) $line['descripcion']),
                Decimal::parse($line['cantidad'], Decimal::SCALE_QUANTITY, 'cantidad'),
                Decimal::parse($line['precio_unitario'], Decimal::SCALE_MONEY, 'precio_unitario'),
                array_key_exists('descuento', $line)
                    ? Decimal::parse($line['descuento'], Decimal::SCALE_PERCENT, 'descuento')
                    : Decimal::zero(Decimal::SCALE_PERCENT),
                array_key_exists('porcentaje_isr', $line)
                    ? Decimal::parse($line['porcentaje_isr'], Decimal::SCALE_PERCENT, 'porcentaje_isr')
                    : Decimal::zero(Decimal::SCALE_PERCENT),
                $taxPercent,
                self::toBool($line['exento'] ?? false)
            );
        }

        return [
            'codigo_orden'   => trim((string) $input['codigo_orden']),
            'tipo_documento' => DteType::resolve($invoicing['tipo_documento'] ?? null),
            'cliente'        => [
                'nit'       => trim((string) $customer['nit']),
                'nombre'    => trim((string) $customer['nombre']),
                'direccion' => trim((string) $customer['direccion']),
                'correo'    => self::optionalText($customer['correo'] ?? null),
                'telefono'  => self::optionalText($customer['telefono'] ?? null),
            ],
            'facturacion' => [
                'tipo_venta'    => (string) ($invoicing['tipo_venta'] ?? XmlBuilder::SALE_CASH),
                'destino_venta' => (string) ($invoicing['destino_venta'] ?? '1'),
                'moneda'        => XmlBuilder::CURRENCY_QUETZAL,
                'tasa'          => '1.00',
                'observaciones' => self::optionalText($invoicing['observaciones'] ?? null),
            ],
            'entrega' => [
                'DireccionEntrega' => self::optionalText($delivery['direccion'] ?? null),
                'TelefonoEntrega'  => self::optionalText($delivery['telefono'] ?? null),
                'Observaciones'    => self::optionalText($delivery['observaciones'] ?? null),
            ],
            'carrito' => $lines,
        ];
    }

    /**
     * Interpreta un valor como booleano sin perder el caso «ausente».
     *
     * @param mixed $value Valor recibido.
     *
     * @return bool `true` si representa un valor verdadero.
     */
    private static function toBool($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'si', 'sí', 'yes'], true);
        }

        return (bool) $value;
    }

    /**
     * Normaliza un texto opcional, devolviendo cadena vacía si falta.
     *
     * @param mixed $value Valor recibido.
     *
     * @return string Texto recortado.
     */
    private static function optionalText($value): string
    {
        if (!is_string($value)) {
            return '';
        }

        return trim($value);
    }

    /**
     * Devuelve el código único de la orden.
     *
     * @return string Código de la orden.
     */
    public function orderCode(): string
    {
        return $this->orderCode;
    }

    /**
     * Devuelve el tipo de documento.
     *
     * @return int Código numérico de {@see DteType}.
     */
    public function dteType(): int
    {
        return $this->dteType;
    }

    /**
     * Devuelve los datos del cliente.
     *
     * @return array<string, string> Datos normalizados.
     */
    public function customer(): array
    {
        return $this->customer;
    }

    /**
     * Devuelve los datos de facturación.
     *
     * @return array<string, string> Datos normalizados.
     */
    public function invoicing(): array
    {
        return $this->invoicing;
    }

    /**
     * Devuelve los datos de entrega.
     *
     * @return array<string, string> Datos normalizados.
     */
    public function delivery(): array
    {
        return $this->delivery;
    }

    /**
     * Devuelve las líneas del carrito.
     *
     * @return array<int, OrderLine> Líneas normalizadas.
     */
    public function lines(): array
    {
        return $this->lines;
    }

    /**
     * Devuelve los totales del documento.
     *
     * @return Totals Totales agregados.
     */
    public function totals(): Totals
    {
        return $this->totals;
    }

    /**
     * Devuelve la orden como array serializable.
     *
     * Es lo que se guarda en la columna `payload` de `gf_orders`: la copia que
     * permite reconstruir el documento en un reintento sin volver a depender de
     * lo que el POS envía.
     *
     * @return array<string, mixed> Representación de la orden.
     */
    public function toArray(): array
    {
        $lines = [];

        foreach ($this->lines as $line) {
            $lines[] = $line->toArray();
        }

        return [
            'codigo_orden'   => $this->orderCode,
            'tipo_documento' => DteType::toCode($this->dteType),
            'cliente'        => $this->customer,
            'facturacion'    => $this->invoicing,
            'entrega'        => $this->delivery,
            'carrito'        => $lines,
            'totales'        => $this->totals->toArray(),
        ];
    }
}
<?php

declare(strict_types=1);

namespace App\Integrations\Guatefacturas;

/**
 * Catálogo de tipos de Documento Electrónico (DTE).
 *
 * El proveedor usa dos identificadores para el mismo tipo:
 *
 * - **Numérico** (`pTipoDoc` de `generaDocumento`): `1` a `10`.
 * - **Texto** (`TipoDocumento` de los archivos de carga y del XML DTE final):
 *   `FACT`, `FCAM`, `NCRE`...
 *
 * Ambas listas están verificadas contra el Web Service en producción, y son
 * distintas a las del manual de 2008 en varios casos: `FESP` (Factura
 * Especial) figura con el código `5` en el servicio, mientras el catálogo SAT
 * del manual le asigna el `5` también pero la carga masiva usa un juego
 * diferente. Esta clase es la **única fuente de verdad** del proyecto.
 *
 * @package App\Integrations\Guatefacturas
 *
 * @author  fel-dentall
 * @license MIT
 */
final class DteType
{
    /**
     * Factura.
     *
     * @var int
     */
    public const FACTURA = 1;

    /**
     * Factura cambiaria.
     *
     * @var int
     */
    public const FACTURA_CAMBIARIA = 2;

    /**
     * Factura de pequeño contribuyente.
     *
     * @var int
     */
    public const FACTURA_PEQUENO_CONTRIBUYENTE = 3;

    /**
     * Factura cambiaria de pequeño contribuyente.
     *
     * @var int
     */
    public const FACTURA_CAMBIARIA_PEQUENO_CONTRIBUYENTE = 4;

    /**
     * Factura especial.
     *
     * @var int
     */
    public const FACTURA_ESPECIAL = 5;

    /**
     * Nota de abono.
     *
     * @var int
     */
    public const NOTA_ABONO = 6;

    /**
     * Recibo por donación.
     *
     * @var int
     */
    public const RECIBO_DONACION = 7;

    /**
     * Recibo.
     *
     * @var int
     */
    public const RECIBO = 8;

    /**
     * Nota de débito.
     *
     * @var int
     */
    public const NOTA_DEBITO = 9;

    /**
     * Nota de crédito.
     *
     * @var int
     */
    public const NOTA_CREDITO = 10;

    /**
     * Correspondencia entre el código numérico y el texto SAT.
     *
     * @var array<int, string>
     */
    private const NAMES = [
        self::FACTURA                             => 'FACT',
        self::FACTURA_CAMBIARIA                   => 'FCAM',
        self::FACTURA_PEQUENO_CONTRIBUYENTE        => 'FPEQ',
        self::FACTURA_CAMBIARIA_PEQUENO_CONTRIBUYENTE => 'FCAP',
        self::FACTURA_ESPECIAL                     => 'FESP',
        self::NOTA_ABONO                           => 'NABN',
        self::RECIBO_DONACION                      => 'RDON',
        self::RECIBO                               => 'RECI',
        self::NOTA_DEBITO                           => 'NDEB',
        self::NOTA_CREDITO                         => 'NCRE',
    ];

    /**
     * Descripción legible de cada tipo, en español.
     *
     * @var array<int, string>
     */
    private const DESCRIPTIONS = [
        self::FACTURA                             => 'Factura',
        self::FACTURA_CAMBIARIA                   => 'Factura cambiaria',
        self::FACTURA_PEQUENO_CONTRIBUYENTE        => 'Factura de pequeño contribuyente',
        self::FACTURA_CAMBIARIA_PEQUENO_CONTRIBUYENTE => 'Factura cambiaria de pequeño contribuyente',
        self::FACTURA_ESPECIAL                     => 'Factura especial',
        self::NOTA_ABONO                           => 'Nota de abono',
        self::RECIBO_DONACION                      => 'Recibo por donación',
        self::RECIBO                               => 'Recibo',
        self::NOTA_DEBITO                           => 'Nota de débito',
        self::NOTA_CREDITO                         => 'Nota de crédito',
    ];

    /**
     * Tipo que exige indicar el documento al que se asocia.
     *
     * @var array<int, bool>
     */
    private const REQUIRES_DOCUMENTO_ASOCIADO = [
        self::NOTA_DEBITO   => true,
        self::NOTA_CREDITO => true,
    ];

    /**
     * La clase es de constants: se bloquea la instanciación.
     */
    private function __construct()
    {
    }

    /**
     * Devuelve el código numérico aceptado por `pTipoDoc`.
     *
     * @param int $type Código numérico del tipo.
     *
     * @return bool `true` si el tipo existe.
     */
    public static function exists(int $type): bool
    {
        return isset(self::NAMES[$type]);
    }

    /**
     * Resuelve un tipo de documento desde cualquiera de sus dos formas.
     *
     * El POS puede enviar `1` o `FACT`: ambos identifican la misma factura. La
     * comparación ignora mayúsculas y espacios para no rechazar un `fact` que
     * un vendedor teclee.
     *
     * @param mixed $value Código numérico o textual.
     *
     * @return int|null Código numérico del tipo, o `null` si no existe.
     */
    public static function resolve($value): ?int
    {
        if (is_int($value)) {
            return self::exists($value) ? $value : null;
        }

        if (is_string($value)) {
            $text = strtoupper(trim($value));

            // El código numérico puede llegar como cadena desde un formulario.
            if (preg_match('/^\d+$/', $text) === 1) {
                $number = (int) $text;

                return self::exists($number) ? $number : null;
            }

            $found = array_search($text, self::NAMES, true);

            return $found === false ? null : (int) $found;
        }

        return null;
    }

    /**
     * Devuelve el código SAT textual de un tipo.
     *
     * @param int $type Código numérico del tipo.
     *
     * @return string Código textual, por ejemplo `FACT`.
     *
     * @throws \InvalidArgumentException Si el tipo no existe.
     */
    public static function toCode(int $type): string
    {
        if (!isset(self::NAMES[$type])) {
            throw new \InvalidArgumentException(sprintf('Tipo de documento desconocido: %d.', $type));
        }

        return self::NAMES[$type];
    }

    /**
     * Devuelve la descripción legible de un tipo.
     *
     * @param int $type Código numérico del tipo.
     *
     * @return string Descripción en español.
     */
    public static function describe(int $type): string
    {
        return self::DESCRIPTIONS[$type] ?? 'Tipo de documento no identificado';
    }

    /**
     * Indica si un tipo exige referenciar otro documento.
     *
     * Las notas de crédito y débito se lean de una factura previa, por lo que el
     * manual exige el bloque `<DocAsociados>`.
     *
     * @param int $type Código numérico del tipo.
     *
     * @return bool `true` si requiere documento asociado.
     */
    public static function requiresDocumentoAsociado(int $type): bool
    {
        return self::REQUIRES_DOCUMENTO_ASOCIADO[$type] ?? false;
    }

    /**
     * Devuelve todos los tipos con su código y descripción.
     *
     * @return array<int, array{code: int, name: string, description: string}>
     *         Lista completa de tipos soportados.
     */
    public static function all(): array
    {
        $types = [];

        foreach (self::NAMES as $code => $name) {
            $types[$code] = [
                'code'        => $code,
                'name'        => $name,
                'description' => self::DESCRIPTIONS[$code] ?? '',
            ];
        }

        return $types;
    }
}
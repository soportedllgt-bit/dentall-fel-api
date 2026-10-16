<?php

declare(strict_types=1);

namespace App\Integrations\Guatefacturas;

use InvalidArgumentException;

/**
 * Constructor del documento XML que envía `generaDocumento`.
 *
 * ## Estructura real, no la del manual
 *
 * El manual técnico del proveedor es de 2008 y describe un esquema que el
 * servicio ya no acepta. Hay dos diferencias, ambas comprobadas enviando
 * documentos al sandbox:
 *
 * 1. **`TAG1` no aparece en el manual.** El manual muestra `<Detalles>` como
 *    hijo directo de `<DocElectronico>`. El servicio responde:
 *
 *    ```
 *    ERROR el TAG1 Detalles no existe o viene mal definido en el formato XML
 *    ```
 *
 * 2. **Cada línea es su propio `<Productos>`.** El nombre del elemento es
 *    plural, pero un solo `<Productos>` con varios `<Producto>` dentro se
 *    rechaza:
 *
 *    ```
 *    ERROR al grabar en productos carga ORA-01400: no se puede realizar una
 *    inserción NULL en ("FEL"."T63_PRODUCTOS_CARGA"."ID_UNIDAD")
 *    ```
 *
 *    Repetir `<Productos>` como elemento hermano, una vez por línea, sí se
 *    acepta. El error que produce la estructura incorrecta no dice nada de las
 *    líneas ni de la estructura: habla de una columna nula en una tabla
 *    interna, lo que la hace muy difícil de diagnosticar sin haberlo probado.
 *
 * La estructura que este generador produce, y que fue aceptada por el
 * proveedor, es:
 *
 * ```xml
 * <DocElectronico>
 *   <Encabezado>
 *     <Receptor>…</Receptor>
 *     <InfoDoc>…</InfoDoc>
 *     <Totales>…</Totales>
 *     <DatosAdicionales>…</DatosAdicionales>
 *   </Encabezado>
 *   <TAG1>
 *     <Detalles>
 *       <Productos>…</Productos>
 *       <Productos>…</Productos>
 *     </Detalles>
 *   </TAG1>
 * </DocElectronico>
 * ```
 *
 * ## Escapado
 *
 * Todos los textos provienen del POS y pueden contener `&`, `<`, `"` o el
 * mismo cierre de etiqueta. Se escapan con `htmlspecialchars()` y
 * `ENT_XML1`, que es la única variante correcta para XML: `ENT_HTML5` escaparía
 * además las comillas simples y produciría entidades que el proveedor no
 * acepta.
 *
 * @package App\Integrations\Guatefacturas
 *
 * @author  fel-dentall
 * @license MIT
 */
final class XmlBuilder
{
    /**
     * Código de moneda del quetzal, según el catálogo del proveedor.
     *
     * @var string
     */
    public const CURRENCY_QUETZAL = '1';

    /**
     * Tipo de venta: venta al contado.
     *
     * @var string
     */
    public const SALE_CASH = 'S';

    /**
     * Código de unidad de medida «unidades».
     *
     * @var string
     */
    public const UNIT_EACH = '1';

    /**
     * Datos del cliente.
     *
     * @var array<string, string>
     */
    private $customer;

    /**
     * Datos de la factura.
     *
     * @var array<string, string>
     */
    private $invoicing;

    /**
     * Datos de la entrega.
     *
     * @var array<string, string>
     */
    private $delivery;

    /**
     * Fecha de emisión en formato `DD/MM/YYYY`.
     *
     * @var string
     */
    private $date;

    /**
     * Referencia de la orden, que viaja como `<Referencia>`.
     *
     * @var string
     */
    private $reference;

    /**
     * Líneas del documento.
     *
     * @var array<int, OrderLine>
     */
    private $lines;

    /**
     * Totales del documento.
     *
     * @var Totals
     */
    private $totals;

    /**
     * Construye el generador a partir de una orden ya validada.
     *
     * @param array<string, string> $customer Datos del cliente.
     * @param array<string, string> $invoicing Datos de facturación.
     * @param array<string, string> $delivery  Datos de entrega.
     * @param string                $reference Referencia de la orden.
     * @param string                $date      Fecha de emisión `DD/MM/YYYY`.
     * @param array<int, OrderLine> $lines     Líneas del carrito.
     * @param Totals                $totals    Totales agregados.
     *
     * @throws InvalidArgumentException Si no hay líneas o la fecha no es válida.
     */
    public function __construct(
        array $customer,
        array $invoicing,
        array $delivery,
        string $reference,
        string $date,
        array $lines,
        Totals $totals
    ) {
        if ($lines === []) {
            throw new InvalidArgumentException('El documento debe tener al menos una línea.');
        }

        if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $date) !== 1) {
            throw new InvalidArgumentException('La fecha de emisión debe tener el formato DD/MM/AAAA.');
        }

        $this->customer   = $customer;
        $this->invoicing  = $invoicing;
        $this->delivery   = $delivery;
        $this->reference  = $reference;
        $this->date       = $date;
        $this->lines      = $lines;
        $this->totals     = $totals;
    }

    /**
     * Genera el documento XML completo.
     *
     * @return string Documento listo para enviar a `generaDocumento`.
     */
    public function build(): string
    {
        return '<DocElectronico>'
            . $this->buildHeader()
            . $this->buildDetails()
            . '</DocElectronico>';
    }

    /**
     * Construye el bloque `<Encabezado>`.
     *
     * @return string Contenido del encabezado.
     */
    private function buildHeader(): string
    {
        return '<Encabezado>'
            . $this->buildCustomer()
            . $this->buildDocumentInfo()
            . $this->buildTotals()
            . $this->buildAdditionalData()
            . '</Encabezado>';
    }

    /**
     * Construye el bloque `<Receptor>`.
     *
     * @return string Datos del cliente.
     */
    private function buildCustomer(): string
    {
        return '<Receptor>'
            . $this->element('NITReceptor', $this->customer['nit'])
            . $this->element('Nombre', $this->customer['nombre'])
            . '<Direccion>'
            . $this->element('Direccion', $this->customer['direccion'])
            . '</Direccion>'
            . $this->element('Correo', $this->customer['correo'])
            . $this->element('Telefono', $this->customer['telefono'])
            . '</Receptor>';
    }

    /**
     * Construye el bloque `<InfoDoc>`.
     *
     * @return string Datos de la factura.
     */
    private function buildDocumentInfo(): string
    {
        return '<InfoDoc>'
            . $this->element('TipoVenta', $this->invoicing['tipo_venta'])
            . $this->element('DestinoVenta', $this->invoicing['destino_venta'])
            . $this->element('Fecha', $this->date)
            . $this->element('Moneda', $this->invoicing['moneda'])
            . $this->element('Tasa', $this->invoicing['tasa'])
            . $this->element('Referencia', $this->reference)
            . $this->element('Observaciones', $this->invoicing['observaciones'])
            . '</InfoDoc>';
    }

    /**
     * Construye el bloque `<Totales>`.
     *
     * @return string Totales del documento.
     */
    private function buildTotals(): string
    {
        // Los totales de cabecera se calculan sobre los importes que espera el
        // proveedor, no sobre los de negocio: la cabecera tiene que cuadrar con la
        // suma de las líneas, y el proveedor lo comprueba.
        $importes = ImportesProveedor::totals($this->lines);

        return '<Totales>'
            . $this->element('Bruto', $importes['bruto']->toString())
            . $this->element('Descuento', $importes['descuento']->toString())
            . $this->element('Exento', $importes['exento']->toString())
            . $this->element('Otros', $importes['otros']->toString())
            . $this->element('Neto', $importes['neto']->toString())
            . $this->element('Isr', '0.00')
            . $this->element('Iva', $importes['iva']->toString())
            . $this->element('Total', $importes['total']->toString())
            . '</Totales>';
    }

    /**
     * Construye el bloque `<DatosAdicionales>`.
     *
     * Es donde viaja la información de entrega. El proveedor no tiene un bloque
     * específico para ella, y el DTE firmado por SAT no debe alterarse con datos
     * que no pertenecen a la factura: `<DatosAdicionales>` es el lugar previsto
     * para información que acompaña al documento sin formar parte de él.
     *
     * El bloque se omite por completo cuando no hay nada que enviar: un
     * `<DatosAdicionales>` vacío puede ser rechazado por el validador.
     *
     * @return string Datos adicionales o cadena vacía.
     */
    private function buildAdditionalData(): string
    {
        $entries = [];

        foreach ($this->delivery as $key => $value) {
            $value = trim($value);

            if ($value !== '') {
                $entries[] = $this->element($key, $value);
            }
        }

        if ($entries === []) {
            return '';
        }

        return '<DatosAdicionales>' . implode('', $entries) . '</DatosAdicionales>';
    }

    /**
     * Construye el bloque `<TAG1>` con una entrada `<Productos>` por línea.
     *
     * @return string Detalle del documento.
     */
    private function buildDetails(): string
    {
        $products = '';

        foreach ($this->lines as $line) {
            $products .= '<Productos>' . $this->buildProduct($line) . '</Productos>';
        }

        return '<TAG1><Detalles>' . $products . '</Detalles></TAG1>';
    }

    /**
     * Construye el contenido de una entrada `<Productos>`.
     *
     * @param OrderLine $line Línea a serializar.
     *
     * @return string Campos de la línea.
     */
    /**
     * Construye el contenido de una entrada `<Productos>`.
     *
     * El precio y los importes viajan en la representación del proveedor: el precio
     * unitario lleva el IVA incluido y `ImpBruto` es el resultado de multiplicarlo
     * por la cantidad. Véase {@see ImportesProveedor}, que documenta la regla con el
     * ejemplo de la guía operativa.
     *
     * @param OrderLine $line Línea a serializar.
     *
     * @return string Campos de la línea.
     */
    private function buildProduct(OrderLine $line): string
    {
        $importes = ImportesProveedor::line($line);

        return $this->element('Producto', $line->code())
            . $this->element('Descripcion', $line->description())
            . $this->element('Medida', self::UNIT_EACH)
            . $this->element('Cantidad', $line->quantity()->toString())
            . $this->element('Precio', $importes['precio']->toString())
            . $this->element('PorcDesc', $line->discountPercent()->toString())
            . $this->element('PorcOthers', $line->otherPercent()->toString())
            . $this->element('ImpBruto', $importes['bruto']->toString())
            . $this->element('ImpDescuento', $importes['descuento']->toString())
            . $this->element('ImpOtros', $importes['otros']->toString())
            . $this->element('ImpExento', $importes['exento']->toString())
            . $this->element('ImpNeto', $importes['neto']->toString())
            . $this->element('ImpIsr', '0.00')
            . $this->element('ImpIva', $importes['iva']->toString())
            . $this->element('ImpTotal', $importes['total']->toString());
    }

    /**
     * Emite un elemento escapando su contenido.
     *
     * `ENT_XML1` es la variante correcta para XML: escapa `&`, `<`, `>` y `"`,
     * que son los caracteres que romperían el documento o cerrarían un atributo.
     *
     * @param string $name    Nombre del elemento.
     * @param string $content Contenido ya escapado.
     *
     * @return string Elemento completo.
     */
    private function element(string $name, string $content): string
    {
        return sprintf('<%s>%s</%s>', $name, $this->escape($content), $name);
    }

    /**
     * Escapa un texto para insertarlo en el documento.
     *
     * @param string $text Texto sin escapar.
     *
     * @return string Texto escapado.
     */
    private function escape(string $text): string
    {
        // El documento se declara en UTF-8; sin esta marca, un nombre con
        // acento o una ñ llega corrupto al proveedor.
        return htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
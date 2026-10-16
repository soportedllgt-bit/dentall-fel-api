<?php

declare(strict_types=1);

namespace App\Integrations\Guatefacturas;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Digest de la respuesta de `generaDocumento`.
 *
 * Convierte el XML que devuelve el proveedor en una estructura lista para
 * entregar al POS, sin exponer el XML crudo salvo cuando se solicita el modo
 * detalle.
 *
 * Formato verificado contra el servicio real, modo resumen (`pTipoRespuesta=R`):
 *
 * ```xml
 * <Resultado>
 *   <Serie>BADD0CD3</Serie>
 *   <Preimpreso>4067575726</Preimpreso>
 *   <Nombre>...</Nombre>
 *   <Direccion>...</Direccion>
 *   <Telefono></Telefono>
 *   <NumeroAutorizacion>BADD0CD3-F272-47AE-B3F3-66627DE68250</NumeroAutorizacion>
 *   <Referencia>IDEMPOTENCIA-TEST-0001</Referencia>
 * </Resultado>
 * ```
 *
 * @package App\Integrations\Guatefacturas
 *
 * @author  fel-dentall
 * @license MIT
 */
final class DocumentResult
{
    /**
     * Serie autorizada por SAT.
     *
     * @var string
     */
    private $serie;

    /**
     * Número correlativo del documento.
     *
     * @var string
     */
    private $preimpreso;

    /**
     * Número de autorización (UUID) entregado por SAT.
     *
     * @var string
     */
    private $autorizacion;

    /**
     * Referencia enviada por el emisor, que permite recuperar el documento.
     *
     * @var string
     */
    private $referencia;

    /**
     * Nombre del cliente, según el proveedor.
     *
     * @var string|null
     */
    private $nombre;

    /**
     * Dirección del cliente, según el proveedor.
     *
     * @var string|null
     */
    private $direccion;

    /**
     * Teléfono del cliente.
     *
     * @var string|null
     */
    private $telefono;

    /**
     * XML DTE firmado, solo en modo detalle.
     *
     * @var string|null
     */
    private $signedXml;

    /**
     * @param string      $serie       Serie autorizada.
     * @param string      $preimpreso  Número correlativo.
     * @param string      $autorizacion Número de autorización de SAT.
     * @param string      $referencia  Referencia del emisor.
     * @param string|null $nombre      Nombre del cliente.
     * @param string|null $direccion   Dirección del cliente.
     * @param string|null $telefono    Teléfono del cliente.
     * @param string|null $signedXml   XML DTE firmado, si se solicitó detalle.
     */
    public function __construct(
        string $serie,
        string $preimpreso,
        string $autorizacion,
        string $referencia,
        ?string $nombre = null,
        ?string $direccion = null,
        ?string $telefono = null,
        ?string $signedXml = null
    ) {
        $this->serie       = $serie;
        $this->preimpreso  = $preimpreso;
        $this->autorizacion = $autorizacion;
        $this->referencia  = $referencia;
        $this->nombre      = $nombre;
        $this->direccion   = $direccion;
        $this->telefono    = $telefono;
        $this->signedXml   = $signedXml;
    }

    /**
     * Interpreta el texto devuelto por `generaDocumento`.
     *
     * @param string $raw Texto del elemento `<result>`.
     *
     * @return self Digest de la respuesta.
     *
     * @throws \App\Integrations\Guatefacturas\ProviderBusinessException Si el XML es inesperado.
     */
    public static function fromResult(string $raw): self
    {
        // En modo detalle el proveedor incrusta una declaración XML al inicio del
        // contenido, lo que hace que `<Resultado>` no sea un elemento XML válido.
        $document = self::loadXml(self::stripInnerDeclaration($raw));

        if ($document === null) {
            throw new ProviderBusinessException($raw);
        }

        $xpath = new DOMXPath($document);

        $serie        = self::text($xpath, '//*[local-name()="Serie"]');
        $preimpreso   = self::text($xpath, '//*[local-name()="Preimpreso"]');
        $autorizacion = self::text($xpath, '//*[local-name()="NumeroAutorizacion"]');
        $referencia   = self::text($xpath, '//*[local-name()="Referencia"]');

        // En modo detalle (`pTipoRespuesta=D`) no hay etiquetas <Serie> ni
        // <Preimpreso>: la serie y el número viajan como atributos de
        // `dte:NumeroAutorizacion`, y el UUID es su contenido de texto.
        $detail = self::detailAttributes($xpath);

        if ($serie === '' && isset($detail['serie'])) {
            $serie        = $detail['serie'];
            $preimpreso   = $detail['numero'];
            $autorizacion = $autorizacion === '' ? $detail['uuid'] : $autorizacion;
        }

        // Sin serie ni autorización no hay documento emitido: la respuesta no
        // tiene el formato de un éxito aunque no diga ERROR.
        if ($serie === '' && $autorizacion === '') {
            throw new ProviderBusinessException($raw);
        }

        // En modo detalle, el XML DTE firmado va incrustado dentro de <Resultado>.
        // Se localiza por nombre local: el prefijo `dte` solo está declarado en
        // el elemento raíz del XML incrustado y DOMXPath no lo reconoce desde
        // fuera.
        $signedXml = null;
        $nodes     = $xpath->query("//*[local-name()='GTDocumento']");

        if ($nodes !== false && $nodes->length > 0) {
            $gtDocument = $nodes->item(0);

            if ($gtDocument instanceof DOMElement) {
                $signedXml = $document->saveXML($gtDocument);
            }
        }

        return new self(
            $serie,
            $preimpreso,
            $autorizacion,
            $referencia,
            self::text($xpath, '//*[local-name()="NombreReceptor"]'),
            self::text($xpath, '//*[local-name()="DireccionReceptor"]/*[local-name()="Direccion"]'),
            self::text($xpath, '//*[local-name()="Telefono"]'),
            $signedXml
        );
    }

    /**
     * Extrae los atributos de certificación del XML DTE firmado.
     *
     * En modo detalle, `dte:NumeroAutorizacion` lleva la serie y el número
     * correlativo como atributos, y el UUID de autorización como contenido.
     *
     * @param DOMXPath $xpath Contexto de búsqueda.
     *
     * @return array{serie: string, numero: string, uuid: string}|null
     *         Atributos de certificación o `null` si no aparecen.
     */
    private static function detailAttributes(DOMXPath $xpath): ?array
    {
        $nodes = $xpath->query("//*[local-name()='NumeroAutorizacion']");

        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        $node = $nodes->item(0);

        if (!$node instanceof DOMElement || !$node->hasAttribute('Serie')) {
            return null;
        }

        return [
            'serie'  => trim($node->getAttribute('Serie')),
            'numero' => trim($node->getAttribute('Numero')),
            'uuid'   => trim((string) $node->textContent),
        ];
    }

    /**
     * Retira una declaración XML incrustada dentro de otro elemento.
     *
     * El proveedor anida una declaración XML antes del elemento `dte:GTDocumento`.
     * Una declaración solo es válida al principio del documento, por lo que
     * `loadXML` rechaza el resultado sin retirarla antes.
     *
     * @param string $raw Texto de la respuesta.
     *
     * @return string Texto sin la declaración incrustada.
     */
    private static function stripInnerDeclaration(string $raw): string
    {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument();
        $loaded   = $document->loadXML($raw, LIBXML_NONET | LIBXML_NOENT);

        if ($loaded) {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);

            return $raw;
        }

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        // Se retira la declaración que aparece tras la apertura de <Resultado>.
        $cleaned = (string) preg_replace('/(<Resultado[^>]*>)\s*<\?xml[^?]*\?>/i', '$1', $raw, 1);

        return $cleaned === '' ? $raw : $cleaned;
    }

    /**
     * Devuelve la serie autorizada.
     *
     * @return string Serie del documento.
     */
    public function serie(): string
    {
        return $this->serie;
    }

    /**
     * Devuelve el número correlativo.
     *
     * @return string Número preimpreso.
     */
    public function preimpreso(): string
    {
        return $this->preimpreso;
    }

    /**
     * Devuelve el número de autorización entregado por SAT.
     *
     * @return string UUID de autorización.
     */
    public function autorizacion(): string
    {
        return $this->autorizacion;
    }

    /**
     * Devuelve la referencia usada para emitir.
     *
     * @return string Referencia del emisor.
     */
    public function referencia(): string
    {
        return $this->referencia;
    }

    /**
     * Devuelve el XML DTE firmado, si se solicitó el modo detalle.
     *
     * @return string|null XML firmado o `null` en modo resumen.
     */
    public function signedXml(): ?string
    {
        return $this->signedXml;
    }

    /**
     * Construye el identificador completo que se muestra al usuario.
     *
     * Es el dato que el vendedor necesita para referencia la factura, y el que
     * la usan los comprobantes de la SAT de Guatemala para validar la autorización.
     *
     * @return string Identificador con el formato `SERIE-PREIMPRESO`.
     */
    public function documentId(): string
    {
        if ($this->preimpreso === '') {
            return $this->serie;
        }

        return $this->serie . '-' . $this->preimpreso;
    }

    /**
     * Devuelve el digest como array serializable.
     *
     * @return array{
     *     serie: string, preimpreso: string, autorizacion: string,
     *     referencia: string, document_id: string, cliente: array|null,
     *     xml_firmado: string|null
     * }
     *         Representación pública de la respuesta.
     */
    public function toArray(): array
    {
        return [
            'serie'        => $this->serie,
            'preimpreso'   => $this->preimpreso,
            'autorizacion' => $this->autorizacion,
            'referencia'   => $this->referencia,
            'document_id'  => $this->documentId(),
            'cliente'      => $this->nombre === null ? null : [
                'nombre'    => $this->nombre,
                'direccion' => $this->direccion,
                'telefono'  => $this->telefono,
            ],
            'xml_firmado'  => $this->signedXml,
        ];
    }

    /**
     * Carga un XML de forma tolerante.
     *
     * @param string $raw Texto XML.
     *
     * @return DOMDocument|null Documento cargado o `null` si no es válido.
     */
    private static function loadXml(string $raw): ?DOMDocument
    {
        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        $document = new DOMDocument();

        // El proveedor puede devolver caracteres de control de la plantilla XML.
        $previous = libxml_use_internal_errors(true);
        $loaded   = $document->loadXML($raw, LIBXML_NONET | LIBXML_NOENT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $loaded ? $document : null;
    }

    /**
     * Devuelve el texto del primer nodo que casa con la expresión.
     *
     * @param DOMXPath $xpath     Contexto de búsqueda.
     * @param string   $expression Expresión XPath.
     *
     * @return string Texto recortado o cadena vacía.
     */
    private static function text(DOMXPath $xpath, string $expression): string
    {
        $nodes = $xpath->query($expression);

        if ($nodes === false || $nodes->length === 0) {
            return '';
        }

        $node = $nodes->item(0);

        return $node === null ? '' : trim((string) $node->textContent);
    }
}
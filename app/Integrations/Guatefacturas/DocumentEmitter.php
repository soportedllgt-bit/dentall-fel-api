<?php

declare(strict_types=1);

namespace App\Integrations\Guatefacturas;

/**
 * Contrato de emisión de documentos electrónicos.
 *
 * ## Por qué una interfaz
 *
 * `FacturacionService` depende de este contrato y no de `GuatefacturasClient`.
 * La clase real es `final` porque su comportamiento con SOAP y el WSDL es
 * delicado y no debe heredarse, pero el servicio necesita poder ejercitarse con
 * un emidor simulado.
 *
 * Sin esta separación, toda la matriz de fallos de HU-3 —caída del proveedor,
 * tiempo de espera agotado, respuesta vacía, límite de emisiones, rechazo de
 * negocio— solo podía probarse contra el sandbox real. Y el sandbox no permite
 * probarla: rechaza por throttle los documentos válidos que llegan encadenados,
 * de modo que una prueba de caída del servicio habría sido intermitente por
 * construcción y habría consumido emisiones reales en cada ejecución.
 *
 * Con el contrato, la matriz completa se ejecuta sin red y en milisegundos.
 *
 * @package App\Integrations\Guatefacturas
 *
 * @author  fel-dentall
 * @license MIT
 */
interface DocumentEmitter
{
    /**
     * Emite un documento electrónico.
     *
     * @param int    $documentType Código numérico del tipo ({@see DteType}).
     * @param string $xml          Documento XML con la estructura del proveedor.
     * @param string $responseType `R` para resumen o `D` para detalle.
     *
     * @return string Texto devuelto por el proveedor, sin procesar.
     *
     * @throws \App\Integrations\Guatefacturas\ConfigurationException Si la configuración no permite emitir.
     * @throws \App\Integrations\Guatefacturas\CommunicationException Si el proveedor no está disponible.
     * @throws \App\Integrations\Guatefacturas\ProviderBusinessException Si el proveedor rechaza el documento.
     */
    public function generaDocumento(
        int $documentType,
        string $xml,
        string $responseType = GuatefacturasConfig::RESPONSE_SUMMARY
    ): string;

    /**
     * Anula un documento electrónico ya emitido.
     *
     * A diferencia de la emisión, **no recibe el XML**: identifica el documento por
     * serie y preimpreso, y exige además el NIT del comprador, que el proveedor
     * inserta en una columna declarada `NOT NULL`.
     *
     * @param string $series        Serie autorizada.
     * @param string $preimpreso    Número correlativo autorizado.
     * @param string $buyerNit      NIT del comprador registrado en la orden.
     * @param string $annulmentDate Fecha de anulación en formato `AAAAMMDD`.
     * @param string $reason        Motivo de la anulación.
     *
     * @return string Texto devuelto por el proveedor, sin procesar.
     *
     * @throws \App\Integrations\Guatefacturas\ConfigurationException Si la configuración no permite emitir.
     * @throws \App\Integrations\Guatefacturas\CommunicationException Si el proveedor no está disponible.
     * @throws \App\Integrations\Guatefacturas\ProviderBusinessException Si el proveedor rechaza la anulación.
     */
    public function anulaDocumento(
        string $series,
        string $preimpreso,
        string $buyerNit,
        string $annulmentDate,
        string $reason
    ): string;
}
<?php

declare(strict_types=1);

namespace App\Integrations\Guatefacturas;

use SoapClient;
use SoapFault;

/**
 * Cliente SOAP de GUATEFACTURAS.
 *
 * Contrato verificado contra el WSDL del servicio (`feltestSB`):
 *
 * - Estilo `rpc`, codificación `literal`.
 * - **SOAP 1.1** (el espacio de nombres del binding es `wsdl/soap/`, no `soap12`).
 * - Espacio de nombres del servicio: `http://dbguatefac/Guatefac.wsdl`.
 * - `generaDocumento(pUsuario, pPassword, pNitEmisor, pEstablecimiento, pTipoDoc,
 *                    pIdMaquina, pTipoRespuesta, pXml) -> string`
 * - `anulaDocumento(pUsuario, pPassword, pNitEmisor, pSerie, pPreimpreso,
 *                   pNitComprador, pFechaAnulacion, pMotivoAnulacion) -> string`
 *
 * Comportamientos importantes, todos comprobados contra el servicio real:
 *
 * 1. **Los errores llegan con HTTP 200.** El rechazo viaja dentro de `<result>`,
 *    por lo que nunca se detecta por el código de estado.
 * 2. **`Referencia` garantiza idempotencia.** Reenviar el mismo XML con la misma
 *    referencia devuelve el documento ya emitido en lugar de crear otro. Es la
 *    base del reintento seguro.
 * 3. **La estructura XML real lleva un nivel que el manual no documenta:**
 *    `<TAG1>` envuelve a `<Detalles>`. El manual de 2008 muestra
 *    `<Detalles>` directamente bajo `<DocElectronico>` y el proveedor lo rechaza.
 *
 * ## Un `SoapFault` no significa rechazo
 *
 * PHP lanza `SoapFault` por igual ante un rechazo del emisor y ante un `502` de
 * una pasarela caída, un tiempo de espera agotado o un problema de DNS. Antes de
 * HU-3 cualquier `SoapFault` se traducía a `ProviderBusinessException` y la API
 * respondía `422 «El proveedor rechazó la factura»`: el vendedor veía un rechazo
 * de sus datos cuando en realidad el servicio estaba caído. La clasificación se
 * delega en {@see ProviderResponseClassifier}.
 *
 * @package App\Integrations\Guatefacturas
 *
 * @author  fel-dentall
 * @license MIT
 */
final class GuatefacturasClient implements DocumentEmitter
{
    /**
     * Espacio de nombres del servicio.
     *
     * @var string
     */
    public const SERVICE_NAMESPACE = 'http://dbguatefac/Guatefac.wsdl';

    /**
     * Límite propio del motivo de anulación.
     *
     * El proveedor admite 729 caracteres y a partir de 730 lanza `ORA-06502` como
     * fallo de la llamada SOAP. Se usa un tope más bajo y redondo para no depender
     * de un límite ajeno que además falla de forma poco elegante.
     *
     * @var int
     */
    public const MAX_ANNULMENT_REASON = 500;

    /**
     * Configuración del proveedor.
     *
     * @var GuatefacturasConfig
     */
    private $config;

    /**
     * Cliente SOAP perezoso, creado en la primera llamada.
     *
     * @var SoapClient|null
     */
    private $soap;

    /**
     * @param GuatefacturasConfig $config Configuración validada del proveedor.
     */
    public function __construct(GuatefacturasConfig $config)
    {
        $this->config = $config;
    }

    /**
     * Emite un documento electrónico.
     *
     * @param int    $documentType    Código numérico del tipo ({@see DteType}).
     * @param string $xml             Documento XML con la estructura del proveedor.
     * @param string $responseType    `R` para resumen o `D` para detalle.
     *
     * @return string Texto devuelto por el proveedor, sin procesar.
     *
     * @throws \App\Integrations\Guatefacturas\ConfigurationException Si faltan credenciales.
     * @throws \App\Integrations\Guatefacturas\CommunicationException Si hay fallo de red.
     * @throws \App\Integrations\Guatefacturas\ProviderBusinessException Si el proveedor rechaza.
     */
    public function generaDocumento(
        int $documentType,
        string $xml,
        string $responseType = GuatefacturasConfig::RESPONSE_SUMMARY
    ): string {
        if (!DteType::exists($documentType)) {
            throw new \InvalidArgumentException(sprintf(
                'Tipo de documento no soportado por el proveedor: %d.',
                $documentType
            ));
        }

        if (trim($xml) === '') {
            throw new \InvalidArgumentException('El documento XML no puede estar vacío.');
        }

        return $this->invoke('generaDocumento', [
            'pEstablecimiento' => $this->config->establishment(),
            'pTipoDoc'         => (string) $documentType,
            'pIdMaquina'       => GuatefacturasConfig::MACHINE_ID,
            'pTipoRespuesta'   => $responseType,
            'pXml'             => $xml,
        ]);
    }

    /**
     * Anula un documento electrónico ya emitido.
     *
     * @param string $series        Serie autorizada.
     * @param string $preimpreso    Número correlativo autorizado.
     * @param string $buyerNit      NIT del comprador.
     * @param string $annulmentDate Fecha en formato `AAAAMMDD`.
     * @param string $reason        Motivo de la anulación.
     *
     * @return string Texto devuelto por el proveedor, sin procesar.
     *
     * @throws \App\Integrations\Guatefacturas\ConfigurationException Si faltan credenciales.
     * @throws \App\Integrations\Guatefacturas\CommunicationException Si hay fallo de red.
     * @throws \App\Integrations\Guatefacturas\ProviderBusinessException Si el proveedor rechaza.
     */
    public function anulaDocumento(
        string $series,
        string $preimpreso,
        string $buyerNit,
        string $annulmentDate,
        string $reason
    ): string {
        if (preg_match('/^\d{8}$/', $annulmentDate) !== 1) {
            throw new \InvalidArgumentException(
                'La fecha de anulación debe tener formato AAAAMMDD.'
            );
        }

        if (trim($series) === '' || trim($preimpreso) === '') {
            throw new \InvalidArgumentException(
                'La anulación necesita la serie y el preimpreso del documento.'
            );
        }

        // El proveedor inserta el NIT del comprador en una columna declarada
        // NOT NULL: vacío produce `ORA-01400` y la llamada falla por excepción, no
        // con un mensaje de negocio. Se avala en cliente para no gastar una
        // llamada en un error que ya se conoce.
        if (trim($buyerNit) === '') {
            throw new \InvalidArgumentException(
                'La anulación necesita el NIT del comprador registrado en la orden.'
            );
        }

        // La columna del motivo admite 729 caracteres en el proveedor; a partir de
        // ahí Oracle lanza `ORA-06502` y la llamada SOAP falla por excepción. Se
        // deja margen respecto de ese límite.
        if (mb_strlen($reason, 'UTF-8') > self::MAX_ANNULMENT_REASON) {
            throw new \InvalidArgumentException(sprintf(
                'El motivo de anulación no puede superar los %d caracteres.',
                self::MAX_ANNULMENT_REASON
            ));
        }

        return $this->invoke('anulaDocumento', [
            'pSerie'          => $series,
            'pPreimpreso'     => $preimpreso,
            'pNitComprador'   => $buyerNit,
            'pFechaAnulacion' => $annulmentDate,
            'pMotivoAnulacion' => $reason,
        ]);
    }

    /**
     * Inserta o actualiza un catálogo del proveedor.
     *
     * @param int    $catalog     1 unidades de medida, 2 productos, 3 clientes.
     * @param bool   $update      `true` para actualizar registros existentes.
     * @param string $xml         Contenido del catálogo en formato del proveedor.
     *
     * @return string Texto devuelto por el proveedor, sin procesar.
     *
     * @throws \App\Integrations\Guatefacturas\ConfigurationException Si faltan credenciales.
     * @throws \App\Integrations\Guatefacturas\CommunicationException Si hay fallo de red.
     * @throws \App\Integrations\Guatefacturas\ProviderBusinessException Si el proveedor rechaza.
     */
    public function actualizaCatalogos(int $catalog, bool $update, string $xml): string
    {
        if ($catalog < 1 || $catalog > 3) {
            throw new \InvalidArgumentException(
                'El catálogo debe ser 1 (unidades), 2 (productos) o 3 (clientes).'
            );
        }

        return $this->invoke('actualizaCatalogos', [
            'pCatalogo'   => (string) $catalog,
            'pActualizar' => $update ? 'S' : 'N',
            'pXml'        => $xml,
        ]);
    }

    /**
     * Ejecuta una operación del servicio.
     *
     * @param string              $operation Nombre de la operación SOAP.
     * @param array<string, mixed> $parameters Parámetros específicos.
     *
     * @return string Texto devuelto en `<result>`.
     *
     * @throws \App\Integrations\Guatefacturas\ConfigurationException Si faltan credenciales.
     * @throws \App\Integrations\Guatefacturas\CommunicationException Si hay fallo de red.
     * @throws \App\Integrations\Guatefacturas\ProviderBusinessException Si el proveedor rechaza.
     */
    private function invoke(string $operation, array $parameters): string
    {
        $this->config->assertComplete();

        $arguments = array_merge([
            'pUsuario'   => $this->config->username(),
            'pPassword'  => $this->config->password(),
            'pNitEmisor' => $this->config->nitEmisor(),
        ], $parameters);

        try {
            // Los argumentos se pasan como array plano, NO anidado en una lista.
            // Con estilo `rpc`, `[$args]` produce una petición mal formada: el
            // proveedor responde `ERRORES:ORA-01403: No se ha encontrado ningún
            // dato` en lugar de procesar la operación. Verificado empíricamente.
            $result = $this->soap()->__soapCall($operation, $arguments);
        } catch (CommunicationException $exception) {
            throw $exception;
        } catch (SoapFault $fault) {
            // Un `SoapFault` no distingue un rechazo de una caída del servicio:
            // ambos llegan como excepción de SOAP. El mensaje es el único dato
            // disponible, así que se examina antes de decidir.
            $detail = $this->describeFault($fault);

            if (ProviderResponseClassifier::isTransportMessage($detail)) {
                throw new CommunicationException(
                    CommunicationException::KIND_TIMEOUT,
                    $detail
                );
            }

            throw ProviderBusinessException::fromResult($detail);
        }

        $text = $this->sanitize($this->extractResult($result));

        // El proveedor envuelve siempre su respuesta en `<Resultado>`, de modo
        // que el error no aparece al inicio de la cadena. Se quita el envoltorio
        // antes de decidir, pero se devuelve el texto original porque
        // `DocumentResult` necesita un XML con raíz única.
        $outcome = ProviderResponseClassifier::classify($text);

        if ($operation === 'anulaDocumento') {
            // Las respuestas de anulación no comparten vocabulario con las de
            // emisión: un documento inexistente produce un mensaje sin código ni
            // la palabra `ERROR` que las reglas generales tomarían por éxito.
            if (ProviderResponseClassifier::isAnnulmentRejection($text)) {
                throw ProviderBusinessException::fromResult($text);
            }

            return $text;
        }

        if ($outcome === ProviderResponseClassifier::OUTCOME_RATE_LIMITED) {
            // No se afirma que el documento sea incorrecto: el mismo mensaje
            // aparece al superar el límite de emisiones, y en ese caso el XML es
            // válido. La orden queda reintentable y sin daño posible.
            throw new CommunicationException(
                CommunicationException::KIND_RATE_LIMITED,
                sprintf(
                    'El proveedor respondió "%s", que también aparece cuando se '
                    . 'supera el límite de emisiones. No se puede concluir si la '
                    . 'factura se emitió.',
                    $this->unwrapResult($text)
                )
            );
        }

        if ($outcome === ProviderResponseClassifier::OUTCOME_EMPTY) {
            // `<Resultado></Resultado>` o una respuesta cortada: el proveedor no
            // afirma nada, así que tampoco se puede afirmar que no emitiera.
            throw new CommunicationException(
                CommunicationException::KIND_TIMEOUT,
                'El proveedor devolvió una respuesta vacía. No se puede determinar '
                . 'si el documento fue emitido.'
            );
        }

        if ($outcome === ProviderResponseClassifier::OUTCOME_REJECTED) {
            throw ProviderBusinessException::fromResult($text);
        }

        return $text;
    }

    /**
     * Extrae el mensaje útil de un `SoapFault`, combinando sus campos.
     *
* `faultstring` contiene el motivo del servidor y `getMessage()` el error
     * de PHP, que puede traer la URL con las credenciales. Se concatenan para
     * no perder ninguno de los dos y se sanean antes de propagarse.
     *
     * @param SoapFault $fault Fallo lanzado por `SoapClient`.
     *
     * @return string Detalle del fallo, sin secretos.
     */
    private function describeFault(SoapFault $fault): string
    {
        $faultString = isset($fault->faultstring) ? (string) $fault->faultstring : '';
        $faultCode   = isset($fault->faultcode) ? (string) $fault->faultcode : '';
        $detail      = trim($fault->getMessage());

        // `faultstring` suele ser más informativo, pero puede estar vacío en los
        // fallos generados en cliente (WSDL ilegible, por ejemplo).
        if ($faultString !== '' && strpos($detail, $faultString) === false) {
            $detail = $detail === '' ? $faultString : $detail . ' | ' . $faultString;
        }

        if ($faultCode !== '') {
            $detail .= sprintf(' [codigo %s]', $faultCode);
        }

        return $this->sanitize($detail);
    }

    /**
     * Quita el envoltorio `<Resultado>` de la respuesta del proveedor.
     *
     * El servicio responde siempre `<Resultado>...</Resultado>`, tanto si emite
     * el documento como si lo rechaza. Sin quitarlo, una expresión anclada al
     * principio no detectaría el error.
     *
     * @param string $text Texto devuelto por el proveedor.
     *
     * @return string Contenido interno de `<Resultado>`.
     */
    private function unwrapResult(string $text): string
    {
        $trimmed = trim($text);

        if ($trimmed === '' || stripos($trimmed, '<resultado') === false) {
            return $trimmed;
        }

        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument();
        $loaded   = $document->loadXML($trimmed, LIBXML_NONET | LIBXML_NOENT);

        if ($loaded) {
            $content = trim((string) $document->documentElement->textContent);

            if ($content !== '') {
                libxml_clear_errors();
                libxml_use_internal_errors($previous);

                return $content;
            }
        }

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $trimmed;
    }

    /**
     * Extrae el texto de `<result>` de lo devuelto por `__soapCall`.
     *
     * Con este WSDL (`rpc` + `literal`) SoapClient devuelve directamente la
     * cadena de `<result>`. Se conserva el manejo de array porque el mismo
     * cliente devuelve un array si el servidor cambiara a `SOAP_1_2`, donde la
     * parte de salida llega calificada con el espacio de nombres.
     *
     * @param mixed $result Valor devuelto por `__soapCall`.
     *
     * @return string Texto de la respuesta, o cadena vacía si no hay contenido.
     */
    private function extractResult($result): string
    {
        if (is_string($result)) {
            return $result;
        }

        if (!is_array($result) || $result === []) {
            return '';
        }

        // El WSDL declara la parte de salida con el nombre `result`.
        if (isset($result['result']) && is_scalar($result['result'])) {
            return (string) $result['result'];
        }

        foreach ($result as $key => $value) {
            if (is_scalar($value) && preg_match('/(?:#result|result)$/i', (string) $key) === 1) {
                return (string) $value;
            }
        }

        return '';
    }

    /**
     * Construye el cliente SOAP, o lo reutiliza.
     *
     * @return SoapClient Cliente configurado con HTTP Basic y timeouts.
     *
     * @throws \App\Integrations\Guatefacturas\ConfigurationException Si el WSDL no es válido.
     */
    private function soap(): SoapClient
    {
        if ($this->soap instanceof SoapClient) {
            return $this->soap;
        }

        $this->prepareCacheDirectory();

        try {
            $this->soap = new SoapClient($this->config->wsdlUrl(), [
                'login'    => $this->config->basicUsername(),
                'password' => $this->config->basicPassword(),
                'auth'     => SOAP_AUTHENTICATION_BASIC,
                'trace'    => false,
                'exceptions' => true,
                'connection_timeout' => $this->config->connectionTimeout(),
                'cache_wsdl'       => WSDL_CACHE_BOTH,
                'keep_alive'        => false,
                'user_agent'        => 'fel-dentall-api/1.0',
            ]);
        } catch (SoapFault $fault) {
            // Un SoapFault puede incluir la URL con las credenciales en la cadena
            // de autenticación; se sanea antes de propagarlo.
            throw ConfigurationException::serviceUnavailable(
                $this->sanitize($fault->getMessage())
            );
        } catch (\Throwable $exception) {
            throw ConfigurationException::serviceUnavailable(
                $this->describeThrowable($exception)
            );
        }

        return $this->soap;
    }

    /**
     * Asegura que el caché de WSDL apunte a un directorio escribible.
     *
     * XAMPP y muchos hostings definen `soap.wsdl_cache_dir = /tmp`, una ruta de
     * Unix que no existe en Windows: la caché falla y el cliente no puede
     * descargar el WSDL. Se redirige al directorio temporal del sistema antes
     * de construir el cliente.
     *
     * @return void
     */
    private function prepareCacheDirectory(): void
    {
        $configured = (string) ini_get('soap.wsdl_cache_dir');

        // Una ruta absoluta existente sirve; en Windows, `/tmp` no es absoluta.
        $isUsable = $configured !== ''
            && $configured[0] === '/'
            && is_dir($configured)
            && DIRECTORY_SEPARATOR === '/';

        if ($isUsable) {
            return;
        }

        $fallback = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fel-dentall-wsdl';

        if (!is_dir($fallback)) {
            @mkdir($fallback, 0700, true);
        }

        if (is_dir($fallback) && is_writable($fallback)) {
            ini_set('soap.wsdl_cache_dir', $fallback);
        } else {
            // Sin directorio escribible, se desactiva la caché antes que dejar
            // que cada petición vuelva a descargar el WSDL.
            ini_set('soap.wsdl_cache_ttl', '0');
        }
    }

    /**
     * Elimina de un texto cualquier credencial conocida.
     *
     * @param string $message Texto a limpiar.
     *
     * @return string Texto sin secretos.
     */
    private function sanitize(string $message): string
    {
        $scrubber = new GuatefacturasException('');
        $scrubber->setSecrets([
            $this->config->username(),
            $this->config->password(),
            $this->config->basicUsername(),
            $this->config->basicPassword(),
        ]);

        return $scrubber->scrub($message);
    }

    /**
     * Describe una excepción eliminándole las credenciales.
     *
     * @param \Throwable $exception Excepción original.
     *
     * @return string Descripción segura.
     */
    private function describeThrowable(\Throwable $exception): string
    {
        return $this->sanitize($exception->getMessage());
    }
}

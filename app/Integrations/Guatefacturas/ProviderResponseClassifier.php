<?php

declare(strict_types=1);

namespace App\Integrations\Guatefacturas;

/**
 * Clasificador de respuestas del proveedor.
 *
 * ## Por qué existe
 *
 * El proveedor reporta sus errores de tres maneras distintas y cada una obliga a
 * una decisión diferente:
 *
 * | Entrada                                        | Significado real                  | Decisión |
 * |------------------------------------------------|-----------------------------------|----------|
 * | `<Resultado><Serie>…</Resultado></Resultado>`   | Documento emitido y autorizado    | Éxito    |
 * | `<Resultado>56-FECHA …</Resultado>`             | Rechazo de negocio, definitivo    | `422`    |
 * | `<Resultado>82-ERROR CALCULO …</Resultado>`     | **Ambiguo**                      | `503`    |
 * | `<Resultado></Resultado>` o respuesta cortada  | Conversación rota                | `503`    |
 * | `SoapFault` de HTTP o de red                   | Caída del servicio                | `503`    |
 *
 * La cuarta fila es la que obliga a este diseño. Un `SoapFault` **no significa
 * rechazo**: PHP lo lanza también cuando el proveedor devuelve un `5xx`, cuando
 * expira el tiempo de espera o cuando no hay conexión. Antes de HU-3 esos casos
 * se traducían a `ProviderBusinessException` y la API respondía
 * `422 «El proveedor rechazó la factura»`, culpando al documento de un fallo de
 * infraestructura. El vendedor veía sus datos tachados y sin cambio alguno.
 *
 * ## El error 82 no significa lo que dice
 *
 * Se comprobó contra el sandbox que `82-ERROR CALCULO DE IMPORTE BRUTO
 * INCORRECTO` aparece en dos situaciones que no se pueden distinguir por el
 * mensaje:
 *
 * 1. El importe bruto enviado es realmente incorrecto.
 * 2. Se ha superado el límite de emisiones del periodo.
 *
 * En el caso (2) el documento es válido y el rechazo es falso. Reenviar el
 * **mismo XML** almacenado es seguro en ambos casos: si el error era real,
 * vuelve a fallar igual y no se duplica nada, porque la `Referencia` es la misma.
 * Por eso el error 82 se clasifica como ambiguo y no como rechazo.
 *
 * Esta clase no contiene estado ni efectos: es una función pura del texto
 * recibido, lo que permite ejercitar toda la matriz de fallos sin red.
 *
 * @package App\Integrations\Guatefacturas
 *
 * @author  fel-dentall
 * @license MIT
 */
final class ProviderResponseClassifier
{
    /**
     * El proveedor emitió y autorizó el documento.
     *
     * @var string
     */
    public const OUTCOME_ISSUED = 'EMITIDO';

    /**
     * Rechazo de negocio: sabemos con certeza que no se emitió nada.
     *
     * @var string
     */
    public const OUTCOME_REJECTED = 'RECHAZO';

    /**
     * Rechazo que puede ser real o un límite de emisiones. No concluyente.
     *
     * @var string
     */
    public const OUTCOME_RATE_LIMITED = 'LIMITE_EMISION';

    /**
     * Respuesta vacía o truncada: no se puede saber si se emitió.
     *
     * @var string
     */
    public const OUTCOME_EMPTY = 'RESPUESTA_VACIA';

    /**
     * Código del proveedor que corresponde a un importe bruto incorrecto.
     *
     * @var string
     */
    public const CODE_GROSS_MISMATCH = '82';

    /**
     * La clase es de uso estático: se bloquea la instanciación.
     */
    private function __construct()
    {
    }

    /**
     * Clasifica lo que el proveedor devolvió dentro de `<Resultado>`.
     *
     * El envoltorio `<Resultado>` se retira antes de analizar: el proveedor
     * responde siempre `<Resultado>…</Resultado>`, tanto si emite como si
     * rechaza, y sin retirarlo ninguna regla anclada al inicio del texto
     * detectaría el error.
     *
     * @param string $raw Texto devuelto por el proveedor.
     *
     * @return string Una de las constantes `OUTCOME_*`.
     */
    public static function classify(string $raw): string
    {
        $text = self::unwrap($raw);

        if (trim($text) === '') {
            return self::OUTCOME_EMPTY;
        }

        // El proveedor tiene tres formas de comunicar un rechazo y las tres se han
        // visto contra el servicio real:
        //
        //   56-FECHA DE DOCUMENTO NO PUEDE SER MAYOR...
        //   ERROR el TAG1 ImpIva no existe o viene mal definido...
        //   <ERROR-1094>[Frase:]El "Tipo de DTE" contiene uno de los textos...
        //
        // La tercera aparece cuando el documento está mal formado, y llega con el
        // código *dentro* de la etiqueta. Sin una regla específica, el mensaje no
        // empieza ni por cifra ni por `ERROR` y se clasificaría como emisión
        // correcta.
        if (preg_match('/^<\s*ERROR(?:-\d{1,4})?\b/i', $text) === 1) {
            return self::OUTCOME_REJECTED;
        }

        if (preg_match('/^\d{1,3}\s*-\s*\S/', $text) === 1) {
            // Todo error del proveedor empieza por su código numérico, lo haya
            // o no después la palabra `ERROR`.
            return self::codeOf($text) === self::CODE_GROSS_MISMATCH
                ? self::OUTCOME_RATE_LIMITED
                : self::OUTCOME_REJECTED;
        }

        if (preg_match('/^\s*ERROR(?:ES)?\b/i', $text) === 1) {
            return self::OUTCOME_REJECTED;
        }

        // `ERRORES:ORA-01403: No se ha encontrado ningún dato` es una falla
        // interna del proveedor, no un rechazo del documento, pero tampoco es
        // concluyente: el motor pudo dejar la transacción a medias.
        if (stripos($text, 'ORA-') !== false) {
            return self::OUTCOME_REJECTED;
        }

        return self::OUTCOME_ISSUED;
    }

    /**
     * Extrae el código de error del proveedor.
     *
     * Se guarda en la orden y se devuelve al POS porque permite clasificar el
     * fallo sin depender del texto: el texto puede traducirse o cambiar, el
     * código numérico no.
     *
     * @param string $raw Texto devuelto por el proveedor.
     *
     * @return string|null Código detectado o `null` si el texto no lleva uno.
     */
    public static function errorCode(string $raw): ?string
    {
        $text = trim(self::unwrap($raw));

        if ($text === '') {
            return null;
        }

        // `82-ERROR …`, `56-FECHA …`
        if (preg_match('/^(\d{1,3})\s*-/', $text, $matches) === 1) {
            return $matches[1];
        }

        // `ERRORES:ORA-01403: …`
        if (preg_match('/\b(ORA-\d{5})\b/i', $text, $matches) === 1) {
            return strtoupper($matches[1]);
        }

        if (preg_match('/^\s*ERROR(?:ES)?\b/i', $text) === 1) {
            return 'ERROR';
        }

        // `<ERROR-1094>...`: el código va dentro de la etiqueta.
        if (preg_match('/^<\s*ERROR-(\d{1,4})\b/i', $text, $coincidencia) === 1) {
            return $coincidencia[1];
        }

        // Último recurso: el código puede venir incrustado en un mensaje que lo
        // cita. Los fallos de transporte incluyen el texto del proveedor entre
        // comillas, y un mensaje que dice «respondió 82-ERROR CALCULO…» debe
        // seguir informando del 82. Las reglas anteriores no bastan porque exigen
        // que el código sea lo primero del texto.
        //
        // `(?<!\d)` impide que se capture un fragmento de una fecha como
        // `20261014-ABC`, de la que se quedaría el grupo `014`.
        if (preg_match('/(?<!\d)(\d{1,3})-(?=ERROR|[A-ZÁÉÍÓÚÑ]{4})/i', $text, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Indica si un desenlace deja duda sobre si el documento se emitió.
     *
     * Es la pregunta que decide si el POS puede reenviar la orden o si hay que
     * consultar al proveedor antes de hacer nada.
     *
     * @param string $outcome Resultado de {@see self::classify()}.
     *
     * @return bool `true` si no se puede afirmar que no se emitió.
     */
    public static function isAmbiguous(string $outcome): bool
    {
        return $outcome === self::OUTCOME_RATE_LIMITED
            || $outcome === self::OUTCOME_EMPTY;
    }

    /**
     * Indica si el desenlace admite reenvío inmediato del mismo XML.
     *
     * Todos los desenlaces salvo el éxito lo admiten: el proveedor reconoce la
     * `Referencia` y no duplica el documento, de modo que reenviar un XML
     * idéntico nunca genera una segunda factura.
     *
     * @param string $outcome Resultado de {@see self::classify()}.
     *
     * @return bool `true` si puede reintentarse sin riesgo de duplicar.
     */
    public static function isRetryable(string $outcome): bool
    {
        return $outcome !== self::OUTCOME_ISSUED;
    }

    /**
     * Indica si una respuesta de **anulación** es en realidad un rechazo.
     *
     * ## Por qué hace falta una función aparte
     *
     * Las respuestas de emisión y las de anulación no comparten vocabulario. Al
     * anular un documento inexistente el proveedor responde:
     *
     * ```
     * <RESULTADO>NO EXISTE EL DOCUMENTO QUE SE DESEA ANULAR</RESULTADO>
     * ```
     *
     * El mensaje **no lleva código numérico ni la palabra `ERROR`**, de modo que
     * {@see self::classify()} lo devolvería como `OUTCOME_ISSUED`: la orden
     * quedaría marcada como anulada cuando el proveedor acaba de decir que no
     * existe. Es el peor tipo de fallo posible en este dominio, porque es
     * silencioso y además falsifica un registro fiscal.
     *
     * Los patrones se limitan a formulaciones negativas explícitas observadas en el
     * servicio, para no confundir una respuesta correcta con una que mencione una
     * palabra negativa por casualidad.
     *
     * ## Riesgo residual conocido
     *
     * La respuesta de anulación **correcta** no se pudo observar contra el entorno de
     * pruebas, que agotó su cupo de emisiones durante el desarrollo de HU-05. Esta
     * función decide por descarte: cualquier cosa que no sea un rechazo conocido se
     * toma por éxito. En cuanto se confirme la forma del mensaje de éxito, lo
     * correcto es exigirla de forma positiva y convertir lo desconocido en error.
     *
     * @param string $raw Texto devuelto por el proveedor.
     *
     * @return bool `true` si el texto representa un rechazo de la anulación.
     */
    public static function isAnnulmentRejection(string $raw): bool
    {
        $text = mb_strtoupper(trim(self::unwrap($raw)), 'UTF-8');

        if ($text === '') {
            return true;
        }

        $rechazos = [
            // Forma observada para un documento inexistente.
            '/^NO EXISTE\b/',
            '/\bNO EXISTE EL DOCUMENTO\b/',
            // Formulaciones negativas del proveedor.
            '/\bNO SE (?:ENCONTR\w+|PUEDE|HIZO|REALIZ\w+|PERMIT\w+)\b/',
            '/\bINVALID[OA]\b/',
            '/\bRECHAZAD[OA]\b/',
            '/\bFALLA\b/',
            '/\bANULACION (?:NO |FALLO|ERROR)/',
            '/\bYA (?:FUE |ESTA )?ANULAD[OA]\b/',
        ];

        foreach ($rechazos as $patron) {
            if (preg_match($patron, $text) === 1) {
                return true;
            }
        }

        // Las reglas generales de la emisión también aplican aquí.
        return self::classify($raw) !== self::OUTCOME_ISSUED;
    }

    /**
     * Indica si el texto de un `SoapFault` describe un fallo de transporte.
     * ## Por qué el examen del texto y no del tipo de excepción
     *
     * PHP lanza `SoapFault` tanto por un rechazo del emisor como por un `502` de
     * una pasarela que no existe, y en el segundo caso el único dato disponible
     * es el mensaje. Se prueban los patrones que se observaron contra el
     * servicio real y ante caídas de red, y **todo `SoapFault` no reconocido se
     * sigue tratando como rechazo**, que es el comportamiento prudente: ante la
     * duda se preserva el mensaje del proveedor en lugar de inventar un motivo
     * técnico.
     *
     * @param string $message Mensaje del fallo, ya saneado de credenciales.
     *
     * @return bool `true` si el fallo es de transporte o infraestructura.
     */
    public static function isTransportMessage(string $message): bool
    {
        $patterns = [
            // HTTP distinto de 200 y fallo al leer las cabeceras. Es lo que
            // produce PHP ante un 5xx del proveedor o de su pasarela.
            '/error fetching http headers/i',
            '/\bhttp\s+error\b/i',
            '/\b(?:5\d{2}|4\d{2})\s+[a-z ]*(?:service unavailable|gateway|bad gateway|'
                . 'gateway timeout|internal server error|too many requests)/i',
            // Tiempo de espera agotado, tanto en la conexión como en la lectura.
            '/\btimed?[\s-]?out\b/i',
            '/operation timed out/i',
            '/timeout/i',
            // Red y DNS.
            '/connection refused/i',
            '/could not connect/i',
            '/failed to connect/i',
            '/connection reset/i',
            '/broken pipe/i',
            '/network is unreachable/i',
            '/no route to host/i',
            '/getaddresses|gethostbyname|name or service not known/i',
            // TLS: un certificado caducado es un fallo de red, no del documento.
            '/\bssl\b|certificate|tls handshake/i',
            // Respuesta truncada o vacía en el nivel HTTP.
            '/empty reply|server closed the connection/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extrae el código numérico inicial de un mensaje del proveedor.
     *
     * @param string $text Texto ya normalizado.
     *
     * @return string Código de dos o tres dígitos, o cadena vacía.
     */
    private static function codeOf(string $text): string
    {
        if (preg_match('/^(\d{1,3})\s*-/', trim($text), $matches) !== 1) {
            return '';
        }

        return $matches[1];
    }

    /**
     * Quita el envoltorio `<Resultado>` del texto del proveedor.
     *
     * Un envoltorio que se abre y se cierra sin contenido devuelve una cadena
     * vacía, **no** el texto original: `<Resultado></Resultado>` es lo que
     * responde el proveedor cuando no tiene nada que decir, y devolverlo intacto
     * lo haría pasar por una emisión válida.
     *
     * Se pelan **todas** las capas porque el proveedor envuelve algunas respuestas
     * dos veces, sobre todo los errores. Y se pela el marcado, no el texto: si se
     * extrajera el contenido de texto del elemento XML, un error anidado perdería
     * su etiqueta `<ERROR-1094>` y ninguna regla de detección lo reconocería —
     * acabaría tratándose como emisión correcta y la excepción saltaría después,
     * en `DocumentResult`, convertida en un fallo que ya no es de negocio.
     *
     * @param string $text Texto devuelto por el proveedor.
     *
     * @return string Contenido interno, sin envoltorios `<Resultado>`.
     */
    private static function unwrap(string $text): string
    {
        $texto = trim($text);

        while (preg_match('/^<resultado\b[^>]*>(.*)<\/resultado>$/is', $texto, $coincidencia) === 1) {
            $pelado = trim($coincidencia[1]);

            // Un envoltorio vacío devuelve cadena vacía, no el texto original.
            if ($pelado === '' || $pelado === $texto) {
                return $pelado;
            }

            $texto = $pelado;
        }

        return $texto;
    }
}
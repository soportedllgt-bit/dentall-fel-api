<?php

declare(strict_types=1);

/**
 * Plantilla de credenciales locales. COPIE ESTE ARCHIVO COMO
 * `config/config.local.php` y rellene los valores.
 *
 * `config/config.local.php` está excluido por `.gitignore` y la carpeta
 * `config/` está bloqueada por `.htaccess`. Al ser un archivo PHP que solo
 * devuelve un array, si alguien lo solicita por HTTP el servidor lo ejecuta y
 * devuelve una respuesta vacía: las credenciales no se imprimen nunca.
 *
* PRECEDENCIA: una variable de entorno con el mismo nombre tiene prioridad
 * sobre lo que se escriba aquí. Si el hosting donde se despliegue permite
 * fijarlas, basta con definirlas y este archivo deja de tener efecto.
 *
 * La fusión es por sección y por clave: declare únicamente lo que cambie y el
 * resto de la sección conserva su valor por defecto.
 *
 * IMPORTANTE: este archivo SÍ se versiona. Nunca debe contener valores reales.
 * `tests/guard_confidencialidad.php` lo verifica.
 *
 * @package App\Config
 *
 * @author  fel-dentall
 * @license MIT
 */

return [
    // Entorno de ejecución: `local`, `desarrollo` o `produccion`.
    //
    // En un escritorio no hace falta declararlo: sin esta clave se asume `local`.
    //
    // En un servidor desplegado SÍ hace falta, y esta es la forma recomendada de
    // declararlo cuando el hosting no ofrece forma de fijar variables de
    // entorno (cPanel sin "Environment Variables"), porque no depende de Apache
    // ni se pierde en un redeploy: este archivo está en `.gitignore`, de modo que
    // un `git pull` no lo toca. Un `SetEnv` en `public/.htaccess` sí funciona,
    // pero el archivo es versionado y cada despliegue lo sobrescribe.
    //
    // Valores: `desarrollo` apunta al sandbox de pruebas y no muestra el detalle
    // de los errores; `produccion` apunta al SERVICIO REAL y emite contra la
    // SAT. Un valor desconocido se degrada a `local`, así que una errata aquí no
    // puede provocar una emisión real por accidente.
    //
    // Si el panel sí permite variables de entorno, `APP_ENV` tiene prioridad
    // sobre esta clave: permite cambiar de entorno sin editar ningún archivo.
    'env' => '',

    'db' => [
        // En `local` los valores por defecto son `root` sin contraseña, que solo
        // funcionan en un equipo propio. Un servidor desplegado necesita siempre
        // declarar esta sección.
        'host'     => '',
        'port'     => 3306,
        'database' => '',
        'username' => '',
        'password' => '',
    ],

    'auth' => [
        // Credenciales del HTTP Basic que protege `/api/`. No confundirlas con
        // las del proveedor: estas abren la API, aquellas firman la factura.
        // La comparación es de tiempo constante, así que no es un valor que valga
        // la pena adivinar, pero tampoco un secreto que valga la pena repetir.
        'username' => '',
        'password' => '',
        'realm'    => 'API REST Items',
    ],

    'fel' => [
        // Credenciales de negocio entregadas por el proveedor (pUsuario/pPassword).
        'username' => '',
        'password' => '',

        // Identificación del emisor.
        'nit'           => '',
        'establishment' => '',

        // HTTP Basic del transporte SOAP. Son credenciales distintas de las de
        // negocio: una autentica la conexión, la otra se envía como parámetro.
        'basic_username' => '',
        'basic_password' => '',

        // URL del Web Service. Vacío = la del entorno activo.
        // local / desarrollo → feltestSB (sandbox)
        // produccion        → fel
        //
        // Fijarlas aquí tiene un uso concreto: permite dejar el entorno en
        // `produccion` (que oculta el detalle de los errores) conservando los
        // endpoints de pruebas. Aun así es preferible usar `APP_ENV=desarrollo`,
        // que ya viene con los endpoints de sandbox y no depende de acordarse
        // de sobreescribir estas dos líneas.
        'wsdl_url'     => '',
        'endpoint_url' => '',

        // Tiempos de espera, en segundos.
        'connection_timeout' => 10,
        'response_timeout'   => 60,
    ],

    'billing' => [
        // Recortes útiles en un servidor de pruebas compartido, donde conviene
        // agotar menos emisiones. En producción se pueden dejar vacíos para
        // heredar los valores por defecto de la sección.
        //
        // 'processing_timeout' => 300,  // segundos antes de declarar atascada
        // 'max_attempts'       => 5,     // intentos por orden
    ],
];
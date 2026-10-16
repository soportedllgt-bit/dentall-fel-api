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
 * IMPORTANTE: este archivo SÍ se versiona. Nunca debe contener valores reales.
 * `tests/guard_confidentialidad.php` lo verifica.
 *
 * @package App\Config
 *
 * @author  fel-dentall
 * @license MIT
 */

return [
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
        // local       → feltestSB (sandbox)
        // produccion → fel
        'wsdl_url'     => '',
        'endpoint_url' => '',

        // Tiempos de espera, en segundos.
        'connection_timeout' => 10,
        'response_timeout'   => 60,
    ],
];
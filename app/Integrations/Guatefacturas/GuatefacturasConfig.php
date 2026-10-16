<?php

declare(strict_types=1);

namespace App\Integrations\Guatefacturas;

use App\Core\AppConfig;

/**
 * Configuración validada de la integración con GUATEFACTURAS.
 *
 * Los valores se resuelven con la cadena de precedencia de
 * {@see AppConfig::getGuatefacturasConfig()}:
 * variable de entorno > `config/config.local.php` > `config/config.php`.
 *
 * Una configuración incompleta no produce un error críptico de SOAP, sino una
 * excepción propia que enumera exactamente qué falta.
 *
 * @package App\Integrations\Guatefacturas
 *
 * @author  fel-dentall
 * @license MIT
 */
final class GuatefacturasConfig
{
    /**
     * URL del WSDL del entorno de pruebas.
     *
     * @var string
     */
    public const SANDBOX_WSDL = 'https://pdte.guatefacturas.com/webservices63/feltestSB/Guatefac?wsdl';

    /**
     * URL del WSDL del entorno de producción.
     *
     * @var string
     */
    public const PRODUCTION_WSDL = 'https://pdte.guatefacturas.com/webservices63/fel/Guatefac?wsdl';

    /**
     * Nombre de la aplicación ante el proveedor (`pIdMaquina`).
     *
     * @var string
     */
    public const MACHINE_ID = '1';

    /**
     * Respuesta resumen: solo serie, preimpreso y autorización.
     *
     * @var string
     */
    public const RESPONSE_SUMMARY = 'R';

    /**
     * Respuesta detalle: incluye el XML DTE firmado por SAT.
     *
     * @var string
     */
    public const RESPONSE_DETAIL = 'D';

    /**
     * Datos de configuración ya resueltos.
     *
     * @var array<string, mixed>
     */
    private $values;

    /**
     * @param AppConfig $config Configuración de la aplicación.
     */
    public function __construct(AppConfig $config)
    {
        $this->values = $config->getGuatefacturasConfig();
    }

    /**
     * Usuario de negocio que viaja como parámetro `pUsuario`.
     *
     * @return string Nombre de usuario entregado por el proveedor.
     */
    public function username(): string
    {
        return (string) $this->values['username'];
    }

    /**
     * Contraseña de negocio que viaja como parámetro `pPassword`.
     *
     * @return string Contraseña entregada por el proveedor.
     */
    public function password(): string
    {
        return (string) $this->values['password'];
    }

    /**
     * NIT del emisor.
     *
     * @return string Identificación tributaria del emisor.
     */
    public function nitEmisor(): string
    {
        return (string) $this->values['nit'];
    }

    /**
     * Número de establecimiento del emisor.
     *
     * @return string Código de establecimiento.
     */
    public function establishment(): string
    {
        return (string) $this->values['establishment'];
    }

    /**
     * Usuario de HTTP Basic del transporte SOAP.
     *
     * @return string Nombre de usuario del transporte.
     */
    public function basicUsername(): string
    {
        return (string) $this->values['basic_username'];
    }

    /**
     * Contraseña de HTTP Basic del transporte SOAP.
     *
     * @return string Contraseña del transporte.
     */
    public function basicPassword(): string
    {
        return (string) $this->values['basic_password'];
    }

    /**
     * URL del WSDL.
     *
     * @return string URL del descriptor del servicio.
     */
    public function wsdlUrl(): string
    {
        $url = (string) $this->values['wsdl_url'];

        return $url === '' ? self::SANDBOX_WSDL : $url;
    }

    /**
     * URL del endpoint SOAP.
     *
     * @return string URL del servicio.
     */
    public function endpointUrl(): string
    {
        $url = (string) $this->values['endpoint_url'];

        return $url === '' ? rtrim($this->wsdlUrl(), '?wsdl') : $url;
    }

    /**
     * Tiempo máximo de espera para establecer la conexión.
     *
     * @return int Segundos.
     */
    public function connectionTimeout(): int
    {
        return (int) $this->values['connection_timeout'];
    }

    /**
     * Tiempo máximo de espera para recibir la respuesta.
     *
     * @return int Segundos.
     */
    public function responseTimeout(): int
    {
        return (int) $this->values['response_timeout'];
    }

    /**
     * Indica si la configuración tiene todos los campos obligatorios.
     *
     * @return bool `true` si no falta ninguna credencial.
     */
    public function isComplete(): bool
    {
        return $this->missingFields() === [];
    }

    /**
     * Devuelve los campos obligatorios que faltan por configurar.
     *
     * Se usa para producir un error accionable en lugar de un fallo de SOAP.
     *
     * @return array<int, string> Nombres de variable de entorno ausentes.
     */
    public function missingFields(): array
    {
        $required = [
            'username'      => 'FEL_GUATEFAC_USER',
            'password'      => 'FEL_GUATEFAC_PASSWORD',
            'nit'           => 'FEL_EMISOR_NIT',
            'establishment' => 'FEL_ESTABLECIMIENTO',
            'basic_username' => 'FEL_GUATEFAC_BASIC_USER',
            'basic_password' => 'FEL_GUATEFAC_BASIC_PASS',
        ];

        $missing = [];

        foreach ($required as $field => $variable) {
            if (trim((string) $this->values[$field]) === '') {
                $missing[$field] = $variable;
            }
        }

        return $missing;
    }

    /**
     * Exige una configuración completa y lanza una excepción si falta algo.
     *
     * @return void
     *
     * @throws \App\Integrations\Guatefacturas\ConfigurationException Si falta algún campo.
     */
    public function assertComplete(): void
    {
        $missing = $this->missingFields();

        if ($missing === []) {
            return;
        }

        $names = array_values($missing);

        throw ConfigurationException::missingCredentials($names);
    }

    /**
     * Devuelve los nombres de variable de entorno definidos.
     *
     * Útil para diagnóstico: permite saber de dónde salió cada valor sin
     * exponer su contenido.
     *
     * @return array<int, string> Relación de campo a nombre de variable.
     */
    public function describeSources(): array
    {
        return [
            'pUsuario'             => 'FEL_GUATEFAC_USER',
            'pPassword'            => 'FEL_GUATEFAC_PASSWORD',
            'pNitEmisor'           => 'FEL_EMISOR_NIT',
            'pEstablecimiento'     => 'FEL_ESTABLECIMIENTO',
            'HTTP Basic usuario'   => 'FEL_GUATEFAC_BASIC_USER',
            'HTTP Basic contraseña' => 'FEL_GUATEFAC_BASIC_PASS',
            'WSDL'                 => 'FEL_WSDL_URL',
            'Endpoint'             => 'FEL_ENDPOINT_URL',
        ];
    }
}
<?php

declare(strict_types=1);

namespace App\Integrations\Guatefacturas;

/**
 * La configuración del proveedor está incompleta.
 *
 * Se lanza antes de cualquier llamada de red, para que el fallo sea explicable
 * en lugar de manifestarse como un error de SOAP críptico.
 *
 * @package App\Integrations\Guatefacturas
 *
 * @author  fel-dentall
 * @license MIT
 */
final class ConfigurationException extends GuatefacturasException
{
    /**
     * @param array<int, string> $variables Nombres de las variables ausentes.
     *
     * @return self Excepción preparada.
     */
    public static function missingCredentials(array $variables): self
    {
        $exception = new self(sprintf(
            'Faltan credenciales de GUATEFACTURAS: %s. '
            . 'Defínelas como variables de entorno o en config/config.local.php.',
            implode(', ', $variables)
        ));

        return $exception;
    }

    /**
     * El servicio del proveedor no está disponible o no responde.
     *
     * @param string $detalle Detalle técnico ya saneado.
     *
     * @return self Excepción preparada.
     */
    public static function serviceUnavailable(string $detalle): self
    {
        return new self(sprintf(
            'El Web Service de GUATEFACTURAS no está disponible. Detalle: %s',
            $detalle
        ));
    }
}
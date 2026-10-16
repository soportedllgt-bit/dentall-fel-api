<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Autenticación mediante HTTP Basic Auth.
 *
 * Valida las credenciales gegen la configuración del entorno activo. La
 * comparación se realiza con `hash_equals()` (comparación en tiempo constante)
 * para no filtrar información por temporización, y la credencial no válida
 * nunca se distingue de la credencial inexistente: ambas devuelven `401`.
 *
 * @package App\Core
 *
 * @author  fel-dentall
 * @license MIT
 */
final class Auth
{
    /**
     * Configuración de la aplicación.
     *
     * @var AppConfig
     */
    private $config;

    /**
     * @param AppConfig $config Configuración de la aplicación.
     */
    public function __construct(AppConfig $config)
    {
        $this->config = $config;
    }

    /**
     * Verifica las credenciales de la petición.
     *
     * @param Request $request Petición entrante.
     *
     * @return bool `true` si las credenciales son válidas.
     */
    public function authenticate(Request $request): bool
    {
        $authConfig = $this->config->getAuthConfig();

        $username = $authConfig['username'];
        $password = $authConfig['password'];

        // Sin credenciales configuradas no se permite el acceso: es preferible
        // rechazar por un fallo de configuración que dejarlo abierto.
        if ($username === '' || $password === '') {
            return false;
        }

        $credentials = $this->extractCredentials($request);

        if ($credentials === null) {
            return false;
        }

        [$providedUser, $providedPassword] = $credentials;

        // Ambas comparaciones se ejecutan siempre para no revelar, por la vía del
        // tiempo de respuesta, cuál de las dos credenciales fue la incorrecta.
        $userMatches     = hash_equals($username, $providedUser);
        $passwordMatches = hash_equals($password, $providedPassword);

        return $userMatches && $passwordMatches;
    }

    /**
     * Exige autenticación o emite un `401` y detiene la ejecución.
     *
     * @param Request $request Petición entrante.
     *
     * @return bool `true` si la petición puede continuar.
     */
    public function requireAuth(Request $request): bool
    {
        if ($this->authenticate($request)) {
            return true;
        }

        $this->deny($this->config->getAuthConfig()['realm']);

        return false;
    }

    /**
     * Emite la respuesta `401 Unauthorized` con el desafío de autenticación.
     *
     * @param string $realm Realm publicado en la cabecera `WWW-Authenticate`.
     *
     * @return void
     */
    private function deny(string $realm): void
    {
        if (!headers_sent()) {
            header(sprintf('WWW-Authenticate: Basic realm="%s", charset="UTF-8"', $realm));
        }

        Response::error('Credenciales inválidas o ausentes. Se requiere autenticación HTTP Basic.', 401);
    }

    /**
     * Extrae usuario y contraseña de la cabecera `Authorization`.
     *
     * @param Request $request Petición entrante.
     *
     * @return array{0: string, 1: string}|null Credenciales o `null` si no hay cabecera válida.
     */
    private function extractCredentials(Request $request): ?array
    {
        // PHP ya decodifica la cabecera Basic en PHP_AUTH_USER/PHP_AUTH_PW.
        $serverUser = $_SERVER['PHP_AUTH_USER'] ?? null;
        $serverPass = $_SERVER['PHP_AUTH_PW'] ?? null;

        if (is_string($serverUser) && is_string($serverPass)) {
            return [$serverUser, $serverPass];
        }

        $header = $request->header('Authorization');

        if (!is_string($header) || stripos($header, 'Basic ') !== 0) {
            return null;
        }

        $encoded = trim(substr($header, strlen('Basic ')));
        $decoded = base64_decode($encoded, true);

        if ($decoded === false || strpos($decoded, ':') === false) {
            return null;
        }

        // El nombre de usuario no puede contener dos puntos (RFC 7617).
        [$username, $password] = explode(':', $decoded, 2);

        return [$username, $password];
    }
}
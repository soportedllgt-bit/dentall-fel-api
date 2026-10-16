<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;

/**
 * Fábrica de conexiones PDO con una única instancia por proceso.
 *
 * La conexión se crea de forma perezosa: hasta que no se llama a
 * {@see Database::getConnection()} no se abre ningún socket contra MySQL, de
 * modo que las peticiones que no tocan la base de datos (por ejemplo un
 * `OPTIONS` de preflight o un `401`) no dependan de la disponibilidad de la BD.
 *
 * @package App\Core
 *
 * @author  fel-dentall
 * @license MIT
 */
final class Database
{
    /**
     * Configuración de la aplicación.
     *
     * @var AppConfig
     */
    private $config;

    /**
     * Conexión PDO reutilizada, creada en la primera llamada.
     *
     * @var PDO|null
     */
    private $pdo;

    /**
     * @param AppConfig $config Configuración de la aplicación.
     */
    public function __construct(AppConfig $config)
    {
        $this->config = $config;
    }

    /**
     * Devuelve la conexión PDO, creándola si aún no existe.
     *
     * @return PDO Conexión configurada con prepared statements nativos.
     *
     * @throws \PDOException Si no se puede establecer la conexión.
     */
    public function getConnection(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $db = $this->config->getDbConfig();

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $db['host'],
            $db['port'],
            $db['database'],
            $db['charset']
        );

        $this->pdo = new PDO($dsn, $db['username'], $db['password'], [
            // Todo error de PDO se lanza como excepción en lugar de devolver false.
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            // Las filas se devuelven como arrays asociativos.
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Prepared statements nativos: los valores viajan tipados al servidor,
            // nunca interpolados en el SQL. Es la protección real contra
            // inyección SQL (ATTR_EMULATE_PREPARES = false).
            PDO::ATTR_EMULATE_PREPARES   => false,
            // Evita la serialización automática de tipos al devolver valores.
            PDO::ATTR_STRINGIFY_FETCHES  => false,
            // Límite de espera de la conexión, en segundos.
            PDO::ATTR_TIMEOUT            => 5,
        ]);

        $this->pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");
        $this->pdo->exec("SET SESSION time_zone = '+00:00'");

        return $this->pdo;
    }

    /**
     * Ejecuta una consulta preparada devolviendo el statement vinculado.
     *
     * @param string               $sql    Consulta SQL con marcadores de posición nombrados.
     * @param array<string, mixed> $params Valores a vincular.
     *
     * @return \PDOStatement Statement listo para ejecutar y consumir.
     *
     * @throws \PDOException Si la consulta no puede prepararse o ejecutarse.
     */
    public function run(string $sql, array $params = []): \PDOStatement
    {
        $statement = $this->getConnection()->prepare($sql);
        $statement->execute($params);

        return $statement;
    }

    /**
     * Cierra la conexión de forma explícita.
     *
     * @return void
     */
    public function close(): void
    {
        $this->pdo = null;
    }

    /**
     * Traduce una excepción de PDO a un mensaje apto para el cliente.
     *
     * En producción el detalle técnico (SQLSTATE, SQL, archivo) nunca se
     * devuelve al cliente: solo se clasifica el tipo de fallo.
     *
     * @param PDOException $exception Excepción recibida.
     *
     * @return array{http_code: int, message: string, conflict: bool}
     *         Código HTTP sugerido, mensaje seguro y si es un conflicto de integridad.
     */
    public function describeException(PDOException $exception): array
    {
        $code = (string) $exception->getCode();

        // 23000: violación de restricción de integridad (clave duplicada o FK).
        $isIntegrityViolation = (strpos($code, '23000') === 0) || ($code === '23000');

        if ($isIntegrityViolation) {
            return [
                'http_code' => 409,
                'message'   => 'La operación viola una restricción de integridad de los datos.',
                'conflict'  => true,
            ];
        }

        // 42S02: tabla inexistente. 42000: error de sintaxis o de esquema.
        $isSchemaError = (strpos($code, '42') === 0);

        return [
            'http_code' => 500,
            'message'   => $isSchemaError
                ? 'No se pudo completar la operación por un problema de esquema en la base de datos.'
                : 'No se pudo completar la operación por un error interno.',
            'conflict'  => false,
        ];
    }
}
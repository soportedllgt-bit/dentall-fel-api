<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

/**
 * Repositorio de persistencia para la entidad `Item`.
 *
 * TODAS las consultas usan *prepared statements* con marcadores de posición
 * nombrados y `PDO::ATTR_EMULATE_PREPARES => false`. Ningún valor Proveniente
 * de la petición se concatena nunca en el SQL.
 *
 * La capa de repositorio devuelve siempre filas con las columnas públicas
 * (`uuid`, `title`, `description`, `created_at`). La columna `id` solo se
 * selecciona cuando es imprescindible (por ejemplo para ordenar de forma
 * estable) y nunca forma parte del resultado devuelto al servicio.
 *
 * @package App\Repositories
 *
 * @author  fel-dentall
 * @license MIT
 */
final class ItemRepository
{
    /**
     * Columnas públicas de la tabla `items`.
     *
     * @var string
     */
    private const PUBLIC_COLUMNS = 'uuid, title, description, created_at';

    /**
     * Columnas que un cliente puede actualizar mediante el endpoint `PUT`.
     *
     * Cualquier otro campo recibido en la petición se descarta antes de tocar
     * la base de datos.
     *
     * @var array<int, string>
     */
    private const UPDATABLE_COLUMNS = ['title', 'description'];

    /**
     * Fábrica de conexiones.
     *
     * @var Database
     */
    private $db;

    /**
     * @param Database $db Fábrica de conexiones PDO.
     */
    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Recupera la lista paginada de items.
     *
     * El orden es `created_at DESC, id DESC`: la clave primaria interna actúa
     * como desempate para dos registros creados en el mismo segundo, sin
     * exponerla al cliente.
     *
     * @param int $limit  Número máximo de registros a devolver.
     * @param int $offset Número de registros a omitir.
     *
     * @return array<int, array<string, mixed>> Lista de filas públicas.
     */
    public function all(int $limit = 25, int $offset = 0): array
    {
        // MariaDB no admite marcadores en LIMIT/OFFSET sin tipado explícito.
        $sql = sprintf(
            'SELECT %s FROM items ORDER BY created_at DESC, id DESC LIMIT :limit OFFSET :offset',
            self::PUBLIC_COLUMNS
        );

        $statement = $this->db->getConnection()->prepare($sql);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $statement->fetchAll();

        return $rows;
    }

    /**
     * Cuenta el total de items existentes.
     *
     * @return int Número total de registros.
     */
    public function count(): int
    {
        $statement = $this->db->getConnection()->prepare('SELECT COUNT(*) FROM items');
        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    /**
     * Busca un item por su identificador público.
     *
     * @param string $uuid Identificador público UUID v4.
     *
     * @return array<string, mixed>|null Fila pública o `null` si no existe.
     */
    public function findByUuid(string $uuid): ?array
    {
        $sql = sprintf('SELECT %s FROM items WHERE uuid = :uuid LIMIT 1', self::PUBLIC_COLUMNS);

        $statement = $this->db->getConnection()->prepare($sql);
        $statement->execute([':uuid' => $uuid]);

        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * Busca un item por su identificador interno.
     *
     * Reservado para uso interno (relaciones, tareas de mantenimiento). Nunca
     * debe usarse para atender una petición cuyo dato llegue del cliente.
     *
     * @param int $id Identificador interno.
     *
     * @return array<string, mixed>|null Fila que incluye `id` o `null` si no existe.
     */
    public function findById(int $id): ?array
    {
        $sql = 'SELECT id, uuid, title, description, created_at FROM items WHERE id = :id LIMIT 1';

        $statement = $this->db->getConnection()->prepare($sql);
        $statement->execute([':id' => $id]);

        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * Inserta un nuevo item y devuelve su representación pública.
     *
     * @param array{uuid: string, title: string, description: string|null, created_at: string} $data
     *        Datos ya validados y saneados por la capa de servicio.
     *
     * @return array<string, mixed> Fila pública del registro creado.
     *
     * @throws \PDOException Si la inserción falla.
     */
    public function create(array $data): array
    {
        $sql = 'INSERT INTO items (uuid, title, description, created_at)
                VALUES (:uuid, :title, :description, :created_at)';

        $this->db->getConnection()->prepare($sql)->execute([
            ':uuid'        => $data['uuid'],
            ':title'       => $data['title'],
            ':description' => $data['description'],
            ':created_at'  => $data['created_at'],
        ]);

        $created = $this->findByUuid($data['uuid']);

        if ($created === null) {
            throw new \PDOException('El registro se insertó pero no pudo releerse.');
        }

        return $created;
    }

    /**
     * Actualiza un item identificado por su UUID público.
     *
     * La cláusula `SET` se construye únicamente con las columnas de
     * {@see ItemRepository::UPDATABLE_COLUMNS}; los nombres de columna se
     * filtran contra esa lista blanca y los valores viajan como parámetros.
     *
     * @param string                      $uuid Identificador público UUID v4.
     * @param array<string, string|null>  $data Campos a actualizar.
     *
     * @return array<string, mixed>|null Fila pública actualizada o `null` si no existe.
     *
     * @throws \PDOException Si la actualización falla.
     */
    public function updateByUuid(string $uuid, array $data): ?array
    {
        $fields = array_intersect_key($data, array_flip(self::UPDATABLE_COLUMNS));

        if ($fields === []) {
            return $this->findByUuid($uuid);
        }

        $assignments = [];
        $params      = [':uuid' => $uuid];

        foreach ($fields as $column => $value) {
            $assignments[]          = sprintf('%s = :%s', $column, $column);
            $params[sprintf(':%s', $column)] = $value;
        }

        $sql = sprintf(
            'UPDATE items SET %s WHERE uuid = :uuid',
            implode(', ', $assignments)
        );

        $this->db->getConnection()->prepare($sql)->execute($params);

        return $this->findByUuid($uuid);
    }

    /**
     * Elimina un item identificado por su UUID público.
     *
     * @param string $uuid Identificador público UUID v4.
     *
     * @return bool `true` si se eliminó un registro, `false` si no existía.
     *
     * @throws \PDOException Si el borrado falla.
     */
    public function deleteByUuid(string $uuid): bool
    {
        $statement = $this->db->getConnection()->prepare('DELETE FROM items WHERE uuid = :uuid');
        $statement->execute([':uuid' => $uuid]);

        return $statement->rowCount() > 0;
    }
}
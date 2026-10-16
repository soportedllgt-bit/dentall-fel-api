<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Entidad de dominio `Item`.
 *
 * MODELO DE IDENTIFICACIÓN:
 * - `$id` es el identificador interno (`BIGINT UNSIGNED AUTO_INCREMENT`), usado
 *   únicamente para relaciones y lógica interna. NUNCA se expone al cliente.
 * - `$uuid` es el identificador público (UUID v4) que aparece en las URLs y en
 *   las respuestas JSON.
 *
 * @package App\Models
 *
 * @author  fel-dentall
 * @license MIT
 */
final class Item
{
    /**
     * Identificador interno. Es `null` hasta que el registro se persiste.
     *
     * @var int|null
     */
    private $id;

    /**
     * Identificador público UUID v4.
     *
     * @var string
     */
    private $uuid;

    /**
     * Título del item.
     *
     * @var string
     */
    private $title;

    /**
     * Descripción del item. Puede ser `null` si el registro no la tiene.
     *
     * @var string|null
     */
    private $description;

    /**
     * Fecha y hora de creación en UTC con formato `Y-m-d H:i:s`.
     *
     * @var string
     */
    private $createdAt;

    /**
     * @param string      $uuid        Identificador público UUID v4.
     * @param string      $title       Título del item.
     * @param string|null $description Descripción del item o `null`.
     * @param string      $createdAt   Fecha de creación en UTC.
     */
    public function __construct(string $uuid, string $title, ?string $description, string $createdAt)
    {
        $this->id          = null;
        $this->uuid        = $uuid;
        $this->title       = $title;
        $this->description = $description;
        $this->createdAt   = $createdAt;
    }

    /**
     * Construye una entidad a partir de una fila de la base de datos.
     *
     * @param array<string, mixed> $row Fila devuelta por PDO.
     *
     * @return self Entidad hidratada.
     */
    public static function fromDb(array $row): self
    {
        $item = new self(
            (string) $row['uuid'],
            (string) $row['title'],
            isset($row['description']) ? (string) $row['description'] : null,
            (string) $row['created_at']
        );

        // El id interno se hidrata para uso interno, pero no se publica.
        if (isset($row['id'])) {
            $item->setId((int) $row['id']);
        }

        return $item;
    }

    /**
     * Devuelve la representación pública del item.
     *
     * IMPORTANTE: esta lista es deliberadamente cerrada. El campo `id` queda
     * excluido por diseño, de modo que ninguna respuesta de la API pueda
     * exponer el identificador interno por accidente.
     *
     * @return array{uuid: string, title: string, description: string|null, created_at: string}
     *         Representación pública del item.
     */
    public function toArrayPublic(): array
    {
        return [
            'uuid'        => $this->uuid,
            'title'       => $this->title,
            'description' => $this->description,
            'created_at'  => $this->createdAt,
        ];
    }

    /**
     * Devuelve el identificador interno.
     *
     * @return int|null Identificador interno o `null` si no está persistido.
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * Asigna el identificador interno.
     *
     * @param int|null $id Identificador interno.
     *
     * @return void
     */
    public function setId(?int $id): void
    {
        $this->id = $id;
    }

    /**
     * Devuelve el identificador público.
     *
     * @return string UUID v4.
     */
    public function getUuid(): string
    {
        return $this->uuid;
    }

    /**
     * Devuelve el título.
     *
     * @return string Título del item.
     */
    public function getTitle(): string
    {
        return $this->title;
    }

    /**
     * Asigna el título.
     *
     * @param string $title Título normalizado.
     *
     * @return void
     */
    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    /**
     * Devuelve la descripción.
     *
     * @return string|null Descripción o `null`.
     */
    public function getDescription(): ?string
    {
        return $this->description;
    }

    /**
     * Asigna la descripción.
     *
     * @param string|null $description Descripción normalizada o `null`.
     *
     * @return void
     */
    public function setDescription(?string $description): void
    {
        $this->description = $description;
    }

    /**
     * Devuelve la fecha de creación.
     *
     * @return string Fecha en UTC con formato `Y-m-d H:i:s`.
     */
    public function getCreatedAt(): string
    {
        return $this->createdAt;
    }

    /**
     * Asigna la fecha de creación.
     *
     * @param string $createdAt Fecha en UTC con formato `Y-m-d H:i:s`.
     *
     * @return void
     */
    public function setCreatedAt(string $createdAt): void
    {
        $this->createdAt = $createdAt;
    }
}
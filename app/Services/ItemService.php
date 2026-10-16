<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Sanitizer;
use App\Core\Uuid;
use App\Core\Validator;
use App\Repositories\ItemRepository;

/**
 * Lógica de negocio del recurso `Item`.
 *
 * Es la única capa que conoce las reglas de dominio:
 * - Saneamiento y normalización de la entrada.
 * - Validación estricta con acumulación de errores.
 * - Generación del `uuid` v4 en el servidor.
 * - Asignación de `created_at` en UTC con `gmdate()`.
 * - Validación del `uuid` entrante.
 *
 * No escribe SQL ni construye respuestas HTTP: devuelve un sobre de resultado
 * (`ok`, `item`, `errors`, `message`) y es el controlador quien traduce ese
 * sobre a un código de estado HTTP.
 *
 * @package App\Services
 *
 * @author  fel-dentall
 * @license MIT
 */
final class ItemService
{
    /**
     * Longitud máxima permitida para el título, en caracteres.
     *
     * @var int
     */
    private const TITLE_MAX_LENGTH = 255;

    /**
     * Longitud máxima permitida para la descripción, en caracteres.
     *
     * @var int
     */
    private const DESCRIPTION_MAX_LENGTH = 65535;

    /**
     * Repositorio de persistencia.
     *
     * @var ItemRepository
     */
    private $repository;

    /**
     * @param ItemRepository $repository Repositorio de persistencia.
     */
    public function __construct(ItemRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Recupera la lista de items de forma paginada.
     *
     * @param int $limit  Límite solicitado, acotado al máximo configurado.
     * @param int $offset Desplazamiento solicitado, acotado a un valor no negativo.
     *
     * @return array{items: array<int, array<string, mixed>>, total: int, limit: int, offset: int}
     *         Listado junto con los metadatos de paginación.
     */
    public function listItems(int $limit, int $offset): array
    {
        $items = $this->repository->all($limit, $offset);

        return [
            'items'  => $items,
            'total'  => $this->repository->count(),
            'limit'  => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * Recupera un item por su identificador público.
     *
     * @param string $uuid Identificador público UUID v4.
     *
     * @return array<string, mixed>|null Fila pública o `null` si no existe.
     *
     * @throws \InvalidArgumentException Si el UUID no tiene formato v4 válido.
     */
    public function getItem(string $uuid): ?array
    {
        return $this->repository->findByUuid(Uuid::normalize($uuid));
    }

    /**
     * Crea un item a partir de los datos recibidos.
     *
     * El `uuid` se genera en el servidor: aunque el cliente lo envíe, se ignora,
     * igual que `created_at`.
     *
     * @param array<string, mixed> $input Cuerpo JSON de la petición.
     *
     * @return array{ok: bool, item: array<string, mixed>|null, errors: array<string, array<int, string>>, message: string}
     *         Sobre de resultado con el item creado o el detalle de los errores.
     */
    public function createItem(array $input): array
    {
        $rules = $this->createRules();
        $errors = Validator::rejectUnknownFields($input, $rules);

        if ($errors !== []) {
            return $this->failure($errors, sprintf(
                'Campo(s) no permitido(s) en la petición: %s.',
                implode(', ', $errors)
            ));
        }

        // Se valida la forma antes de sanear para poder informar de tipos erróneos
        // con precisión (por ejemplo, un array donde se esperaba una cadena).
        $errors = Validator::validate($input, $rules);

        if ($errors !== []) {
            return $this->failure($errors);
        }

        $data = [
            'title'       => Sanitizer::title($input['title']),
            'description' => Sanitizer::description($input['description'] ?? null),
        ];

        // El saneamiento puede reducir la longitud, nunca aumentarla, pero se
        // vuelve a validar para mantener una única fuente de verdad.
        $errors = Validator::validate($data, $rules);

        if ($errors !== []) {
            return $this->failure($errors);
        }

        $item = $this->repository->create([
            'uuid'        => Uuid::v4(),
            'title'       => $data['title'],
            'description' => $data['description'],
            'created_at'  => gmdate('Y-m-d H:i:s'),
        ]);

        return $this->success($item);
    }

    /**
     * Actualiza un item existente.
     *
     * Aplica semántica de actualización parcial: basta con enviar `title`,
     * `description` o ambos. `uuid` y `created_at` son inmutables.
     *
     * @param string               $uuid  Identificador público UUID v4.
     * @param array<string, mixed> $input Cuerpo JSON de la petición.
     *
     * @return array{ok: bool, item: array<string, mixed>|null, errors: array<string, array<int, string>>, message: string}
     *         Sobre de resultado con el item actualizado o el detalle de los errores.
     */
    public function updateItem(string $uuid, array $input): array
    {
        $normalizedUuid = Uuid::normalize($uuid);
        $rules          = $this->updateRules();

        $unknownFields = Validator::rejectUnknownFields($input, $rules);

        if ($unknownFields !== []) {
            return $this->failure([], sprintf(
                'Campo(s) no permitido(s) en la petición: %s.',
                implode(', ', $unknownFields)
            ));
        }

        if ($input === []) {
            return $this->failure([], 'Debe enviar al menos un campo modificable: "title" o "description".');
        }

        // Semántica parcial: los campos ausentes no generan error de obligatoriedad.
        $errors = Validator::validate($input, $rules, true);

        if ($errors !== []) {
            return $this->failure($errors);
        }

        $data = [];

        if (array_key_exists('title', $input)) {
            $data['title'] = Sanitizer::title($input['title']);
        }

        if (array_key_exists('description', $input)) {
            $data['description'] = Sanitizer::description($input['description']);
        }

        $errors = Validator::validate($data, $rules, true);

        if ($errors !== []) {
            return $this->failure($errors);
        }

        // Un título presente pero vacío tras el saneamiento es un error explícito
        // y no un campo omitido: diferenciarlo evita borrados accidentales.
        if (array_key_exists('title', $data) && $data['title'] === '') {
            return $this->failure(['title' => ['El campo "title" no puede quedar vacío.']]);
        }

        return $this->success($this->repository->updateByUuid($normalizedUuid, $data));
    }

    /**
     * Elimina un item existente.
     *
     * @param string $uuid Identificador público UUID v4.
     *
     * @return bool `true` si se eliminó, `false` si no existía.
     *
     * @throws \InvalidArgumentException Si el UUID no tiene formato v4 válido.
     */
    public function deleteItem(string $uuid): bool
    {
        return $this->repository->deleteByUuid(Uuid::normalize($uuid));
    }

    /**
     * Reglas de validación aplicables a la creación de un item.
     *
     * @return array<string, array<string, mixed>> Reglas por campo.
     */
    private function createRules(): array
    {
        return [
            'title' => [
                'required' => true,
                'type'     => 'string',
                'max'      => self::TITLE_MAX_LENGTH,
            ],
            'description' => [
                'required' => false,
                'type'     => 'string',
                'max'      => self::DESCRIPTION_MAX_LENGTH,
                'nullable' => true,
            ],
        ];
    }

    /**
     * Reglas de validación aplicables a la actualización de un item.
     *
     * Ningún campo es obligatorio porque la actualización es parcial.
     *
     * @return array<string, array<string, mixed>> Reglas por campo.
     */
    private function updateRules(): array
    {
        return [
            'title' => [
                'required' => false,
                'type'     => 'string',
                'max'      => self::TITLE_MAX_LENGTH,
                'min'      => 1,
            ],
            'description' => [
                'required' => false,
                'type'     => 'string',
                'max'      => self::DESCRIPTION_MAX_LENGTH,
                'nullable' => true,
            ],
        ];
    }

    /**
     * Construye un sobre de resultado exitoso.
     *
     * @param array<string, mixed>|null $item Item resultante o `null` si no existía.
     *
     * @return array{ok: bool, item: array<string, mixed>|null, errors: array<string, array<int, string>>, message: string}
     *         Sobre de resultado.
     */
    private function success(?array $item): array
    {
        return [
            'ok'      => true,
            'item'    => $item,
            'errors'  => [],
            'message' => '',
        ];
    }

    /**
     * Construye un sobre de resultado con errores de validación.
     *
     * @param array<string, array<int, string>> $errors Errores por campo.
     * @param string                             $message Mensaje general opcional.
     *
     * @return array{ok: bool, item: array<string, mixed>|null, errors: array<string, array<int, string>>, message: string}
     *         Sobre de resultado con `ok` a `false`.
     */
    private function failure(array $errors, string $message = ''): array
    {
        return [
            'ok'      => false,
            'item'    => null,
            'errors'  => $errors,
            'message' => $message,
        ];
    }
}
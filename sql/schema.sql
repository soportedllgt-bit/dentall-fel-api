-- =============================================================================
-- Esquema base de la API RESTful (recurso de ejemplo: items)
-- =============================================================================
--
-- Convenciones aplicadas:
--   * Nombres de tablas y columnas SIEMPRE en inglés.
--   * `id`       : BIGINT UNSIGNED AUTO_INCREMENT. Identificador INTERNO, usado
--                  solo para relaciones y lógica interna. No se expone nunca.
--   * `uuid`     : CHAR(36) UNIQUE. Identificador PÚBLICO UUID v4, generado
--                  siempre en el servidor. Es el único identificador visible en
--                  las rutas y en las respuestas JSON.
--   * `created_at`: DATETIME en UTC con formato `Y-m-d H:i:s` (se usa gmdate()).
--
-- Importación en XAMPP / MariaDB:
--   C:\xampp_lite\apps\mysql\bin\mysql.exe -u root < sql\schema.sql
--
-- Importación desde phpMyAdmin:
--   phpMyAdmin > Importar > seleccionar sql/schema.sql > Continuar.
--
-- IMPORTANTE: este archivo describe la instalación LIMPIA. Cualquier cambio
-- posterior sobre una base ya desplegada va en sql/migrations/, nunca aquí.
-- Ver sql/migrations/README.md.
-- =============================================================================

CREATE DATABASE IF NOT EXISTS fel_dentall_api
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE fel_dentall_api;

CREATE TABLE IF NOT EXISTS items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT 'Identificador interno (PK/FK)',
  uuid CHAR(36) NOT NULL COMMENT 'Identificador público UUID v4',
  title VARCHAR(255) NOT NULL COMMENT 'Título del item',
  description TEXT NULL COMMENT 'Descripción del item',
  created_at DATETIME NOT NULL COMMENT 'Fecha y hora de creación (UTC)',
  UNIQUE KEY uk_items_uuid (uuid),
  INDEX idx_items_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
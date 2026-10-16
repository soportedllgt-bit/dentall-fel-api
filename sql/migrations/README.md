# Migraciones de base de datos

`sql/schema.sql` describe la instalación **limpia**. No sirve para evolucionar una
base de datos ya desplegada: editarla in-place produce instalaciones divergentes
y, en el caso de `CREATE TABLE IF NOT EXISTS`, ni siquiera aplica el cambio.

Toda modificación a una base existente va aquí, como un archivo numerado y
aplicable en orden.

## Convención

```text
sql/migrations/
├── 001_descripcion_corta.sql
├── 002_otra_cosa.sql
└── README.md
```

Formato del nombre: `NNN_snake_case_en_ingles.sql`. El número debe ser
correlativo y de tres dígitos, para que el orden lexicográfico coincida con el
orden de aplicación.

## Reglas

1. **Nombres de tablas y columnas en inglés.** Comentarios y descripciones en
   español.
2. **Un archivo por cambio.** Si una HU altera tres tablas, un solo archivo con
   las tres sentencias.
3. **Siempre idempotente.** `CREATE TABLE IF NOT EXISTS`, `ADD COLUMN` protegido
   por una consulta a `information_schema`, o un stored procedure que verifique
   antes de alterar. Reaplicar una migración no debe romper nada.
4. **Nunca borres datos sin una tabla de respaldo.** Si una migración destruye o
   transforma datos, debe incluir el `CREATE TABLE ..._backup` y el `INSERT`
   previos.
5. **Nada de `DROP TABLE` de negocio.** Solo se permite retirar columnas o
   tablas que la propia serie de migraciones introdujo y que ya no aportan nada.
6. **Nada de datos de prueba.** Las migraciones mueven estructura, no filas
   reales.

## Plantilla

```sql
-- =============================================================================
-- Migración 001 — Descripción corta en español
-- Autor: <nombre>
-- Fecha: <AAAA-MM-DD>
-- Historia de usuario: <HU-x>
-- Criterios de aceptación afectados: <lista breve>
-- =============================================================================

USE fel_dentall_api;

-- Verificación previa: la migración es segura de reaplicar.
-- SET @existe = (
--     SELECT COUNT(*) FROM information_schema.COLUMNS
--     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'items'
--       AND COLUMN_NAME = 'updated_at'
-- );
-- SET @sql = IF(@existe = 0,
--     'ALTER TABLE items ADD COLUMN updated_at DATETIME NULL COMMENT ''Fecha de actualización (UTC)''',
--     'DO 0');
-- PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
```

## Aplicación

```bash
# Instalación limpia (solo la primera vez)
C:\xampp_lite\apps\mysql\bin\mysql.exe -u root < sql\schema.sql

# Cada migración, en orden, una vez por entorno existente
C:\xampp_lite\apps\mysql\bin\mysql.exe -u root fel_dentall_api < sql\migrations\001_descripcion_corta.sql
```

## Tabla de control

Para saber qué migraciones se han aplicado, crea una sola vez:

```sql
CREATE TABLE IF NOT EXISTS schema_migrations (
  version     VARCHAR(255) NOT NULL PRIMARY KEY COMMENT 'Nombre del archivo de migración',
  applied_at  DATETIME NOT NULL COMMENT 'Fecha y hora de aplicación (UTC)'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Y registra cada una al aplicarla:

```sql
INSERT INTO schema_migrations (version, applied_at) VALUES ('001', UTC_TIMESTAMP());
```

Esta tabla **no** está en `sql/schema.sql` a propósito: se crea en el primer
despliegue que necesite migraciones, para no ensuciar el esquema de referencia.
-- =============================================================================
-- Migración 003 — Anulación de facturas
-- Autor: fel-dentall
-- Fecha: 2026-10-13
-- Historia de usuario: HU-05 (CA5.7)
-- Criterios de aceptación afectados: CA5.7, CA5.8
-- =============================================================================
--
-- Qué añade
-- ----------
-- La anulación es la única operación del proveedor que no recibe el XML: identifica
-- el documento por serie y preimpreso. Eso obliga a conservar el resultado de la
-- emisión (ya están en `serie` y `preimpreso` desde la migración 001) y a guardar
-- además la constancia de lo que se hizo después.
--
-- `annulment_reason` guarda el motivo tal como se envió al proveedor. Se acota a
-- 500 caracteres porque el propio proveedor revienta con `ORA-06502` a partir de 729,
-- y depender de un límite ajeno que además falla con una excepción de Oracle es
-- frágil.
--
-- `annulled_at` se escribe en UTC, como el resto de marcas de tiempo de la tabla.
-- El valor que viaja al proveedor es la fecha local del emisor en `AAAAMMDD`, que se
-- calcula en el servicio y no se almacena: es derivable de la marca UTC con la zona
-- horaria configurada.
-- =============================================================================

USE fel_dentall_api;

-- `annulment_reason` queda en VARCHAR(500), dentro del máximo propio y por debajo
-- del límite real del proveedor (729 caracteres).
SET @columna := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE gf_orders ADD COLUMN annulment_reason VARCHAR(500) NULL AFTER last_error_code',
    'SELECT 1')
    FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'gf_orders'
     AND COLUMN_NAME  = 'annulment_reason'
);

PREPARE sentencia FROM @columna;
EXECUTE sentencia;
DEALLOCATE PREPARE sentencia;

SET @columna := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE gf_orders ADD COLUMN annulment_response LONGTEXT NULL AFTER annulment_reason',
    'SELECT 1')
    FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'gf_orders'
     AND COLUMN_NAME  = 'annulment_response'
);

PREPARE sentencia FROM @columna;
EXECUTE sentencia;
DEALLOCATE PREPARE sentencia;

SET @columna := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE gf_orders ADD COLUMN annulled_at DATETIME NULL AFTER invoiced_at',
    'SELECT 1')
    FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'gf_orders'
     AND COLUMN_NAME  = 'annulled_at'
);

PREPARE sentencia FROM @columna;
EXECUTE sentencia;
DEALLOCATE PREPARE sentencia;

-- Las órdenes ya anuladas antes de esta migración no conservan el motivo ni la
-- respuesta. Se marca la fecha para que el estado terminal sea consistente, pero el
-- motivo queda nulo porque no se puede reconstruir: inventarlo sería peor que
-- confesar que no se sabe.
UPDATE gf_orders
   SET annulled_at = COALESCE(invoiced_at, updated_at)
 WHERE status = 'ANULADO'
   AND annulled_at IS NULL;

INSERT IGNORE INTO schema_migrations (version, applied_at)
VALUES ('003_gf_orders_anulacion', UTC_TIMESTAMP());
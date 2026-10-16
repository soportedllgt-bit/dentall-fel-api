-- =============================================================================
-- Migración 002 — Contingencia del proveedor: desenlace del último intento
-- Autor: fel-dentall
-- Fecha: 2026-10-13
-- Historia de usuario: HU-03 (CA3.2 y CA3.3)
-- Criterios de aceptación afectados: CA3.2, CA3.3
-- =============================================================================
--
-- Qué añade
-- ----------
-- La migración 001 registra un único `status = ERROR_PROVEEDOR` para dos
-- situaciones que exigen acciones opuestas:
--
--   a) El proveedor respondió y rechazó el documento. Los datos están mal y
--      reenviarlo idéntico volverá a fallar. Hay que corregir la orden.
--
--   b) El proveedor no respondió, devolvió una respuesta vacía, o respondió con
--      el error 82. No se sabe si emitió. Reenviar el mismo XML es seguro y
--      puede ser exactamente lo que hace falta.
--
-- Confundirlas tiene un coste concreto: en el caso (b) la API informaba al POS
-- de que el proveedor «rechazó la factura», con código 422. El vendedor leía
-- que su venta estaba mal y no había nada que reintentar, cuando el documento
-- era correcto y bastaba con reenviarlo.
--
-- Columnas nuevas
-- ---------------
-- `ambiguous`      1 si no se puede afirmar que el documento no se emitió.
--                  Es el dato que decide si tiene sentido consultar al
--                  proveedor antes de reenviar (HU-4).
--
-- `last_error_code` Código numérico del proveedor (`82`, `56`, `ORA-01400`).
--                  Se persiste y se devuelve al POS porque clasifica el fallo
--                  sin depender del texto, que puede traducirse o cambiar.
--
-- `PENDIENTE_REINTENTO` es un valor nuevo de la columna `status`, que es
-- `VARCHAR(32)` y no un `ENUM`: no requiere modificarla, solo documentarla.
-- =============================================================================

USE fel_dentall_api;

-- -----------------------------------------------------------------------------
-- Desenlace del último intento de emisión
--
-- `ambiguous`          1 si no puede afirmarse que el documento no se emitió.
--                      Es el dato que decide si tiene sentido consultar al
--                      proveedor antes de reenviar (HU-4).
-- `last_error_code`    Código numérico del proveedor (`82`, `56`, `ORA-01400`).
--                      Clasifica el fallo sin depender del texto, que puede
--                      traducirse o cambiar entre versiones.
--
-- El `ALTER` se envuelve en una comprobación contra `information_schema` porque
-- `ADD COLUMN IF NOT EXISTS` no existe en MySQL 5.7 (sí en MariaDB), y una
-- migración debe poder aplicarse dos veces seguidas en cualquier motor.
-- Las descripciones van en comentarios de este archivo y no en la definición de
-- las columnas: dentro del literal SQL las comillas del `COMMENT` habría que
-- duplicar, y un `COMMENT 'texto'` equilibra el literal antes de tiempo.
-- -----------------------------------------------------------------------------
SET @columna := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE gf_orders ADD COLUMN ambiguous TINYINT(1) NOT NULL DEFAULT 0 AFTER error_message',
    'SELECT 1')
    FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'gf_orders'
     AND COLUMN_NAME  = 'ambiguous'
);

PREPARE sentencia FROM @columna;
EXECUTE sentencia;
DEALLOCATE PREPARE sentencia;

SET @columna := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE gf_orders ADD COLUMN last_error_code VARCHAR(16) NULL AFTER ambiguous',
    'SELECT 1')
    FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'gf_orders'
     AND COLUMN_NAME  = 'last_error_code'
);

PREPARE sentencia FROM @columna;
EXECUTE sentencia;
DEALLOCATE PREPARE sentencia;

-- Las órdenes que ya estaban en ERROR_PROVEEDOR antes de esta migración no
-- permiten distinguir (a) de (b). Se marcan como ambiguas porque es la
-- hipótesis conservadora: ante la duda se permite reintentar, y el proveedor no
-- duplica el documento porque la Referencia es la misma.
UPDATE gf_orders
   SET ambiguous = 1
 WHERE status = 'ERROR_PROVEEDOR';

INSERT IGNORE INTO schema_migrations (version, applied_at)
VALUES ('002_gf_orders_contingencia', UTC_TIMESTAMP());
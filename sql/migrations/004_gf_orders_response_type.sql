-- =============================================================================
-- Migración 004 — Modo de respuesta configurable por el POS
-- Autor: fel-dentall
-- Fecha: 2026-10-21
-- Historia de usuario: HU-06 (respuesta resumen o detalle)
-- Criterios de aceptación afectados: CA6.1, CA6.2, CA6.3
-- =============================================================================
--
-- Qué añade
-- ----------
-- Hasta ahora el modo de respuesta del proveedor estaba fijado a `R` (resumen)
-- en el código fuente: `FacturacionService` y `ReintentoService` llamaban a
-- `generaDocumento()` con `GuatefacturasConfig::RESPONSE_SUMMARY` sin mirar el
-- pedido del POS. El modo `D` (detalle) incluye el XML DTE firmado por SAT, que
-- es lo que el POS necesita para imprimir o validar la factura, pero pesa
-- decenas de kilobytes y no siempre hace falta.
--
-- Se añade `response_type`, que guarda la elección del POS en el momento de
-- emitir. El reintento reenvía el XML almacenado y debe usar el mismo modo:
-- cambiar de `R` a `D` a mitad de un reintento no tiene sentido, porque el
-- documento ya se generó y lo único que cambia es cuánto eco devuelve el
-- proveedor.
--
-- El valor por defecto es `R`, que es lo que la API hacía antes de esta
-- migración. Las órdenes ya emitidas quedan en `R`, que es coherente con lo
-- que realmente ocurrió (nunca se pidió el detalle). No se intenta reconstruir
-- el modo a partir del `provider_response` guardado: una respuesta en modo `R`
-- no contiene el XML firmado, y adivinar `D` por su ausencia sería inventar
-- información.
-- =============================================================================
--
-- Dónde se coloca
-- ---------------
-- `response_type` va al final, después de `annulled_at`, porque es una columna
-- añadida en esta migración y no forma parte del núcleo de la orden. Mantener
-- el orden de las columnas anteriores evita que una relectura de filas antiguas
-- se rompa si alguien las selecciona por posición.
-- =============================================================================

USE fel_dentall_api;

SET @columna := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE gf_orders ADD COLUMN response_type VARCHAR(1) NOT NULL DEFAULT \'R\' AFTER annulled_at',
    'SELECT 1')
    FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'gf_orders'
     AND COLUMN_NAME  = 'response_type'
);

PREPARE sentencia FROM @columna;
EXECUTE sentencia;
DEALLOCATE PREPARE sentencia;

-- Normaliza cualquier valor que no sea `R` ni `D` (no debería haberlo, pero la
-- columna es nueva y es mejor ser defensivo que confiar en el DEFAULT).
UPDATE gf_orders
   SET response_type = 'R'
 WHERE response_type IS NULL
    OR response_type NOT IN ('R', 'D');

INSERT IGNORE INTO schema_migrations (version, applied_at)
VALUES ('004_gf_orders_response_type', UTC_TIMESTAMP());
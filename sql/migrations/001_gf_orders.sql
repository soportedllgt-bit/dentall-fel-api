-- =============================================================================
-- Migración 001 — Tabla de órdenes de facturación electrónica
-- Autor: fel-dentall
-- Fecha: 2026-10-13
-- Historia de usuario: HU-01 (persistencia inicial) y HU-02 (CA2.3)
-- Criterios de aceptación afectados: CA1.3, CA2.3
-- =============================================================================
--
-- Por qué una tabla y no varias
-- ----------------------------
-- La orden del POS se guarda completa como un solo documento JSON en `payload`.
-- Esa copia es la que permite reconstruir el XML idéntico en un reintento: el
-- proveedor exige reenviar el documento byte a byte, y reconstruirlo desde
-- columnas desnormalizadas cambiaría el redondeo y rompería la idempotencia.
--
-- `sent_xml` guarda el XML exacto que salió hacia el proveedor. Es la columna
-- más importante de la tabla: es lo que permite el reintento seguro y, en caso
-- de anulación, lo que debe reenviarse con `<Reversion>`.
--
-- Las columnas `totals_*` son una copia desnormalizada de los totales ya
-- calculados. Existen para poder listar y conciliar desde SQL sin parsear JSON.
-- =============================================================================

USE fel_dentall_api;

-- -----------------------------------------------------------------------------
-- Tabla de control de migraciones (solo esta vez, según sql/migrations/README.md)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS schema_migrations (
  version     VARCHAR(255) NOT NULL PRIMARY KEY COMMENT 'Nombre del archivo de migración',
  applied_at  DATETIME NOT NULL COMMENT 'Fecha y hora de aplicación (UTC)'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- Órdenes de facturación electrónica
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS gf_orders (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
                    COMMENT 'Identificador interno (PK/FK). No se expone nunca.',

  uuid              CHAR(36) NOT NULL
                    COMMENT 'Identificador público UUID v4 de la operación',

  -- Código único que envía el POS. Viaja al proveedor como <Referencia> y es
  -- la clave real de idempotencia: dos peticiones con el mismo código no pueden
  -- producir dos facturas.
  order_code        VARCHAR(64) NOT NULL
                    COMMENT 'Código único de la orden del POS (Referencia del proveedor)',

  dte_type          TINYINT UNSIGNED NOT NULL
                    COMMENT 'Código numérico del tipo de DTE (1..10, ver DteType)',

  status            VARCHAR(32) NOT NULL
                    COMMENT 'PENDIENTE | EN_PROCESO | FACTURADO | ERROR_PROVEEDOR | ANULADO',

  -- Copia normalizada y validada del JSON que envió el POS. Se conserva para
  -- poder reconstruir el documento y auditar qué se intentó facturar.
  payload           LONGTEXT NOT NULL
                    COMMENT 'Orden normalizada en JSON (cliente, carrito, facturación, entrega)',

  -- Totales calculados por la API, enquetados como enteros para no arrastrar
  -- errores de coma flotante. Los importes van en centavos.
  totals_gross      BIGINT NOT NULL DEFAULT 0 COMMENT 'Bruto, en centavos',
  totals_discount   BIGINT NOT NULL DEFAULT 0 COMMENT 'Descuento, en centavos',
  totals_exempt     BIGINT NOT NULL DEFAULT 0 COMMENT 'Exento, en centavos',
  totals_others     BIGINT NOT NULL DEFAULT 0 COMMENT 'Otros (ISR), en centavos',
  totals_net        BIGINT NOT NULL DEFAULT 0 COMMENT 'Neto, en centavos',
  totals_tax        BIGINT NOT NULL DEFAULT 0 COMMENT 'IVA, en centavos',
  totals_total      BIGINT NOT NULL DEFAULT 0 COMMENT 'Total, en centavos',
  item_count        SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Número de líneas del carrito',

  -- Digest devuelto por el proveedor tras una emisión correcta.
  serie             VARCHAR(16) NULL COMMENT 'Serie autorizada',
  preimpreso        VARCHAR(32) NULL COMMENT 'Número correlativo autorizado',
  autorizacion      VARCHAR(64) NULL COMMENT 'Número de autorización entregado por SAT',

  -- Copia íntegra e inalterada de lo que devolvió el proveedor. Puede ocupar
  -- decenas de kilobytes en modo detalle porque incluye el XML DTE firmado.
  provider_response LONGTEXT NULL
                    COMMENT 'Texto íntegro de <Resultado> devuelto por el proveedor',

  -- XML exacto enviado. Es el insumo del reintento con <Reversion> y de la
  -- anulación; nunca se regenera a partir de `payload`.
  sent_xml          LONGTEXT NULL
                    COMMENT 'XML exacto enviado al proveedor',

  error_message     VARCHAR(500) NULL
                    COMMENT 'Último error, acotado a 500 caracteres',

  attempts          TINYINT UNSIGNED NOT NULL DEFAULT 0
                    COMMENT 'Número de intentos de emisión realizados',

  created_at        DATETIME NOT NULL COMMENT 'Fecha y hora de recepción (UTC)',
  updated_at        DATETIME NOT NULL COMMENT 'Fecha y hora de la última actualización (UTC)',
  invoiced_at       DATETIME NULL COMMENT 'Momento de la autorización de SAT (UTC)',

  UNIQUE KEY uk_gf_orders_uuid (uuid),
  UNIQUE KEY uk_gf_orders_order_code (order_code),
  INDEX idx_gf_orders_status (status),
  INDEX idx_gf_orders_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO schema_migrations (version, applied_at)
VALUES ('001_gf_orders', UTC_TIMESTAMP());
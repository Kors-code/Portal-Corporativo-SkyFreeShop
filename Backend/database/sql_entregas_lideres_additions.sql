-- Ajustes faltantes para el modulo de entrega de lideres.
-- Tu dump ya trae entregas, novedades, entrega_log y firmas_digitales.
-- Este campo guarda la firma personal reutilizable del lider.

USE `u527431831_personal`;

SET @firma_col_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'empleados'
    AND COLUMN_NAME = 'firma_personal'
);

SET @firma_sql := IF(
  @firma_col_exists = 0,
  'ALTER TABLE `empleados` ADD COLUMN `firma_personal` LONGTEXT NULL COMMENT ''Firma personal reutilizable en base64 o SVG'' AFTER `deleted_at`',
  'SELECT ''firma_personal ya existe'' AS info'
);
PREPARE firma_stmt FROM @firma_sql;
EXECUTE firma_stmt;
DEALLOCATE PREPARE firma_stmt;

-- Opcional pero recomendado para filtrar pendientes/completadas rapido.
SET @resuelto_idx_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'novedades'
    AND INDEX_NAME = 'novedades_resuelto_index'
);

SET @resuelto_sql := IF(
  @resuelto_idx_exists = 0,
  'ALTER TABLE `novedades` ADD INDEX `novedades_resuelto_index` (`resuelto`)',
  'SELECT ''novedades_resuelto_index ya existe'' AS info'
);
PREPARE resuelto_stmt FROM @resuelto_sql;
EXECUTE resuelto_stmt;
DEALLOCATE PREPARE resuelto_stmt;

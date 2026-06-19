-- Corrige roles cajero abiertos que dejan por fuera a Diego Osorio
-- del reporte de comisiones de vendedores.
--
-- Problema:
--   DIEGO ALEJANDRO OSORIO LOAIZA (codigo_vendedor 1536) tiene varios
--   registros de rol "cajero" con end_date = NULL. Eso hace que el reporte
--   lo considere cajero activo en meses posteriores y lo excluya aunque tenga
--   rol Vendedor.
--
-- Criterio de correccion:
--   Para este usuario, cerrar solo los roles "cajero" abiertos dejando
--   end_date = start_date. Asi esos registros siguen existiendo para el dia
--   correspondiente, pero no bloquean ventas de fechas posteriores.

START TRANSACTION;

-- 1) Verificar usuario objetivo.
SELECT id, name, codigo_vendedor
FROM users
WHERE codigo_vendedor = 1536
   OR UPPER(name) = 'DIEGO ALEJANDRO OSORIO LOAIZA';

-- 2) Verificar roles cajero abiertos antes del cambio.
SELECT ur.id, ur.user_id, u.name, r.name AS role_name, ur.start_date, ur.end_date
FROM user_roles ur
JOIN users u ON u.id = ur.user_id
JOIN roles r ON r.id = ur.role_id
WHERE u.codigo_vendedor = 1536
  AND LOWER(r.name) = 'cajero'
  AND ur.end_date IS NULL
ORDER BY ur.start_date, ur.id;

-- 3) Cerrar roles cajero abiertos solo para Diego.
UPDATE user_roles ur
JOIN users u ON u.id = ur.user_id
JOIN roles r ON r.id = ur.role_id
SET ur.end_date = ur.start_date
WHERE u.codigo_vendedor = 1536
  AND LOWER(r.name) = 'cajero'
  AND ur.end_date IS NULL;

-- 4) Verificar que no queden roles cajero abiertos para Diego.
SELECT ur.id, ur.user_id, u.name, r.name AS role_name, ur.start_date, ur.end_date
FROM user_roles ur
JOIN users u ON u.id = ur.user_id
JOIN roles r ON r.id = ur.role_id
WHERE u.codigo_vendedor = 1536
  AND LOWER(r.name) = 'cajero'
  AND ur.end_date IS NULL
ORDER BY ur.start_date, ur.id;

-- 5) Verificar roles vendedor abiertos de Diego.
SELECT ur.id, ur.user_id, u.name, r.name AS role_name, ur.start_date, ur.end_date
FROM user_roles ur
JOIN users u ON u.id = ur.user_id
JOIN roles r ON r.id = ur.role_id
WHERE u.codigo_vendedor = 1536
  AND LOWER(r.name) = 'vendedor'
ORDER BY ur.start_date, ur.id;

COMMIT;


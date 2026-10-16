# Pruebas de API Macronet — 15 de octubre de 2026

Base: http://74.208.89.166:8080

Login probado con éxito usando el usuario de la captura. Las credenciales de la colección son distintas; se usaron las indicadas en la captura. No se guardaron contraseñas ni cookies en estos archivos.

## Cómo consultar

1. Enviar POST /login con Content-Type: application/json, tenantId: skyfree y el cuerpo de login de la captura.
2. Conservar la cookie SESSION que entrega el servidor. Postman puede gestionarla automáticamente.
3. Enviar las consultas GET con tenantId: skyfree y la cookie de esa sesión.

La colección adjunta usa variables usuario y contrasena; completar la contraseña localmente antes de ejecutar Login. No ejecutar toda la colección original: contiene una solicitud para crear una factura.

## Rutas verificadas en vivo

| Método | Ruta | Resultado |
|---|---|---|
| POST | /login | 200, Autenticado con éxito |
| GET | /api/v1/ventas/documentos?limit=100&offset=0 | 200, 100 documentos |
| GET | /api/v1/ventas/documentos?limit=5&offset=100 | 200, 5 documentos adicionales sin IDs repetidos |
| GET | /api/v1/ventas/documentos/3 | 200, documento S1MEX0200000001 |
| GET | /api/v1/ventas/tipo-operaciones | 200, 15 tipos |
| GET | /api/v1/ventas/tipo-documentos | 200, 4 tipos |
| GET | /api/v1/generales/sucursales | 200, 3 sucursales |

GET /api/v1/ventas/documentos/1 devolvió 400 indicando que no se encontró el ID. Esto no implica que la ruta sea inválida; el ID 3 sí respondió.

Las rutas /v3/api-docs, /v2/api-docs, /swagger-resources y /swagger-ui/index.html devolvieron 500. No se pudo obtener documentación por esas rutas.

## Ventas obtenidas

ventas_muestra_105.json contiene 105 documentos únicos, todos con tipo_operacion_id=4 (NOTA DE VENTA según el catálogo). Fechas entre 2023-01-01 y 2023-01-06. Son una muestra del inicio de la paginación, no todas las ventas ni las más recientes.

Ejemplos: S1MEX0200000001, total 1059; S1MEX0200000002, total 373; S1MEX0200000003, total 774. Los valores son los reportados por la API; no se verificó el nombre de la moneda de ID 1.

Los documentos incluyen fecha, folio, total, importe, impuestos, sucursal_id, cliente_id, vendedor_id, moneda_id y estatus. La consulta individual probada no incluye partidas de productos. No se validaron filtros por fechas, ordenamiento, conteo total ni una ruta de partidas.

El catálogo identifica también FACTURA=5, DEVOLUCIÓN DE CLIENTE=6 y FACTURA GLOBAL=9. La ruta documentos agrupa documentos de ventas; no debe asumirse que todas sus filas son ventas netas ni sumar facturas globales y notas de venta sin revisar duplicaciones.

Solo se ejecutó POST para login y GET para consultas. No se crearon ni modificaron ventas.

resultados.json registra los códigos y rutas probadas sin credenciales ni cookies. probar_api.py permite repetir la muestra con las variables de entorno MACRO_USER y MACRO_PASSWORD; usa el mismo UUID y reemplazar=true de la captura.

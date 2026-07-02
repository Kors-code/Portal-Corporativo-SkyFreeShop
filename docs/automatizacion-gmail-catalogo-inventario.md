# Automatizacion Gmail para catalogo e inventario

## Que queda armado

- Catalogo: `POST /api/automation/import-catalog`
- Inventario: `POST /api/v1/inventory/import-automation`
- Ambos usan el header `X-Automation-Token` con `IMPORT_AUTOMATION_TOKEN`.
- El servicio Node vive en `gmail-import-service`.

## Configuracion Laravel

En `Backend/.env`:

```env
IMPORT_AUTOMATION_TOKEN=pon-un-token-largo
```

## Configuracion Node

```powershell
cd gmail-import-service
npm install
Copy-Item .env.example .env
```

En `gmail-import-service/.env`:

```env
BACKEND_URL=https://tu-backend
IMPORT_AUTOMATION_TOKEN=el-mismo-token-de-laravel
CATALOG_QUERY=from:proveedor@example.com subject:(catalogo) has:attachment newer_than:2d
INVENTORY_RULES_JSON=[{"name":"COLS1","storeId":1,"query":"from:proveedor@example.com subject:(inventario COLS1) has:attachment newer_than:2d"}]
```

## Gmail

1. Crear credenciales OAuth tipo `Desktop app` en Google Cloud.
2. Guardar el archivo como `gmail-import-service/credentials.json`.
3. Generar URL:

```powershell
npm run auth-url
```

4. Autorizar Gmail, copiar el codigo y guardarlo:

```powershell
$env:AUTH_CODE="codigo-de-google"
npm run auth-code
```

## Ejecucion

Probar una vez:

```powershell
npm run once
```

Dejar corriendo diario:

```powershell
npm start
```

El horario se ajusta con:

```env
CRON_SCHEDULE=0 7 * * *
TIMEZONE=America/Bogota
```

## Catalogo

El catalogo reconoce el campo `F/C` y lo guarda como `product_inventory_configs.factor_caja`.
El reporte de inventario usa primero ese factor configurado y luego cae al factor guardado en inventario si no existe.

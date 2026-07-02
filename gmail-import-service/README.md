# Gmail Import Service

Servicio Node.js para descargar adjuntos de Gmail e importarlos en Laravel.

Flujo:

1. Gmail busca correos por query.
2. Descarga adjuntos `.xlsx`, `.xls`, `.xlsm` o `.csv`.
3. Envia el archivo al endpoint Laravel con `X-Automation-Token`.
4. Guarda `state.json` para no repetir el mismo adjunto.

## Endpoints Laravel

Catalogo:

```text
POST /api/automation/import-catalog
Header: X-Automation-Token: IMPORT_AUTOMATION_TOKEN
Body multipart: file
```

Inventario:

```text
POST /api/v1/inventory/import-automation
Header: X-Automation-Token: IMPORT_AUTOMATION_TOKEN
Body multipart: file, store_id
```

## Instalacion

```powershell
cd gmail-import-service
npm install
Copy-Item .env.example .env
```

En `Backend/.env` debe existir el mismo token:

```env
IMPORT_AUTOMATION_TOKEN=pon-un-token-largo
```

Y en `gmail-import-service/.env`:

```env
BACKEND_URL=https://tu-dominio-o-localhost
IMPORT_AUTOMATION_TOKEN=pon-un-token-largo
```

## Gmail OAuth

1. En Google Cloud crea credenciales OAuth tipo `Desktop app`.
2. Descarga el JSON como `gmail-import-service/credentials.json`.
3. Genera URL:

```powershell
npm run auth-url
```

4. Abre la URL, autoriza Gmail y copia el codigo.
5. Guarda token:

```powershell
$env:AUTH_CODE="codigo-de-google"
npm run auth-code
```

Eso crea `token.json`.

## Configuracion de correos

Catalogo:

```env
CATALOG_ENABLED=true
CATALOG_QUERY=from:proveedor@example.com subject:(catalogo) has:attachment newer_than:2d
```

Inventario por tienda:

```env
INVENTORY_RULES_JSON=[{"name":"COLS1","storeId":1,"query":"from:proveedor@example.com subject:(inventario COLS1) has:attachment newer_than:2d"}]
```

`storeId` debe coincidir con `budget.stores.id`.

## Ejecutar

Una sola vez:

```powershell
npm run once
```

Diario por cron interno:

```powershell
npm start
```

El horario se controla con:

```env
CRON_SCHEDULE=0 7 * * *
TIMEZONE=America/Bogota
```

## Columnas del catalogo

El importador Laravel ya reconoce el archivo con encabezados como:

```text
SKU CODE, SKU MIA, UPC1, PRODUCT DESCRIPTION, CATEGORY CODE, CATEGORY DESCRIPTION, COST UNIT USD, RETAIL PRICE, BRAND DESCRIPTION, SUPPLIER CODE, SUPPLIER DESCRIPTION, TYPE, ORIGEN, LINE, F/C
```

`F/C` se guarda en `product_inventory_configs.factor_caja` y el reporte de inventario lo usa como factor principal.

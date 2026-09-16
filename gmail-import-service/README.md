# Gmail Import Service

Servicio Node.js para descargar adjuntos de Gmail e importarlos en Laravel.

Flujo:

1. Gmail busca correos por query.
2. Descarga adjuntos de hojas de calculo o CV segun la regla configurada.
3. Envia el archivo al endpoint Laravel con `X-Automation-Token`.
4. Guarda `state.json` para no repetir el mismo adjunto.

El servicio queda pensado para correr como una app Node independiente. En produccion se recomienda levantarlo con Docker y dejar que su cron interno revise Gmail cada 30 minutos.

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

Hojas de vida:

```text
POST /api/automation/import-cvs
Header: X-Automation-Token: IMPORT_AUTOMATION_TOKEN
Body multipart: cv, vacante_slug, sender_email, sender_name
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

Si vas a correrlo con Docker, copia estos archivos al volumen persistente:

```powershell
New-Item -ItemType Directory -Force gmail-import-service/data
Copy-Item gmail-import-service/credentials.json gmail-import-service/data/credentials.json
Copy-Item gmail-import-service/token.json gmail-import-service/data/token.json
```

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

Hojas de vida por vacante:

```env
RESUME_RULES_JSON=[{"name":"ventas-cartagena","vacanteSlug":"asesora-cartagena","query":"from:rrhh@example.com subject:(cartagena) subject:(hoja de vida OR cv OR hv) has:attachment newer_than:7d"}]
RESUMES_ENDPOINT=/api/automation/import-cvs
```

`vacanteSlug` debe coincidir con `vacantes.slug`. El servicio acepta adjuntos `.pdf`, `.doc` y `.docx`; Gmail se revisa con la frecuencia definida por `CRON_SCHEDULE`.
Por seguridad, las hojas de vida importadas desde Gmail quedan pendientes de evaluacion automatica por IA.

Fallback con IA:

```env
RESUME_AUTO_ENABLED=true
RESUME_AUTO_QUERY=from:rrhh@example.com subject:(hoja de vida OR cv OR hv) has:attachment newer_than:7d
RESUMES_AUTO_ENDPOINT=/api/automation/import-cvs/auto
```

Usa reglas especificas en `RESUME_RULES_JSON` para asuntos claros y deja `RESUME_AUTO_QUERY` para correos validos que no indican vacante en el asunto. En ese fallback, Laravel lee el CV y escoge la vacante con IA entre las vacantes existentes.

## Ejecutar

Una sola vez:

```powershell
npm run once
```

Cada 30 minutos por cron interno:

```powershell
npm start
```

El horario se controla con:

```env
CRON_SCHEDULE=*/30 * * * *
TIMEZONE=America/Bogota
```

Para revisar Gmail con otra frecuencia, ajusta `CRON_SCHEDULE`. Por ejemplo cada 5 minutos:

```env
CRON_SCHEDULE=*/5 * * * *
```

Los adjuntos ya importados quedan registrados en `state.json`, por eso no se vuelven a subir aunque la consulta encuentre el mismo correo.

## Docker

1. Crea el archivo de entorno para Docker:

```powershell
Copy-Item gmail-import-service/.env.docker.example gmail-import-service/.env.docker
```

2. Ajusta `gmail-import-service/.env.docker`:

```env
BACKEND_URL=https://tu-backend.com
IMPORT_AUTOMATION_TOKEN=el-mismo-token-de-Backend
CRON_SCHEDULE=*/30 * * * *
RESUME_AUTO_ENABLED=true
RESUME_AUTO_QUERY=subject:(hoja de vida OR cv OR hv) has:attachment after:2026/09/16
```

3. Deja credenciales y token en el volumen persistente:

```powershell
New-Item -ItemType Directory -Force gmail-import-service/data
Copy-Item gmail-import-service/credentials.json gmail-import-service/data/credentials.json
Copy-Item gmail-import-service/token.json gmail-import-service/data/token.json
```

4. Construye y levanta la app:

```powershell
docker compose -f docker-compose.gmail-import.yml up -d --build
```

5. Ver logs:

```powershell
docker compose -f docker-compose.gmail-import.yml logs -f gmail-import-service
```

El contenedor monta `gmail-import-service/data` en `/app/data`, donde viven `credentials.json`, `token.json`, `state.json` y `downloads`. Esa carpeta no se sube a git.

## Columnas del catalogo

El importador Laravel ya reconoce el archivo con encabezados como:

```text
SKU CODE, SKU MIA, UPC1, PRODUCT DESCRIPTION, CATEGORY CODE, CATEGORY DESCRIPTION, COST UNIT USD, RETAIL PRICE, BRAND DESCRIPTION, SUPPLIER CODE, SUPPLIER DESCRIPTION, TYPE, ORIGEN, LINE, F/C
```

`F/C` se guarda en `product_inventory_configs.factor_caja` y el reporte de inventario lo usa como factor principal.

# Desarrollo local con base de datos en VPS

Este proyecto puede ejecutarse completo en la maquina local mientras MySQL sigue viviendo en el VPS por tunel SSH. La idea es:

- Laravel corre local en `http://127.0.0.1:8000`.
- React/Vite corre local en `http://127.0.0.1:5173/panel/`.
- Vite redirige `/api/*` al Laravel local.
- Laravel se conecta a `127.0.0.1:3307`; ese puerto local se tunela al MySQL del VPS.
- Sesiones, cache y colas de desarrollo quedan locales para no ensuciar tablas operativas del VPS.

## 1. Preparar variables locales

Desde la raiz del proyecto:

```powershell
Copy-Item Backend\.env.local.example Backend\.env
Copy-Item Front-React\.env.local.example Front-React\.env.local
```

Puedes editar `Backend\.env` manualmente o usar el configurador.

Configurador recomendado:

```powershell
powershell.exe -ExecutionPolicy Bypass -File tools\configure-vps-tunnel-env.ps1 `
  -VpsSshHost "IP_O_HOST_DEL_VPS" `
  -VpsSshUser "USUARIO_SSH" `
  -MainDbName "DB_PRINCIPAL" `
  -MainDbUser "USUARIO_DB_PRINCIPAL" `
  -MainDbPassword "PASSWORD_DB_PRINCIPAL" `
  -BudgetDbName "DB_BUDGET" `
  -BudgetDbUser "USUARIO_DB_BUDGET" `
  -BudgetDbPassword "PASSWORD_DB_BUDGET" `
  -PersonalDbName "DB_PERSONAL" `
  -PersonalDbUser "USUARIO_DB_PERSONAL" `
  -PersonalDbPassword "PASSWORD_DB_PERSONAL"
```

Si lo haces manualmente, edita `Backend\.env` y llena:

```dotenv
DB_HOST=127.0.0.1
DB_PORT=3307
DB_DATABASE=
DB_USERNAME=
DB_PASSWORD=

DB_SECOND_HOST=127.0.0.1
DB_SECOND_PORT=3307
DB_SECOND_DATABASE=
DB_SECOND_USERNAME=
DB_SECOND_PASSWORD=

DB_BUDGET_HOST=127.0.0.1
DB_BUDGET_PORT=3307
DB_BUDGET_DATABASE=
DB_BUDGET_USERNAME=
DB_BUDGET_PASSWORD=

VPS_SSH_HOST=IP_O_HOST_DEL_VPS
VPS_SSH_USER=usuario_ssh
VPS_SSH_PORT=22
VPS_DB_HOST=127.0.0.1
VPS_DB_PORT=3306
LOCAL_DB_PORT=3307
```

## 2. Instalar dependencias

```powershell
cd Backend
php composer.phar install
php artisan key:generate

cd ..\Front-React
npm install
```

## 3. Abrir tunel y probar conexion a la base del VPS

Abre el tunel:

```powershell
powershell.exe -ExecutionPolicy Bypass -File tools\dev-local.ps1 -WithTunnel -NoBackend -NoFrontend
```

Ese comando deja una ventana abierta con:

```text
ssh -N -L 3307:127.0.0.1:3306 usuario@vps
```

Con el tunel abierto, prueba la base:

```powershell
.\tools\test-vps-db.ps1
```

Si Windows bloquea scripts por politica de ejecucion:

```powershell
powershell.exe -ExecutionPolicy Bypass -File tools\test-vps-db.ps1
```

Para ver el detalle del error:

```powershell
.\tools\test-vps-db.ps1 -VerboseErrors
```

Debe mostrar `OK` para `mysql`, `budget` y `mysql_personal`.

## 4. Levantar desarrollo local

```powershell
powershell.exe -ExecutionPolicy Bypass -File tools\dev-local.ps1 -WithTunnel
```

Esto abre ventanas para:

- Tunel SSH a MySQL del VPS.
- Laravel local en `127.0.0.1:8000`.
- Vite local en `127.0.0.1:5173`.

Con worker de cola local:

```powershell
.\tools\dev-local.ps1 -Queue
```

Abre:

```text
http://127.0.0.1:5173/panel/
```

## 5. Reglas importantes

- No ejecutes migraciones contra el VPS sin revisar antes el impacto.
- No uses `php artisan migrate:fresh`, `db:wipe`, `db:seed` ni comandos destructivos apuntando al VPS.
- Mantén el tunel abierto mientras desarrollas; si se cierra, Laravel perdera conexion a MySQL.
- Para desarrollo normal, `SESSION_DRIVER=file`, `CACHE_STORE=file` y `QUEUE_CONNECTION=sync` evitan escribir datos temporales en la base remota.
- Si cambias valores de `Backend\.env`, corre `php artisan config:clear`.

## 6. Como queda el frontend

`Front-React\.env.local` usa:

```dotenv
VITE_API_URL=/api/v1
VITE_BACKEND_URL=http://127.0.0.1:8000
```

En local, las llamadas a `/api/v1` pasan por el proxy de Vite hacia Laravel. En produccion, si no existe `VITE_API_URL`, el frontend usa automaticamente `${window.location.origin}/api/v1`.

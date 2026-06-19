# WhatsApp Service

Microservicio local para enviar reportes al grupo interno de WhatsApp usando Baileys.

## Instalar

```bash
npm install
```

## Ejecutar

```bash
$env:SERVICE_TOKEN="pon-un-token-largo"
npm start
```

Escanea el QR desde WhatsApp > Dispositivos vinculados.

## Ver grupos

```bash
curl -H "x-api-token: pon-un-token-largo" http://127.0.0.1:3001/groups
```

Copia el `id` del grupo en `Backend/.env` como `WHATSAPP_GROUP_ID`.

## Variables Laravel

```env
WHATSAPP_SERVICE_URL=http://127.0.0.1:3001
WHATSAPP_SERVICE_TOKEN=pon-un-token-largo
WHATSAPP_GROUP_ID=123456789-123456789@g.us
```

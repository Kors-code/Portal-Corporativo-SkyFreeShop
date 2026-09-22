---
description: Revisa TODO lo pendiente de comitear (diff + archivos nuevos) con checklist de Sky Free Shop y da veredicto de si está listo para subir
---

Revisa TODOS los cambios pendientes de comitear como si fueras un revisor senior de un proyecto Laravel/PHP en producción para Sky Free Shop. Esto es solo revisión y recomendación: **no hagas commit ni push, no modifiques archivos** — eso lo decide y ejecuta el usuario.

Cubre absolutamente todo lo que `git status` muestre como pendiente, no solo el diff de archivos ya rastreados:
1. `git status --porcelain` para ver el panorama completo (modificados, borrados, sin rastrear).
2. `git diff` y `git diff --staged` para los archivos ya rastreados.
3. Para cada archivo **sin rastrear (`??`)** que sea código fuente (no builds/artefactos, no `node_modules`, no `vendor`, no assets compilados de Vite), léelo completo con Read para entender qué se está agregando — no lo ignores solo porque no tiene diff.
4. Si algo del cambio no se explica por sí solo, revisa el contexto alrededor (archivos relacionados, rutas, modelos, migraciones) antes de opinar.

Verifica:
1. **Seguridad**: credenciales/tokens expuestos, validación de inputs, mass assignment en Eloquent, inyección SQL, secretos en archivos nuevos (`.env`, `credentials.json`, tokens) que no deberían subirse al repo
2. **Integración Siigo**: consistencia de datos, manejo de errores en llamadas a la API, timeouts
3. **WhatsApp Business API**: validación de templates, manejo de rate limits, formato de payloads
4. **VPS**: permisos de archivos, comandos peligrosos, exposición de puertos o servicios si aplica
5. **Buenas prácticas Laravel**: queries N+1, uso correcto de queues/jobs, migraciones reversibles, convenciones del proyecto existente

Da un veredicto por archivo (✅ aprobado / ⚠️ con observaciones / ❌ bloqueante) y explica brevemente el porqué de cada hallazgo, no solo el qué.

Cierra siempre con un veredicto único y explícito de conjunto:
- **✅ Listo para subir** — ningún hallazgo bloqueante.
- **⚠️ Se puede subir, pero revisa esto primero** — lista los puntos concretos a decidir.
- **❌ No subir todavía** — lista qué hay que corregir antes de comitear/pushear.
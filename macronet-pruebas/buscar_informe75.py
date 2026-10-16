import json
from probar_api import login, request, results, OUT

if login()[0] == 200:
    for path in [
        '/api/v1/ventas/informes/75',
        '/api/v1/ventas/reportes/75',
        '/api/v1/informes/75',
        '/api/v1/reportes/75',
        '/api/v1/ventas/informes',
        '/api/v1/ventas/reportes',
        '/api/v1/ventas/documentos?limit=1&offset=0&fecha-inicial=2026-10-01&fecha-final=2026-10-03',
        '/api/v1/ventas/documentos?limit=1&offset=0&fecha_inicio=2026-10-01&fecha_fin=2026-10-03',
        '/api/v1/ventas/documentos?limit=1&offset=0&fecha=2026-10-01',
    ]:
        status, body = request(path, tenant='skyfree')
        if status == 200 and isinstance(body, list) and body:
            print(json.dumps({'first_keys':list(body[0])[:15], 'first_date':body[0].get('fecha'), 'first_id':body[0].get('id')}), flush=True)
(OUT / 'pruebas-informe75.json').write_text(json.dumps(results,indent=2,ensure_ascii=False),encoding='utf-8')

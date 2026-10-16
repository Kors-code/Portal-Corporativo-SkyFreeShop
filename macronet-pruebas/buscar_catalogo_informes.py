import json
from probar_api import login, request, results, OUT

if login()[0] == 200:
    for path in [
        '/api/v1/informes',
        '/api/v1/reportes',
        '/api/v1/generales/informes',
        '/api/v1/generales/reportes',
        '/api/v1/app/informes',
        '/api/v1/app/reportes',
        '/api/v1/ventas/informes/75?fecha-inicial=2026-10-01&fecha-final=2026-10-03',
        '/api/v1/ventas/reportes/75?fecha-inicial=2026-10-01&fecha-final=2026-10-03',
        '/informes/75',
        '/reportes/75',
        '/api/v1/ventas/documentos?limit=1&offset=0&order=fecha,desc',
    ]:
        s,b = request(path,tenant='skyfree')
        if s==200 and isinstance(b,list) and b:
            print(json.dumps({'first_keys':list(b[0])[:15], 'fecha':b[0].get('fecha')}),flush=True)
(OUT/'pruebas-catalogo-informes.json').write_text(json.dumps(results,ensure_ascii=False,indent=2),encoding='utf-8')

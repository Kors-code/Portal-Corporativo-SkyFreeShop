import json
from urllib.parse import urlencode
from probar_api import login, request, results, OUT

queries = [
 {'fecha':'2023-01-01T11:50:52'},
 {'fecha':'>=2026-10-01'},
 {'fecha':'2026-10-01,2026-10-03'},
 {'fecha':'2026-10-01|2026-10-03'},
 {'fecha-desde':'2026-10-01','fecha-hasta':'2026-10-03'},
 {'fecha': '2026-10-01', 'operador':'MAYOR_IGUAL'},
]
if login()[0] == 200:
    for q in queries:
        path='/api/v1/ventas/documentos?' + urlencode({'limit':1,'offset':0, **q})
        s,b=request(path,tenant='skyfree')
        if s==200 and isinstance(b,list) and b:
            print(json.dumps({'id':b[0].get('id'),'fecha':b[0].get('fecha')}),flush=True)
(OUT / 'pruebas-filtros-fecha.json').write_text(json.dumps(results,indent=2,ensure_ascii=False),encoding='utf-8')

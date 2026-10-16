"""Read-only Macronet route checks. Credentials and sessions are never saved."""
import json, os, urllib.request, urllib.error, http.cookiejar, re, hashlib
from pathlib import Path

BASE = 'http://74.208.89.166:8080'
OUT = Path(__file__).resolve().parent
OUT.mkdir(exist_ok=True)
jar = http.cookiejar.CookieJar()
client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
results = []
def request(path, data=None, tenant=None):
    headers = {'Accept': 'application/json'}
    if tenant: headers['tenantId'] = tenant
    if data is not None: headers['Content-Type'] = 'application/json'
    req = urllib.request.Request(BASE + path, data=json.dumps(data).encode() if data is not None else None, headers=headers)
    try:
        response = client.open(req, timeout=12)
    except urllib.error.HTTPError as error:
        response = error
    except Exception as error:
        row = {'method': req.get_method(), 'path': path, 'error': str(error)}
        results.append(row); print(json.dumps(row), flush=True)
        return None, None
    raw = response.read(4_000_000)
    try: body = json.loads(raw)
    except (ValueError, UnicodeDecodeError): body = raw.decode(errors='replace')
    row = {'method': req.get_method(), 'path': path, 'tenant': tenant, 'status': response.code, 'bytes': len(raw)}
    if isinstance(body, dict):
        row['keys'] = list(body)[:25]
        row['message'] = {k: body[k] for k in ('mensaje','message','estatus','error') if k in body}
    elif isinstance(body, list): row['count'] = len(body)
    else: row['preview'] = body[:180]
    results.append(row); print(json.dumps(row, ensure_ascii=True), flush=True)
    if response.code == 200 and req.get_method() == 'GET':
        name = path.strip('/').replace('/', '_').replace('?', '_').replace('&', '_').replace('=', '-') or 'root'
        name = re.sub(r'[<>:"\\|*]', '_', name)
        if len(name) > 110: name = name[:90] + '_' + hashlib.sha256(path.encode()).hexdigest()[:12]
        (OUT / (name + '.json')).write_text(json.dumps(body, ensure_ascii=False, indent=2), encoding='utf-8')
    return response.code, body

def login():
    data = {'usuario': os.environ['MACRO_USER'], 'contrasena': os.environ['MACRO_PASSWORD'], 'uuid': '9999-9999-99HG-SACC-UUID', 'tipo_sesion': 'ESCRITORIO', 'direccion_mac': 'FA:16:3E:49:A9:BF', 'reemplazar': True}
    return request('/login', data, 'skyfree')

if __name__ == '__main__':
    status, body = login()
    if status == 200:
        for path in ['/api/v1/ventas/documentos?limit=100&offset=0', '/api/v1/ventas/documentos?limit=5&offset=100', '/api/v1/ventas/documentos/3']:
            request(path, tenant='skyfree')
    previous = json.loads((OUT / 'resultados.json').read_text(encoding='utf-8')) if (OUT / 'resultados.json').exists() else []
    (OUT / 'resultados.json').write_text(json.dumps(previous + results, indent=2, ensure_ascii=False), encoding='utf-8')


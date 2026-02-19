import  { useEffect, useMemo, useState } from 'react';
import axios from 'axios';

type Category = { id: string | number; name: string };
type CatalogProduct = { id: number; sku?: string; product?: string; brand?: string; category?: string; supplier?: string };
type WishItem = { id: number; product_text: string; category?: string; catalog_product_id?: number | null; indicator: 'never_had'|'we_have_or_had'; created_at?: string };
type SellerUser = {
  id: number;
  name: string;
  role: string;
};
type CurrentUser = {
  id: number;
  name: string;
  role: string;
};


export default function CatalogMatchPage({ apiBase = '/api/v1' }: { apiBase?: string }) {
  const [categories, setCategories] = useState<Category[]>([]);
  const [category, setCategory] = useState<string | null>(null);
  const [query, setQuery] = useState('');
  const [catalog, setCatalog] = useState<CatalogProduct[]>([]);
  const [wishItems, setWishItems] = useState<WishItem[]>([]);
  const [selectedSuggestion, setSelectedSuggestion] = useState<CatalogProduct | null>(null);
  const [loading, setLoading] = useState(false);
  const [adding, setAdding] = useState(false);
  const [error, setError] = useState<string | null>(null);
  
  
  const [sellers, setSellers] = useState<SellerUser[]>([]);
  const [selectedUserId, setSelectedUserId] = useState<number | null>(null);
  const [currentUser, setCurrentUser] = useState<CurrentUser | null>(null);
  
  // debounce simple
  useEffect(() => {
    const t = setTimeout(() => fetchLists(), 250);
    return () => clearTimeout(t);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [query, category]);

  useEffect(() => {
  console.log('useEffect inicial: cargando datos...');
  loadCategories();
  loadCurrentUser();
  fetchLists();
  // eslint-disable-next-line react-hooks/exhaustive-deps
}, []);

async function loadCurrentUser() {
  console.log('loadCurrentUser: iniciando petición /me');
  try {
    const res = await axios.get(`${apiBase}/me`, { withCredentials: true });
    console.log('/api/v1/me response raw:', res);

    // Manejar res.data o res.data.user (por si backend devuelve { user: {...} })
    const user = res.data?.user ?? res.data;
    if (!user) {
      console.warn('loadCurrentUser: res.data no tiene user ni payload esperado', res.data);
      setCurrentUser(null);
      return;
    }

    // Normalizar id a number (por si viene como string)
    user.id = Number(user.id);
    setCurrentUser(user);
    console.log('me ->', user);

    // Si es admin O es el id especial 69, cargamos vendedores
    if (user.role === 'super_admin'|| user.role === 'admin' || user.role === '' || user.id === 69) {
      await loadSellers();
    } else {
      setSelectedUserId(user.id);
    }
  } catch (e: any) {
    // Mostrar detalles del error para depurar
    console.error('No se pudo cargar usuario:', e?.response?.status, e?.response?.data ?? e.message);
  }
}

console.log(currentUser);

  async function loadCategories() {
    try {
      const res = await axios.get(`${apiBase}/catalog/categories`);
      const data = res?.data ?? [];
      setCategories(Array.isArray(data) ? data.map((d: any) => ({ id: d.id ?? d, name: d.name ?? d })) : []);
    } catch (e: any) {
      setCategories([]);
    }
  }
async function loadSellers() {
  try {
    const res = await axios.get(`${apiBase}/users/sellers`);
    setSellers(res.data ?? []);
  } catch {
    setSellers([]);
  }
}

  async function fetchLists() {
    setLoading(true);
    setError(null);
    try {
      const params = { q: query || undefined, category: category || undefined };
      const [cRes, wRes] = await Promise.all([
        axios.get(`${apiBase}/catalog-products`, { params }),
        axios.get(`${apiBase}/wish-items`, { params })
      ]);
      setCatalog(cRes?.data ?? []);
      setWishItems(wRes?.data ?? []);
    } catch (e: any) {
      setError('Error cargando datos');
    } finally {
      setLoading(false);
    }
  }

  function onSelectSuggestion(p: CatalogProduct) {
    setSelectedSuggestion(p);
    setQuery(p.product ?? p.sku ?? '');
  }

  async function handleAdd() {
    if (!query.trim()) return;
    setAdding(true);
    setError(null);

    try {
      const payload: any = {
        product_text: query.trim(),
        category: category || null,
        user_id: currentUser?.role === 'admin'
        ? selectedUserId
        : currentUser?.id,

      };


      if (selectedSuggestion) {
        payload.catalog_product_id = selectedSuggestion.id;
      } else {
        // intentar si la búsqueda devuelve exactamente 1 match en catálogo -> enlazarlo
        if (catalog.length === 1) {
          payload.catalog_product_id = catalog[0].id;
        }
      }

      const res = await axios.post(`${apiBase}/wish-items`, payload);
      const created = res?.data;
      setWishItems(prev => [created, ...prev]);
      setQuery('');
      setSelectedSuggestion(null);
      // opcional: refrescar catálogo
      fetchLists();
    } catch (e: any) {
      setError(e?.response?.data?.message ?? 'No se pudo agregar');
    } finally {
      setAdding(false);
    }
  }

  async function toggleIndicator(item: WishItem) {
    const newIndicator = item.indicator === 'never_had' ? 'we_have_or_had' : 'never_had';
    try {
      const res = await axios.patch(`${apiBase}/wish-items/${item.id}`, { indicator: newIndicator });
      const updated = res?.data;
      setWishItems(prev => prev.map(w => w.id === updated.id ? updated : w));
    } catch (e:any) {
      setError('No se pudo actualizar');
    }
  }

  // suggestions for dropdown
  const suggestions = useMemo(() => {
    const q = query.trim().toLowerCase();
    if (!q) return [];
    return catalog.slice(0, 8);
  }, [catalog, query]);

  return (
    <div className="p-6 max-w-6xl mx-auto space-y-6">
      <h1 className="text-2xl font-bold">Match catálogo ↔ deseos</h1>

            {currentUser && currentUser.id === 69 && (
        <div className="mb-3">
          <label className="text-sm text-gray-700 block mb-1">
            Seleccionar vendedor / cajero{currentUser.role } {currentUser.id} {/* Mostrar rol e ID del usuario actual */  }
          </label>
          <select
            value={selectedUserId ?? ''}
            onChange={e => setSelectedUserId(Number(e.target.value))}
            className="w-full rounded px-3 py-2 bg-gray-200 border border-gray-300"
          >
            <option value="">Seleccionar usuario</option>
            {sellers.map(u => (
              <option key={u.id} value={u.id}>
                {u.name} ({u.role})
              </option>
            ))}
          </select>
        </div>
      )}

      <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div>
          <label className="text-sm text-gray-600">Categoría</label>
          <select value={category ?? ''} onChange={e => setCategory(e.target.value || null)} className="w-full border rounded px-3 py-2">
            <option value="">-- Todas --</option>
            {categories.map(c => <option key={String(c.id)} value={c.name}>{c.name}</option>)}
          </select>
        </div>

        <div className="md:col-span-2">
          <label className="text-sm text-gray-600">Buscar / Ingresar (un solo input)</label>
          <div className="relative">
            <input
              value={query}
              onChange={e => { setQuery(e.target.value); setSelectedSuggestion(null); }}
              onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); handleAdd(); } }}
              placeholder="Escribe nombre, sku o marca..."
              className="w-full border rounded px-3 py-2"
            />

            {/* sugerencias */}
            {suggestions.length > 0 && query.trim() !== '' && (
              <div className="absolute z-20 left-0 right-0 bg-white border mt-1 rounded max-h-56 overflow-auto shadow">
                {suggestions.map(s => (
                  <div key={s.id} onClick={() => onSelectSuggestion(s)} className="p-2 hover:bg-gray-100 cursor-pointer">
                    <div className="font-medium">{s.product}</div>
                    <div className="text-xs text-gray-500">{s.brand} {s.sku ? `— ${s.sku}` : ''}</div>
                  </div>
                ))}
              </div>
            )}
          </div>

          <div className="mt-3 flex gap-2">
            <button onClick={handleAdd} disabled={adding || !query.trim()} className={`px-4 py-2 rounded text-white ${adding || !query.trim() ? 'bg-gray-400' : 'bg-blue-600'}`}>
              {adding ? 'Agregando...' : 'Agregar'}
            </button>
            <button onClick={() => { setQuery(''); setSelectedSuggestion(null); }} className="px-3 py-2 border rounded">Limpiar</button>
            <div className="text-sm text-gray-500 ml-auto">Si no seleccionas sugerencia será marcado como <strong>wishlist</strong> (never_had).</div>
          </div>
        </div>
      </div>

      {error && <div className="text-red-600">{error}</div>}
      {loading && <div className="text-gray-600">Cargando…</div>}

      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
        {/* Left: Wish items */}
        <div className="bg-white rounded shadow p-4 min-h-[300px]">
          <h3 className="font-semibold mb-2">Aportados / Wish Items ({wishItems.length})</h3>
          <div className="space-y-2 max-h-[420px] overflow-auto">
            {wishItems.map(w => (
              <div key={w.id} className="border rounded p-2 flex items-start justify-between">
                <div>
                  <div className="font-medium">{w.product_text}</div>
                  <div className="text-xs text-gray-500">{w.category ?? '—'}</div>
                  <div className="text-xs mt-1">{w.catalog_product_id ? <span className="text-green-600">Relacionado a catálogo</span> : <span className="text-orange-600">No relacionado</span>}</div>
                </div>
                <div className="text-right space-y-2">
                  <div className="text-sm">{w.indicator === 'never_had' ? 'Wishlist' : 'Lo tuvimos/tenemos'}</div>
                  <button onClick={() => toggleIndicator(w)} className="text-xs text-indigo-600">Cambiar indicador</button>
                </div>
              </div>
            ))}

            {wishItems.length === 0 && <div className="text-sm text-gray-500">No hay aportes todavía.</div>}
          </div>
        </div>

        {/* Right: Catalog */}
        <div className="bg-white rounded shadow p-4 min-h-[300px]">
          <h3 className="font-semibold mb-2">Catálogo ({catalog.length})</h3>

          <div className="space-y-2 max-h-[420px] overflow-auto">
            {catalog.map(p => (
              <div key={p.id} className="border rounded p-2 flex items-start justify-between">
                <div>
                  <div className="font-medium">{p.product}</div>
                  <div className="text-xs text-gray-500">{p.brand} {p.sku ? `— ${p.sku}` : ''}</div>
                </div>
                <div>
                  <button onClick={() => { setQuery(p.product ?? p.sku ?? ''); setSelectedSuggestion(p); }} className="text-sm text-indigo-600">Seleccionar</button>
                </div>
              </div>
            ))}

            {catalog.length === 0 && <div className="text-sm text-gray-500">No hay productos en catálogo para esta búsqueda.</div>}
          </div>
        </div>
      </div>
    </div>
  );
}

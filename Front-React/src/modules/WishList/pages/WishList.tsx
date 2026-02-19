// CatalogMatchPage.tsx
import { useEffect, useMemo, useState } from 'react';
import axios from 'axios';

type Category = { id: string | number; name: string };
type CatalogProduct = { id: number; product?: string; brand?: string; sku?: string; category?: string };
type WishItem = {
  id: number;
  product_text: string;
  category?: string | null;
  catalog_product_id?: number | null;
  count?: number | null;
};
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
  const [selectedLeft, setSelectedLeft] = useState<WishItem | null>(null);
  const [selectedRight, setSelectedRight] = useState<CatalogProduct | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  // cooldowns map: key -> expiryTimestamp(ms)
  const [cooldowns, setCooldowns] = useState<Record<string, number>>({});
  // tick to re-render countdowns every second
    const [sellers, setSellers] = useState<SellerUser[]>([]);
  const [selectedUserId, setSelectedUserId] = useState<number | null>(null);
  const [, setTick] = useState(0);
  const [currentUser, setCurrentUser] = useState<CurrentUser | null>(null);
  const [sellerQuery, setSellerQuery] = useState('');
const [showSellerDropdown, setShowSellerDropdown] = useState(false);


  useEffect(() => {
  console.log('useEffect inicial: cargando datos...');
  loadCategories();
  loadCurrentUser();
  fetchLists();
  // eslint-disable-next-line react-hooks/exhaustive-deps
}, []);

  useEffect(() => {
    const t = setTimeout(fetchLists, 300);
    return () => clearTimeout(t);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [query, category]);

  // interval to update countdowns UI
  useEffect(() => {
    const iv = setInterval(() => setTick(t => t + 1), 1000);
    return () => clearInterval(iv);
  }, []);

  async function loadCategories() {
    try {
      const res = await axios.get(`${apiBase}/catalog/categories`);
      setCategories(res.data ?? []);
    } catch {
      setCategories([]);
    }
  }
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
    if (user.role === 'super_admin'|| user.role === 'admin' || user.role === 'adminpresupuesto' || user.id === 69) {
      await loadSellers();
    } else {
      setSelectedUserId(user.id);
    }
  } catch (e: any) {
    // Mostrar detalles del error para depurar
    console.error('No se pudo cargar usuario:', e?.response?.status, e?.response?.data ?? e.message);
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
        axios.get(`${apiBase}/catalog-products`, { params }).catch(() => ({ data: [] })),
        axios.get(`${apiBase}/wish-items`, { params }).catch(() => ({ data: [] }))
      ]);
      setCatalog(cRes.data ?? []);
      setWishItems(wRes.data ?? []);
    } catch {
      setError('Error cargando datos');
    } finally {
      setLoading(false);
    }
  }

  // Left: only free-text wishes (no catalog link)
  const leftWishItems = useMemo(() => wishItems.filter(w => !w.catalog_product_id), [wishItems]);

  const filteredSellers = useMemo(() => {
  if (!sellerQuery.trim()) return sellers;
  return sellers.filter(s =>
    s.name.toLowerCase().includes(sellerQuery.toLowerCase())
  );
}, [sellerQuery, sellers]);


  // counts for catalog products (sum of wish.count for items linked to product)

  // helpers for cooldown keys and remaining seconds
  function cooldownKeyForSelection() {
    if (selectedLeft) return `wish:${selectedLeft.id}`;
    if (selectedRight) return `catalog:${selectedRight.id}`;
    if (query.trim()) return `text:${query.trim().toLowerCase()}`;
    return null;
  }
  function getRemainingSeconds(key: string | null) {
    if (!key) return 0;
    const expiry = cooldowns[key] ?? 0;
    const rem = Math.ceil(Math.max(0, expiry - Date.now()) / 1000);
    return rem;
  }
  function isCooling(key: string | null) {
    return getRemainingSeconds(key) > 0;
  }

  // unified send
  async function handleEnviar() {
    setError(null);

    const key = cooldownKeyForSelection();
    if (key && isCooling(key)) {
      const rem = getRemainingSeconds(key);
      setError(`Espera ${rem}s antes de volver a enviar este elemento.`);
      return;
    }

    if (!selectedLeft && !selectedRight && !query.trim()) {
      setError('Escribe algo o selecciona un item.');
      return;
    }

    setSaving(true);
    try {
      let payload: any = {};

      if (selectedLeft) {
        payload = { wish_item_id: selectedLeft.id, user_id: selectedUserId ?? currentUser?.id };
      } else if (selectedRight) {
        payload = {
          catalog_product_id: selectedRight.id,
          product_text: selectedRight.product,
          category: selectedRight.category ?? category ?? null,
          user_id: selectedUserId ?? currentUser?.id
        };
      } else {
        payload = { product_text: query.trim(), category: category ?? null, user_id: selectedUserId ?? currentUser?.id };
      }

      const res = await axios.post(`${apiBase}/wish-items/select`, payload);
      const wish: WishItem | undefined = res?.data?.wish ?? res?.data;

      if (wish) {
        setWishItems(prev => {
          const idx = prev.findIndex(p => p.id === wish.id);
          if (idx >= 0) {
            const copy = [...prev];
            copy[idx] = wish;
            return copy;
          } else {
            return [wish, ...prev];
          }
        });
      } else {
        await fetchLists();
      }

      // set cooldown for the selection key (30 seconds)
      const newKey = cooldownKeyForSelection();
      if (newKey) {
        setCooldowns(prev => ({ ...prev, [newKey]: Date.now() + 10_000 }));
      }

      // clear input if was free-text
      if (!selectedLeft && !selectedRight) setQuery('');
      setSelectedLeft(null);
      setSelectedRight(null);
    } catch (e: any) {
      setError(e?.response?.data?.message ?? 'No se pudo registrar la selección');
    } finally {
      setSaving(false);
    }
  }

  // quick pick functions
  function pickLeft(w: WishItem) {
    setSelectedLeft(w);
    setSelectedRight(null);
    setError(null);
  }
  function pickRight(p: CatalogProduct) {
    setSelectedRight(p);
    setSelectedLeft(null);
    setError(null);
  }

  // visual helpers
  function renderCooldownBadgeForWish(w: WishItem) {
    const k = `wish:${w.id}`;
    const rem = getRemainingSeconds(k);
    if (rem > 0) return <div className="text-xs text-red-600">Enfriando: {rem}s</div>;
    return null;
  }
  function renderCooldownBadgeForCatalog(p: CatalogProduct) {
    const k = `catalog:${p.id}`;
    const rem = getRemainingSeconds(k);
    if (rem > 0) return <div className="text-xs text-red-600">Enfriando: {rem}s</div>;
    return null;
  }
  useEffect(() => {
  function handleClickOutside() {
    setShowSellerDropdown(false);
  }
  window.addEventListener('click', handleClickOutside);
  return () => window.removeEventListener('click', handleClickOutside);
}, []);


  // disable enviar when saving or current selection is cooling
  const currentKey = cooldownKeyForSelection();
  const currentRemaining = getRemainingSeconds(currentKey);
  const enviarDisabled = saving || (currentRemaining > 0);

  return (
    
    <div className="p-4 sm:p-6 max-w-4xl mx-auto">
      <div className="fixed top-4 left-4 z-50 flex gap-3">
  <button
    onClick={() => window.location.href = '/welcome'}
    className="px-4 py-2 bg-gray-800 hover:bg-gray-900 text-white rounded-lg shadow-md"
  >
    ← Volver
  </button>

</div>

      <header className="mb-4">
        <h1 className="text-xl sm:text-2xl font-bold text-center text-[#840028]">Wish List & Denied</h1>
        <p className="text-xs text-gray-500 mt-1 text-center">Selecciona un item y presiona "Enviar (+1)". Evita envío repetido por 30s.</p>
      </header>
      {(
  currentUser?.role === 'super_admin' ||
  currentUser?.role === 'admin' ||
  currentUser?.role === 'adminpresupuesto'
) && (
  <button
    onClick={() => window.location.href = '/panel/AdminWishList'}
    className="px-4 py-2 bg-[#840028] hover:bg-[#6a0020] text-white rounded-lg  shadow-md mb-4 text-end block ml-auto"
  >
    Admin
  </button>
)}

                  {(
  currentUser?.role === 'super_admin' ||
  currentUser?.role === 'admin' ||
  currentUser?.role === 'adminpresupuesto'||
  currentUser?.id === 69
)  && (
  <div className="relative">
    <input
      value={sellerQuery}
      onChange={e => {
        setSellerQuery(e.target.value);
        setShowSellerDropdown(true);
      }}
      onFocus={() => setShowSellerDropdown(true)}
      placeholder="Buscar vendedor..."
      className="w-full rounded px-3 py-2 bg-gray-200 border border-gray-300"
    />

    {showSellerDropdown && filteredSellers.length > 0 && (
      <div className="absolute z-20 left-0 right-0 bg-white border mt-1 rounded shadow max-h-60 overflow-auto">
        {filteredSellers.map(u => (
          <div
            key={u.id}
            onClick={() => {
              setSelectedUserId(u.id);
              setSellerQuery(`${u.name} (${u.role})`);
              setShowSellerDropdown(false);
            }}
            className="p-2 hover:bg-indigo-50 cursor-pointer"
          >
            <div className="font-medium text-sm">{u.name}</div>
            <div className="text-xs text-gray-500">{u.role}</div>
          </div>
        ))}
      </div>
    )}
  </div>
)}



      {/* filters + input (mobile-first) */}
      <div className="space-y-3 mb-4">
        <div>
          <label className="text-sm text-gray-700 block mb-1">Categoría</label>
          <select
            value={category ?? ''}
            onChange={e => setCategory(e.target.value || null)}
            className="w-full rounded px-3 py-2 bg-gray-200 text-gray-800 border border-gray-300 focus:outline-none"
          >
            <option value="">-- Todas --</option>
            {categories.map(c => (
              <option key={String(c.id)} value={c.name}>{c.name}</option>
            ))}
          </select>
        </div>

        <div>
          <label className="text-sm text-gray-700 block mb-1">Buscar / escribir</label>
          <input
            value={query}
            onChange={e => setQuery(e.target.value)}
            placeholder="Buscar o escribir..."
            className="w-full rounded px-3 py-3 border border-gray-200 focus:outline-none focus:ring-2 focus:ring-indigo-200"
          />
        </div>
      </div>

      {error && <div className="text-red-600 mb-3">{error}</div>}
      {loading && <div className="text-gray-600 mb-3">Cargando…</div>}

      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
        {/* LEFT: free-text wish items */}
        <div className="bg-white shadow rounded p-3">
          <div className="flex items-center justify-between mb-3">
            <h3 className="font-semibold">Wish List</h3>
            <div className="text-xs text-gray-500 hidden md:block">{leftWishItems.length} items</div>
          </div>

          <div className="space-y-2 max-h-[60vh] overflow-auto -mx-2 px-2">
            {leftWishItems.length === 0 && <div className="text-sm text-gray-500 p-2">No hay aportes.</div>}

            {leftWishItems.map(w => {
              const isSelected = selectedLeft?.id === w.id;
              return (
                <button
                  key={w.id}
                  onClick={() => pickLeft(w)}
                  className={`w-full text-left border rounded p-3 flex items-start justify-between ${isSelected ? 'bg-blue-50' : 'bg-white'}`}
                  type="button"
                >
                  <div className="flex-1">
                    <div className="font-medium text-sm">{w.product_text}</div>
                    <div className="text-xs text-gray-500 mt-1">{w.category ?? '—'}</div>
                  </div>

                  <div className="ml-3 flex flex-col items-end">
                    <div className="text-sm text-gray-700"></div>
                    {renderCooldownBadgeForWish(w)}
                  </div>
                </button>
              );
            })}
          </div>
        </div>

        {/* RIGHT: catalog */}
        <div className="bg-white shadow rounded p-3">
          <div className="flex items-center justify-between mb-3">
            <h3 className="font-semibold">Denied</h3>
            <div className="text-xs text-gray-500 hidden md:block">{catalog.length} productos</div>
          </div>

          <div className="space-y-2 max-h-[60vh] overflow-auto -mx-2 px-2">
            {catalog.length === 0 && <div className="text-sm text-gray-500 p-2">No hay productos en catálogo para esta búsqueda.</div>}

            {catalog.map(p => {
              const isSelected = selectedRight?.id === p.id;
              return (
                <button
                  key={p.id}
                  onClick={() => pickRight(p)}
                  className={`w-full text-left border rounded p-3 flex items-start justify-between ${isSelected ? 'bg-green-50' : 'bg-white'}`}
                  type="button"
                >
                  <div className="flex-1">
                    <div className="font-medium text-sm">{p.product}</div>
                    <div className="text-xs text-gray-500 mt-1">{p.brand} {p.sku ? `— ${p.sku}` : ''}</div>
                  </div>

                  <div className="ml-3 flex flex-col items-end">
                    <div className="text-sm text-gray-700"></div>
                    {renderCooldownBadgeForCatalog(p)}
                  </div>
                </button>
              );
            })}
          </div>
        </div>
      </div>

      {/* Enviar */}
      <div className="flex justify-end mt-4">
        <button
          onClick={handleEnviar}
          disabled={enviarDisabled}
          className={`px-6 py-3 rounded text-white ${enviarDisabled ? 'bg-[#840028]' : 'bg-[#840028] hover:[#6a0020]'} shadow-md`}
        >
          {saving ? 'Registrando...' : 'Enviar (+1)'}
        </button>
      </div>
    </div>
  );
}

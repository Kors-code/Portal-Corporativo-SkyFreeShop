import { useEffect, useMemo, useState, type JSX } from 'react';
import { Save, Search, Users, CheckSquare, X, Zap } from 'lucide-react';

type Permission = { id: number; name: string };
type User = {
  id: number;
  name: string;
  email?: string;
  role?: string;
  role_id?: number;
  permissions?: Permission[];
  permission_ids?: number[];
};

type GroupedPermissions = Record<string, Permission[]>;

/**
 * Presets: cada entry contiene label y lista de patrones.
 * Los patrones pueden ser:
 *  - '*' => todos
 *  - 'prefix*' => startsWith (ej 'commissions*' matchea commissions.view)
 *  - 'substring' => contains (ej 'cashier' matchea budget.cashier.view)
 */
const ROLE_PRESETS: Record<string, { label: string; match: string[] }> = {
  super_admin: { label: 'Super Admin (todo)', match: ['*'] },
  administrativo: {
    label: 'Administrativo (portal + presupuesto)',
    match: ['portal.view', 'budgets.view', 'budget.view', 'presupuesto', 'budget.cashier.view'],
  },
  lider: { label: 'Líder (comisiones / imports / advisors)', match: ['commissions', 'imports', 'advisors'] },
  seller: { label: 'Seller (mis comisiones / budgets)', match: ['commissions.view', 'budgets.view', 'commissions'] },
  cashier: { label: 'Cashier (reports / cashier-awards)', match: ['reports.view', 'budget.cashier.view', 'cashier'] },
};

export default function AdminPermissionsPanel(): JSX.Element {
  const [permissions, setPermissions] = useState<Permission[]>([]);
  const [users, setUsers] = useState<User[]>([]);
  const [selectedUser, setSelectedUser] = useState<User | null>(null);
  const [userPermissions, setUserPermissions] = useState<Set<number>>(new Set());
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [search, setSearch] = useState('');
  const [filterRole, setFilterRole] = useState<string>('all');
  const [toast, setToast] = useState<string | null>(null);
  const [activeModule, setActiveModule] = useState<string>('all'); // sidebar filter

  // lee csrf token desde meta (Laravel)
  const csrfToken =
    typeof document !== 'undefined' ? document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '' : '';

  useEffect(() => {
    void fetchInitialData();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  async function fetchInitialData() {
    setLoading(true);
    try {
      const [permRes, usersRes] = await Promise.all([
        fetch('/api/v1/permissions', { credentials: 'include' }),
        fetch('/api/v1/admin/users-with-permissions', { credentials: 'include' }),
      ]);

      if (!permRes.ok) throw new Error(`Error fetching permissions (${permRes.status})`);
      if (!usersRes.ok) throw new Error(`Error fetching users (${usersRes.status})`);

      const permsJson = await permRes.json();
      const usersJson = await usersRes.json();

      const normalizedPerms: Permission[] = Array.isArray(permsJson) ? permsJson : (permsJson.data ?? permsJson.permissions ?? []);
      const normalizedUsers: User[] = Array.isArray(usersJson) ? usersJson : (usersJson.data ?? usersJson.users ?? []);

      setPermissions(normalizedPerms);
      setUsers(normalizedUsers);

      // seleccionar primer usuario si existe
      if (normalizedUsers && normalizedUsers.length) {
        await selectUser(normalizedUsers[0]);
      }
    } catch (e: any) {
      console.error(e);
      setToast('Error cargando datos. Revisa la consola.');
      window.setTimeout(() => setToast(null), 3000);
    } finally {
      setLoading(false);
    }
  }

  // agrupar permisos por módulo (prefijo antes del primer punto)
  const grouped = useMemo(() => {
    const g: GroupedPermissions = {};
    permissions.forEach((p) => {
      const parts = (p.name || '').split('.');
      const module = parts[0] || 'other';
      if (!g[module]) g[module] = [];
      g[module].push(p);
    });
    Object.keys(g).forEach((m) => g[m].sort((a, b) => a.name.localeCompare(b.name)));
    return g;
  }, [permissions]);

  const modulesList = useMemo(() => {
    const keys = Object.keys(grouped).sort();
    return ['all', ...keys];
  }, [grouped]);

  // obtener permisos efectivos de un usuario
  async function selectUser(user: User) {
    setSelectedUser(user);
    setUserPermissions(new Set()); // limpiar mientras carga
    try {
      const res = await fetch(`/api/v1/users/${user.id}/permissions`, { credentials: 'include' });
      if (!res.ok) {
        console.warn('Error fetching user permissions: ', res.status);
        applyPermissionsFromUserList(user);
        return;
      }
      const json = await res.json();
      if (json && Array.isArray(json.effective_permissions)) {
        setUserPermissions(new Set(json.effective_permissions.map((p: any) => Number(p.id)).filter(Boolean)));
        return;
      }
      if (json && Array.isArray(json.permissions)) {
        setUserPermissions(new Set(json.permissions.map((p: any) => Number(p.id)).filter(Boolean)));
        return;
      }
      applyPermissionsFromUserList(user);
    } catch (err) {
      console.error(err);
      applyPermissionsFromUserList(user);
    }
  }

  // fallback: leer permisos desde users[] (propiedades permissions o permission_ids)
  function applyPermissionsFromUserList(user: User) {
    const fromList = users.find((u) => u.id === user.id) as any;
    if (fromList && Array.isArray(fromList.permissions)) {
      setUserPermissions(new Set(fromList.permissions.map((p: any) => Number(p.id)).filter(Boolean)));
    } else if (fromList && Array.isArray(fromList.permission_ids)) {
      setUserPermissions(new Set((fromList.permission_ids as any[]).map((n) => Number(n)).filter(Boolean)));
    } else {
      setUserPermissions(new Set());
    }
  }

  function isChecked(pid: number) {
    return userPermissions.has(pid);
  }

  function togglePermission(pid: number) {
    setUserPermissions((prev) => {
      const next = new Set(prev);
      if (next.has(pid)) next.delete(pid);
      else next.add(pid);
      return next;
    });
  }

  function toggleModule(module: string) {
    const modulePerms = grouped[module] ?? [];
    const allSelected = modulePerms.every((p) => userPermissions.has(p.id));
    setUserPermissions((prev) => {
      const next = new Set(prev);
      if (allSelected) modulePerms.forEach((p) => next.delete(p.id));
      else modulePerms.forEach((p) => next.add(p.id));
      return next;
    });
  }

  function clearPermissions() {
    setUserPermissions(new Set());
  }

  // Helper: comprobar si un permiso (nombre) cumple un patrón
  function matchesPattern(name: string, pattern: string) {
    const n = name.toLowerCase();
    const p = pattern.toLowerCase();
    if (p === '*') return true;
    if (p.endsWith('*')) {
      const prefix = p.slice(0, -1);
      return n.startsWith(prefix);
    }
    // contains or exact
    return n === p || n.startsWith(p) || n.includes(p);
  }

  // Aplica preset (role) — construye set de ids coincidentes
  function applyPresetByRoleKey(roleKey: string) {
    if (!ROLE_PRESETS[roleKey]) return;
    const preset = ROLE_PRESETS[roleKey];
    if (preset.match.includes('*')) {
      setUserPermissions(new Set(permissions.map((p) => p.id)));
      setToast(`Preset "${preset.label}" aplicado (todo).`);
      window.setTimeout(() => setToast(null), 1800);
      return;
    }

    const matched = new Set<number>();
    for (const perm of permissions) {
      for (const pat of preset.match) {
        if (matchesPattern(perm.name, pat)) {
          matched.add(perm.id);
          break;
        }
      }
    }
    setUserPermissions(matched);
    setToast(`Preset "${preset.label}" aplicado.`);
    window.setTimeout(() => setToast(null), 1800);
  }

  async function savePermissions() {
    if (!selectedUser) return setToast('Selecciona un usuario');
    setSaving(true);
    try {
      const body = { permissions: Array.from(userPermissions) };
      const res = await fetch(`/api/v1/users/${selectedUser.id}/permissions`, {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-CSRF-TOKEN': csrfToken || '',
        },
        body: JSON.stringify(body),
      });

      if (res.status === 419) {
        setToast('Sesión expirada. Inicia sesión de nuevo.');
        throw new Error('CSRF / session expired (419)');
      }
      if (!res.ok) {
        const txt = await res.text().catch(() => '');
        console.error('Save failed:', res.status, txt);
        throw new Error('Save failed');
      }

      setToast('Permisos guardados correctamente');
      // refrescar permisos del usuario guardado
      await selectUser(selectedUser);
    } catch (e) {
      console.error(e);
      setToast('Error guardando permisos');
    } finally {
      setSaving(false);
      window.setTimeout(() => setToast(null), 2200);
    }
  }

  const filteredUsers = useMemo(() => {
    return users.filter((u) => {
      const matchSearch = (u.name + ' ' + (u.email || '')).toLowerCase().includes(search.toLowerCase());
      const matchRole = filterRole === 'all' || (u.role || '').toLowerCase() === filterRole.toLowerCase();
      return matchSearch && matchRole;
    });
  }, [users, search, filterRole]);

  // módulos a renderizar (si activeModule === 'all' -> todos los módulos)
  const modulesToRender = useMemo(() => {
    if (activeModule === 'all') return Object.keys(grouped).sort();
    return [activeModule];
  }, [grouped, activeModule]);

  return (
    <div className="p-6 bg-gray-50 min-h-screen">
      <div className="max-w-7xl mx-auto">
        {/* Header */}
        <div className="flex items-center justify-between mb-6">
          <h1 className="text-2xl font-bold flex items-center gap-3">
            <Users className="w-6 h-6" /> Administración de permisos — modular
          </h1>

          <div className="flex gap-3 items-center">
            <div className="relative">
              <input
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                className="pl-10 pr-3 py-2 border rounded shadow-sm w-72 bg-white"
                placeholder="Buscar usuario..."
              />
              <Search className="absolute left-3 top-2.5 w-4 h-4 text-gray-400" />
            </div>

            <select
              className="py-2 px-3 border rounded bg-white"
              value={filterRole}
              onChange={(e) => setFilterRole(e.target.value)}
            >
              <option value="all">Todos los roles</option>
              <option value="super_admin">Super Admin</option>
              <option value="administrativo">Administrativo</option>
              <option value="lider">Líder</option>
              <option value="seller">Seller</option>
              <option value="cashier">Cashier</option>
            </select>

            <div className="text-sm text-gray-600">
              Seleccionado: <span className="font-medium">{selectedUser?.name ?? '—'}</span>
            </div>
          </div>
        </div>

        <div className="grid grid-cols-12 gap-6">
          {/* Sidebar: módulos + presets */}
          <aside className="col-span-3 bg-white rounded shadow p-4 sticky top-6 h-[70vh] overflow-auto">
            <h2 className="font-semibold mb-3 flex items-center gap-2">
              <Zap className="w-4 h-4" /> Módulos
            </h2>

            <ul className="space-y-1 mb-4">
              {modulesList.map((m) => (
                <li key={m}>
                  <button
                    onClick={() => setActiveModule(m)}
                    className={`w-full text-left px-3 py-2 rounded ${activeModule === m ? 'bg-emerald-50 border' : 'hover:bg-gray-50'}`}
                  >
                    {m === 'all' ? 'Todos' : m.replace(/_/g, ' ')}
                    <span className="text-xs text-gray-400 ml-2">({m === 'all' ? permissions.length : grouped[m]?.length ?? 0})</span>
                  </button>
                </li>
              ))}
            </ul>

            <h3 className="font-semibold mb-2">Presets por rol</h3>
            <div className="space-y-2 mb-4">
              {Object.keys(ROLE_PRESETS).map((rk) => (
                <button
                  key={rk}
                  onClick={() => applyPresetByRoleKey(rk)}
                  className="w-full text-left px-3 py-2 border rounded hover:bg-gray-50 text-sm"
                >
                  {ROLE_PRESETS[rk].label}
                </button>
              ))}
              <button onClick={clearPermissions} className="w-full text-left px-3 py-2 border rounded hover:bg-gray-50 text-sm text-red-600">
                Limpiar selección
              </button>
            </div>

            <h3 className="font-semibold mb-2">Acciones rápidas</h3>
            <div className="flex gap-2">
              <button
                onClick={() => {
                  if (activeModule === 'all') {
                    setUserPermissions(new Set(permissions.map((p) => p.id)));
                  } else {
                    const ids = grouped[activeModule] ? grouped[activeModule].map((p) => p.id) : [];
                    setUserPermissions((prev) => {
                      const next = new Set(prev);
                      ids.forEach((id) => next.add(id));
                      return next;
                    });
                  }
                }}
                className="flex-1 px-3 py-2 border rounded text-sm"
              >
                Select visible
              </button>
              <button
                onClick={() => {
                  if (activeModule === 'all') {
                    setUserPermissions(new Set());
                  } else {
                    const ids = grouped[activeModule] ? grouped[activeModule].map((p) => p.id) : [];
                    setUserPermissions((prev) => {
                      const next = new Set(prev);
                      ids.forEach((id) => next.delete(id));
                      return next;
                    });
                  }
                }}
                className="px-3 py-2 border rounded text-sm"
              >
                Clear visible
              </button>
            </div>

            <div className="mt-4 text-xs text-gray-500">
              Tip: los presets buscan por prefijo o contenido en el nombre del permiso. Ajusta <code>ROLE_PRESETS</code> si tu BD usa nombres distintos.
            </div>
          </aside>

          {/* Users list */}
          <div className="col-span-3 bg-white rounded shadow p-4">
            <h2 className="font-semibold mb-3">Usuarios ({filteredUsers.length})</h2>
            {loading ? (
              <div>cargando...</div>
            ) : (
              <ul className="space-y-2 max-h-[68vh] overflow-auto">
                {filteredUsers.map((u) => (
                  <li
                    key={u.id}
                    className={`p-2 rounded hover:bg-gray-100 flex justify-between items-center cursor-pointer ${selectedUser?.id === u.id ? 'bg-gray-100 border' : ''}`}
                    onClick={() => void selectUser(u)}
                  >
                    <div>
                      <div className="font-medium">{u.name}</div>
                      <div className="text-xs text-gray-500">{u.email}</div>
                    </div>
                    <div className="text-xs text-gray-600 px-2 py-1 rounded bg-gray-100">{u.role}</div>
                  </li>
                ))}
              </ul>
            )}
          </div>

          {/* Permissions editor */}
          <div className="col-span-6 bg-white rounded shadow p-6">
            <div className="flex items-center justify-between mb-4">
              <div>
                <h3 className="text-lg font-semibold">Permisos {selectedUser ? `— ${selectedUser.name}` : ''}</h3>
                <div className="text-sm text-gray-500">Rol base: {selectedUser?.role || '—'}</div>
              </div>

              <div className="flex items-center gap-3">
                <button onClick={clearPermissions} className="px-3 py-2 border rounded flex items-center gap-2 text-sm" title="Limpiar permisos seleccionados">
                  <X className="w-4 h-4" /> Limpiar
                </button>
                <button
                  onClick={savePermissions}
                  disabled={!selectedUser || saving}
                  className="bg-emerald-500 text-white px-4 py-2 rounded flex items-center gap-2 disabled:opacity-60"
                  title="Guardar permisos para el usuario seleccionado"
                >
                  <Save className="w-4 h-4" /> {saving ? 'Guardando...' : 'Guardar cambios'}
                </button>
              </div>
            </div>

            <div className="grid grid-cols-1 gap-4 max-h-[64vh] overflow-auto">
              {modulesToRender.length === 0 || Object.keys(grouped).length === 0 ? (
                <div className="text-sm text-gray-500">No hay permisos definidos.</div>
              ) : (
                modulesToRender.map((module) => {
                  const perms = grouped[module] ?? [];
                  return (
                    <section key={module} className="border rounded p-3">
                      <div className="flex items-center justify-between mb-2">
                        <div className="font-medium capitalize">{module.replace(/_/g, ' ')}</div>
                        <div className="flex items-center gap-2">
                          <button onClick={() => toggleModule(module)} className="text-xs px-3 py-1 border rounded flex items-center gap-2">
                            <CheckSquare className="w-4 h-4" /> Toggle
                          </button>
                        </div>
                      </div>

                      <div className="space-y-2">
                        {perms.map((p) => (
                          <label key={p.id} className="flex items-center gap-3 text-sm">
                            <input type="checkbox" checked={isChecked(p.id)} onChange={() => togglePermission(p.id)} className="w-4 h-4" />
                            <span className="truncate">{p.name.split('.').slice(1).join('.') || p.name}</span>
                          </label>
                        ))}
                        {perms.length === 0 && <div className="text-xs text-gray-400">No hay permisos en este módulo.</div>}
                      </div>
                    </section>
                  );
                })
              )}
            </div>
          </div>
        </div>

        {/* Toast */}
        {toast && <div className="fixed bottom-6 right-6 bg-black text-white px-4 py-2 rounded shadow">{toast}</div>}
      </div>
    </div>
  );
}
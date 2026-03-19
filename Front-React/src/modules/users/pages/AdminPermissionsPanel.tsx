import { useEffect, useMemo, useState, type JSX } from 'react';
import { Save, Search, Users, CheckSquare, X,  Layers3,  Shield } from 'lucide-react';

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
type SubGroupedPermissions = Record<string, Permission[]>;

type ModuleDef = {
  key: string;
  label: string;
  description: string;
  match: (permissionName: string) => boolean;
};

const MODULES: ModuleDef[] = [
  {
    key: 'budget',
    label: 'Presupuesto',
    description: 'Configuración, comisiones, reportes y caja',
    match: (name) => {
      const n = name.toLowerCase();
      return (
        n.startsWith('budget.') ||
        n.startsWith('budgets.') ||
        n.startsWith('commissions.') ||
        n.startsWith('reports.') ||
        n.includes('cashier')
      );
    },
  },
  {
    key: 'candidates',
    label: 'Hojas de vida',
    description: 'Postulaciones, CV y gestión de candidatos',
    match: (name) => name.toLowerCase().startsWith('candidates.'),
  },
  {
    key: 'disciplines',
    label: 'Disciplinas positivas',
    description: 'Formularios, exportaciones y gestión disciplinaria',
    match: (name) => name.toLowerCase().startsWith('disciplines.'),
  },
  {
    key: 'users',
    label: 'Usuarios',
    description: 'Gestión de usuarios y perfiles',
    match: (name) => name.toLowerCase().startsWith('users.'),
  },
  {
    key: 'permissions',
    label: 'Permisos y roles',
    description: 'Roles, permisos y administración de accesos',
    match: (name) => name.toLowerCase().startsWith('permissions.'),
  },
  {
    key: 'imports',
    label: 'Importaciones',
    description: 'Carga masiva y lotes de importación',
    match: (name) => name.toLowerCase().startsWith('imports.'),
  },
  {
    key: 'advisors',
    label: 'Advisors',
    description: 'Asesores, especialistas y distribución',
    match: (name) => name.toLowerCase().startsWith('advisors.'),
  },
  {
    key: 'wishlist',
    label: 'Wish list',
    description: 'Catálogo, selección y administración',
    match: (name) => name.toLowerCase().startsWith('wishlist.') || name.toLowerCase().startsWith('wish-items.'),
  },
  {
    key: 'vacancies',
    label: 'Vacantes',
    description: 'Publicación y administración de vacantes',
    match: (name) => name.toLowerCase().startsWith('vacancies.'),
  },
  {
    key: 'panel',
    label: 'Panel',
    description: 'Acceso general al panel',
    match: (name) => name.toLowerCase().startsWith('panel.'),
  },
  {
    key: 'reports',
    label: 'Reportes',
    description: 'Consultas y exportaciones',
    match: (name) => name.toLowerCase().startsWith('reports.'),
  },
];

const ROLE_PRESETS: Record<string, { label: string; match: string[] }> = {
  super_admin: { label: 'Super Admin (todo)', match: ['*'] },
  administrativo: {
    label: 'Administrativo (panel + presupuesto)',
    match: ['panel.view', 'budget.', 'budgets.', 'commissions.', 'reports.', 'users.view', 'permissions.view'],
  },
  lider: {
    label: 'Líder (presupuesto + advisors + imports)',
    match: ['budget.', 'budgets.', 'commissions.', 'advisors.', 'imports.', 'reports.view'],
  },
  seller: {
    label: 'Seller (mis comisiones + presupuesto lectura)',
    match: ['commissions.view', 'budget.view', 'budgets.view', 'reports.view', 'panel.view'],
  },
  cashier: {
    label: 'Cashier (reportes + premios)',
    match: ['reports.view', 'budget.cashier.', 'budget.view', 'panel.view'],
  },
};

function normalizeText(value: string) {
  return (value || '').toLowerCase().trim();
}

function moduleLabel(moduleKey: string) {
  const found = MODULES.find((m) => m.key === moduleKey);
  return found ? found.label : moduleKey;
}

function getModuleDescription(moduleKey: string) {
  const found = MODULES.find((m) => m.key === moduleKey);
  return found ? found.description : '';
}

function matchesPattern(name: string, pattern: string) {
  const n = normalizeText(name);
  const p = normalizeText(pattern);

  if (p === '*') return true;
  if (p.endsWith('*')) return n.startsWith(p.slice(0, -1));

  return n === p || n.startsWith(p) || n.includes(p);
}

function getModuleKey(permissionName: string) {
  const found = MODULES.find((module) => module.match(permissionName));
  return found?.key ?? 'other';
}

function getSubgroupLabel(moduleKey: string, permissionName: string) {
  const n = normalizeText(permissionName);
  const parts = n.split('.');
  const second = parts[1] ?? '';

  if (moduleKey === 'budget') {
    if (n.includes('cashier')) return 'Cajeros';
    if (n.includes('commission')) return 'Comisiones';
    if (n.includes('report')) return 'Reportes';
    if (n === 'budget.view' || n === 'budget.manage' || n === 'budgets.view' || n === 'budgets.manage') return 'General';
    return second ? second.charAt(0).toUpperCase() + second.slice(1) : 'General';
  }

  if (moduleKey === 'advisors') {
    if (n.includes('category')) return 'Categorías presupuesto';
    if (n.includes('specialist')) return 'Especialistas';
    if (n.includes('split')) return 'Distribución';
    return second ? second.charAt(0).toUpperCase() + second.slice(1) : 'General';
  }

  if (moduleKey === 'permissions') {
    if (n.includes('roles')) return 'Roles';
    if (n.includes('users')) return 'Usuarios';
    return second ? second.charAt(0).toUpperCase() + second.slice(1) : 'General';
  }

  if (moduleKey === 'users') {
    if (n.includes('manage')) return 'Gestión';
    if (n.includes('view')) return 'Consulta';
    return second ? second.charAt(0).toUpperCase() + second.slice(1) : 'General';
  }

  return second ? second.charAt(0).toUpperCase() + second.slice(1) : 'General';
}

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
  const [activeModule, setActiveModule] = useState<string>('budget');
  const [activeSubgroup, setActiveSubgroup] = useState<string>('all');
  console.log(activeSubgroup)

  const csrfToken =
    typeof document !== 'undefined'
      ? document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? ''
      : '';

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

      const firstModule = getAvailableModules(normalizedPerms)[0] ?? 'budget';
      setActiveModule(firstModule);

      if (normalizedUsers.length) {
        await selectUser(normalizedUsers[0], normalizedUsers);
      }
    } catch (e) {
      console.error(e);
      setToast('Error cargando datos. Revisa la consola.');
      window.setTimeout(() => setToast(null), 3000);
    } finally {
      setLoading(false);
    }
  }

  function getAvailableModules(perms: Permission[]) {
    const keys = new Set<string>();
    perms.forEach((p) => keys.add(getModuleKey(p.name)));
    return MODULES.map((m) => m.key).filter((k) => keys.has(k));
  }

  const groupedByModule = useMemo(() => {
    const g: GroupedPermissions = {};
    permissions.forEach((p) => {
      const module = getModuleKey(p.name);
      if (!g[module]) g[module] = [];
      g[module].push(p);
    });

    Object.keys(g).forEach((m) => g[m].sort((a, b) => a.name.localeCompare(b.name)));
    return g;
  }, [permissions]);

  const moduleKeys = useMemo(() => {
    const present = new Set(Object.keys(groupedByModule));
    return MODULES.map((m) => m.key).filter((k) => present.has(k));
  }, [groupedByModule]);

  const filteredUsers = useMemo(() => {
    return users.filter((u) => {
      const matchSearch = (u.name + ' ' + (u.email || '')).toLowerCase().includes(search.toLowerCase());
      const matchRole = filterRole === 'all' || (u.role || '').toLowerCase() === filterRole.toLowerCase();
      return matchSearch && matchRole;
    });
  }, [users, search, filterRole]);

  const activeModulePermissions = useMemo(() => {
    return groupedByModule[activeModule] ?? [];
  }, [groupedByModule, activeModule]);

  const subgroups = useMemo(() => {
    const result: SubGroupedPermissions = {};
    activeModulePermissions.forEach((p) => {
      const subgroup = getSubgroupLabel(activeModule, p.name);
      if (!result[subgroup]) result[subgroup] = [];
      result[subgroup].push(p);
    });

    Object.keys(result).forEach((k) => result[k].sort((a, b) => a.name.localeCompare(b.name)));
    return result;
  }, [activeModulePermissions, activeModule]);

  const subgroupKeys = useMemo(() => {
    const keys = Object.keys(subgroups);
    const order = ['General', 'Comisiones', 'Cajeros', 'Reportes', 'Categorías presupuesto', 'Especialistas', 'Distribución'];
    return keys.sort((a, b) => {
      const ia = order.indexOf(a);
      const ib = order.indexOf(b);
      if (ia === -1 && ib === -1) return a.localeCompare(b);
      if (ia === -1) return 1;
      if (ib === -1) return -1;
      return ia - ib;
    });
  }, [subgroups]);

  async function selectUser(user: User, userListOverride?: User[]) {
    setSelectedUser(user);
    setUserPermissions(new Set());

    try {
      const res = await fetch(`/api/v1/users/${user.id}/permissions`, { credentials: 'include' });
      if (!res.ok) {
        applyPermissionsFromUserList(user, userListOverride ?? users);
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

      applyPermissionsFromUserList(user, userListOverride ?? users);
    } catch (err) {
      console.error(err);
      applyPermissionsFromUserList(user, userListOverride ?? users);
    }
  }

  function applyPermissionsFromUserList(user: User, userList: User[]) {
    const fromList = userList.find((u) => u.id === user.id) as any;

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

  function toggleSubgroup(subgroup: string) {
    const perms = subgroups[subgroup] ?? [];
    const allSelected = perms.every((p) => userPermissions.has(p.id));

    setUserPermissions((prev) => {
      const next = new Set(prev);
      if (allSelected) {
        perms.forEach((p) => next.delete(p.id));
      } else {
        perms.forEach((p) => next.add(p.id));
      }
      return next;
    });
  }

  function toggleModule(module: string) {
    const modulePerms = groupedByModule[module] ?? [];
    const allSelected = modulePerms.every((p) => userPermissions.has(p.id));

    setUserPermissions((prev) => {
      const next = new Set(prev);
      if (allSelected) {
        modulePerms.forEach((p) => next.delete(p.id));
      } else {
        modulePerms.forEach((p) => next.add(p.id));
      }
      return next;
    });
  }

  function clearPermissions() {
    setUserPermissions(new Set());
  }

  function applyPresetByRoleKey(roleKey: string) {
    const preset = ROLE_PRESETS[roleKey];
    if (!preset) return;

    if (preset.match.includes('*')) {
      setUserPermissions(new Set(permissions.map((p) => p.id)));
      setToast(`Preset "${preset.label}" aplicado.`);
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
    if (!selectedUser) {
      setToast('Selecciona un usuario');
      return;
    }

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
      await selectUser(selectedUser);
    } catch (e) {
      console.error(e);
      setToast('Error guardando permisos');
    } finally {
      setSaving(false);
      window.setTimeout(() => setToast(null), 2200);
    }
  }

  function applyVisibleSelect() {
    if (activeModule === 'all') {
      setUserPermissions(new Set(permissions.map((p) => p.id)));
      return;
    }

    const ids = groupedByModule[activeModule]?.map((p) => p.id) ?? [];
    setUserPermissions((prev) => {
      const next = new Set(prev);
      ids.forEach((id) => next.add(id));
      return next;
    });
  }

  function clearVisibleSelect() {
    if (activeModule === 'all') {
      setUserPermissions(new Set());
      return;
    }

    const ids = groupedByModule[activeModule]?.map((p) => p.id) ?? [];
    setUserPermissions((prev) => {
      const next = new Set(prev);
      ids.forEach((id) => next.delete(id));
      return next;
    });
  }

  return (
    <div className="min-h-screen bg-gray-50 p-6">
      <div className="mx-auto max-w-7xl">
        <div className="mb-6 flex items-center justify-between gap-4">
          <div>
            <h1 className="text-2xl font-bold flex items-center gap-3">
              <Shield className="h-6 w-6" />
              Administración de permisos
            </h1>
            <p className="text-sm text-gray-500">
              Vista modular por áreas: presupuesto, hojas de vida, disciplinas y más.
            </p>
          </div>

          <div className="flex items-center gap-3">
            <div className="relative">
              <input
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                className="w-72 rounded border bg-white py-2 pl-10 pr-3 shadow-sm"
                placeholder="Buscar usuario..."
              />
              <Search className="absolute left-3 top-2.5 h-4 w-4 text-gray-400" />
            </div>

            <select
              className="rounded border bg-white px-3 py-2"
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
          </div>
        </div>

        <div className="grid grid-cols-12 gap-6">
          {/* Usuarios */}
          <aside className="col-span-3 rounded bg-white p-4 shadow">
            <h2 className="mb-3 flex items-center gap-2 font-semibold">
              <Users className="h-4 w-4" /> Usuarios ({filteredUsers.length})
            </h2>

            {loading ? (
              <div className="text-sm text-gray-500">Cargando...</div>
            ) : (
              <ul className="max-h-[72vh] space-y-2 overflow-auto pr-1">
                {filteredUsers.map((u) => (
                  <li
                    key={u.id}
                    className={`flex cursor-pointer items-center justify-between rounded border p-3 transition hover:bg-gray-50 ${
                      selectedUser?.id === u.id ? 'border-emerald-300 bg-emerald-50' : 'bg-white'
                    }`}
                    onClick={() => void selectUser(u)}
                  >
                    <div className="min-w-0">
                      <div className="truncate font-medium">{u.name}</div>
                      <div className="truncate text-xs text-gray-500">{u.email}</div>
                    </div>
                    <div className="ml-2 shrink-0 rounded bg-gray-100 px-2 py-1 text-xs text-gray-600">
                      {u.role || '—'}
                    </div>
                  </li>
                ))}
              </ul>
            )}
          </aside>

          {/* Módulos */}
          <aside className="col-span-3 rounded bg-white p-4 shadow">
            <h2 className="mb-3 flex items-center gap-2 font-semibold">
              <Layers3 className="h-4 w-4" /> Módulos
            </h2>

            <div className="max-h-[72vh] space-y-2 overflow-auto pr-1">
              {moduleKeys.map((module) => {
                const count = groupedByModule[module]?.length ?? 0;
                const active = activeModule === module;

                return (
                  <button
                    key={module}
                    onClick={() => {
                      setActiveModule(module);
                      setActiveSubgroup('all');
                    }}
                    className={`w-full rounded border p-3 text-left transition ${
                      active ? 'border-emerald-300 bg-emerald-50' : 'bg-white hover:bg-gray-50'
                    }`}
                  >
                    <div className="flex items-center justify-between gap-3">
                      <div>
                        <div className="font-medium">{moduleLabel(module)}</div>
                        <div className="text-xs text-gray-500">{getModuleDescription(module)}</div>
                      </div>
                      <div className="text-xs text-gray-500">{count}</div>
                    </div>
                  </button>
                );
              })}
            </div>
          </aside>

          {/* Editor */}
          <section className="col-span-6 rounded bg-white p-6 shadow">
            <div className="mb-4 flex items-start justify-between gap-4">
              <div>
                <h3 className="text-lg font-semibold">
                  Permisos — {moduleLabel(activeModule)}
                </h3>
                <div className="text-sm text-gray-500">
                  {getModuleDescription(activeModule) || 'Selecciona un módulo para ver sus permisos.'}
                </div>
              </div>

              <div className="flex gap-2">
                <button
                  onClick={clearPermissions}
                  className="flex items-center gap-2 rounded border px-3 py-2 text-sm"
                >
                  <X className="h-4 w-4" /> Limpiar
                </button>
                <button
                  onClick={savePermissions}
                  disabled={!selectedUser || saving}
                  className="flex items-center gap-2 rounded bg-emerald-500 px-4 py-2 text-sm text-white disabled:opacity-60"
                >
                  <Save className="h-4 w-4" /> {saving ? 'Guardando...' : 'Guardar cambios'}
                </button>
              </div>
            </div>

            {/* Presets encima del botón guardar, dentro del mismo cuadro */}
            <div className="mb-5 rounded border bg-gray-50 p-4">
              <div className="mb-3 text-sm font-semibold">Presets por rol</div>
              <div className="grid grid-cols-1 gap-2 md:grid-cols-2">
                {Object.entries(ROLE_PRESETS).map(([rk, preset]) => (
                  <button
                    key={rk}
                    onClick={() => applyPresetByRoleKey(rk)}
                    className="rounded border bg-white px-3 py-2 text-left text-sm hover:bg-gray-50"
                  >
                    {preset.label}
                  </button>
                ))}
              </div>
            </div>

            <div className="mb-4 flex flex-wrap gap-2">
              <button
                onClick={applyVisibleSelect}
                className="rounded border px-3 py-2 text-sm hover:bg-gray-50"
              >
                Seleccionar visibles
              </button>
              <button
                onClick={clearVisibleSelect}
                className="rounded border px-3 py-2 text-sm hover:bg-gray-50"
              >
                Limpiar visibles
              </button>
              <button
                onClick={() => toggleModule(activeModule)}
                className="rounded border px-3 py-2 text-sm hover:bg-gray-50"
              >
                Toggle módulo
              </button>
            </div>

            <div className="max-h-[58vh] space-y-4 overflow-auto pr-1">
              {subgroupKeys.length === 0 ? (
                <div className="text-sm text-gray-500">No hay permisos definidos para este módulo.</div>
              ) : (
                subgroupKeys.map((subgroup) => {
                  const perms = subgroups[subgroup] ?? [];
                  const allSelected = perms.length > 0 && perms.every((p) => userPermissions.has(p.id));

                  return (
                    <section key={subgroup} className="rounded border p-4">
                      <div className="mb-3 flex items-center justify-between gap-3">
                        <div>
                          <div className="font-medium">{subgroup}</div>
                          <div className="text-xs text-gray-500">{perms.length} permisos</div>
                        </div>

                        <button
                          onClick={() => toggleSubgroup(subgroup)}
                          className={`flex items-center gap-2 rounded border px-3 py-1.5 text-xs ${
                            allSelected ? 'bg-emerald-50' : 'bg-white'
                          }`}
                        >
                          <CheckSquare className="h-4 w-4" />
                          {allSelected ? 'Quitar grupo' : 'Seleccionar grupo'}
                        </button>
                      </div>

                      <div className="grid grid-cols-1 gap-2 md:grid-cols-2">
                        {perms.map((p) => (
                          <label
                            key={p.id}
                            className="flex cursor-pointer items-center gap-3 rounded border bg-white px-3 py-2 text-sm hover:bg-gray-50"
                          >
                            <input
                              type="checkbox"
                              checked={isChecked(p.id)}
                              onChange={() => togglePermission(p.id)}
                              className="h-4 w-4"
                            />
                            <span className="truncate">
                              {p.name}
                            </span>
                          </label>
                        ))}
                      </div>
                    </section>
                  );
                })
              )}
            </div>
          </section>
        </div>

        {toast && (
          <div className="fixed bottom-6 right-6 rounded bg-black px-4 py-2 text-white shadow">
            {toast}
          </div>
        )}
      </div>
    </div>
  );
}
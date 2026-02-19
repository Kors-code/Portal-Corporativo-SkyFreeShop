import React, { useEffect, useState } from 'react';
import api from '../../../api/axios';

type ManagedUser = {
  id: number;
  name: string;
  email: string;
  seller_code?: string | null;
  role: string; // ahora es string
};

const ALLOWED_ROLES = ['seller','cashier','adminpresupuesto'];

export default function UsersManager() {
  const [loading, setLoading] = useState(true);
  const [users, setUsers] = useState<ManagedUser[]>([]);
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [perPage] = useState(50);

  // modal state
  const [showModal, setShowModal] = useState(false);
  const [editingUser, setEditingUser] = useState<ManagedUser | null>(null);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [submitting, setSubmitting] = useState(false);
  const [message, setMessage] = useState<string | null>(null);

  const [form, setForm] = useState({
    name: '',
    email: '',
    password: '',
    seller_code: '',
    role: ALLOWED_ROLES[0],
  });

  useEffect(() => {
    loadUsers();
    // eslint-disable-next-line
  }, [page]);

  async function loadUsers() {
    setLoading(true);
    try {
      const res = await api.get('/manage/users', {
        params: { search, page, per_page: perPage }
      });
      const data = res.data;
      // adapt to paginated or plain array
      const list = data?.data ? data.data : (Array.isArray(data) ? data : data.items || []);
      // normalize role field if backend returns differently
      const normalized = list.map((u: any) => ({
        id: u.id,
        name: u.name,
        email: u.email,
        seller_code: u.seller_code ?? u.username ?? null,
        role: u.role ?? (u.roles ? (Array.isArray(u.roles) ? u.roles[0] : u.roles) : ''),
      })) as ManagedUser[];
      setUsers(normalized);
    } catch (e) {
      console.error('Error loading users', e);
      setUsers([]);
    } finally {
      setLoading(false);
    }
  }

  function openCreate() {
    setEditingUser(null);
    setForm({ name:'', email:'', password:'', seller_code:'', role: ALLOWED_ROLES[0] });
    setErrors({});
    setMessage(null);
    setShowModal(true);
  }

  function openEdit(u: ManagedUser) {
    setEditingUser(u);
    setForm({
      name: u.name || '',
      email: u.email || '',
      password: '',
      seller_code: u.seller_code || '',
      role: u.role || ALLOWED_ROLES[0],
    });
    setErrors({});
    setMessage(null);
    setShowModal(true);
  }

  function setField<K extends keyof typeof form>(key: K, value: (typeof form)[K]) {
    setForm(prev => ({ ...prev, [key]: value }));
  }

  async function submitForm(e: React.FormEvent) {
    e.preventDefault();
    setErrors({});
    setSubmitting(true);
    setMessage(null);

    try {
      if (editingUser) {
        // update
        const payload: any = {
          name: form.name,
          email: form.email,
          seller_code: form.seller_code,
          role: form.role,
        };
        if (form.password) payload.password = form.password;

        const res = await api.put(`/manage/users/${editingUser.id}`, payload);
        const updated = res.data.user ?? { ...editingUser, ...payload };

        setUsers(prev => prev.map(u => u.id === editingUser.id ? updated : u));
        setMessage('Usuario actualizado correctamente.');
      } else {
        // create
        const payload = {
          name: form.name,
          email: form.email,
          password: form.password,
          seller_code: form.seller_code,
          role: form.role
        };
        const res = await api.post('/manage/users', payload);
        const newUser = res.data.user ?? null;
        if (newUser) {
          setUsers(prev => [newUser, ...prev]);
        }
        setMessage('Usuario creado correctamente.');
      }

      setShowModal(false);
    } catch (err: any) {
      console.error(err);
      // Laravel returns validation errors in err.response.data.errors
      const resp = err?.response?.data;
      if (resp && resp.errors) {
        setErrors(resp.errors);
      } else if (resp && resp.message) {
        setErrors({ general: [String(resp.message)] });
      } else {
        setErrors({ general: ['Error inesperado. Revisa la consola.'] });
      }
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="p-4 max-w-5xl mx-auto">
      <div className="flex items-center justify-between mb-4">
        <h2 className="text-2xl font-semibold">Gestión de usuarios</h2>
        <div className="flex gap-2">
          <button onClick={openCreate} className="px-4 py-2 bg-green-600 text-white rounded shadow">Nuevo usuario</button>
        </div>
      </div>

      {message && (
        <div className="mb-4 px-4 py-2 bg-green-100 text-green-800 rounded">{message}</div>
      )}

      <div className="mb-3 flex gap-2">
        <input
          placeholder="Buscar por nombre, email o seller code"
          value={search}
          onChange={(e)=>setSearch(e.target.value)}
          className="border px-3 py-2 rounded flex-1"
        />
        <button onClick={() => { setPage(1); loadUsers(); }} className="px-4 py-2 bg-gray-200 rounded">Buscar</button>
      </div>

      <div className="bg-white rounded shadow overflow-x-auto">
        <table className="w-full text-sm">
          <thead className="bg-gray-100">
            <tr>
              <th className="p-3 text-left">Nombre</th>
              <th className="p-3 text-left">Email</th>
              <th className="p-3 text-left">Seller code</th>
              <th className="p-3 text-left">Rol</th>
              <th className="p-3 text-right">Acciones</th>
            </tr>
          </thead>
          <tbody>
            {loading ? (
              <tr><td colSpan={5} className="p-4 text-center text-gray-500">Cargando…</td></tr>
            ) : users.length === 0 ? (
              <tr><td colSpan={5} className="p-4 text-center text-gray-500">No hay usuarios</td></tr>
            ) : users.map((u) => (
              <tr key={u.id} className="border-t hover:bg-slate-50">
                <td className="p-3">{u.name}</td>
                <td className="p-3">{u.email}</td>
                <td className="p-3">{u.seller_code || '—'}</td>
                <td className="p-3">
                  <span className={
                    `px-2 py-1 rounded text-xs font-semibold ${
                      u.role === 'seller' ? 'bg-blue-100 text-blue-800' :
                      u.role === 'cashier' ? 'bg-green-100 text-green-800' :
                      u.role === 'adminpresupuesto' ? 'bg-yellow-100 text-yellow-800' :
                      'bg-gray-100 text-gray-700'
                    }`
                  }>{u.role}</span>
                </td>
                <td className="p-3 text-right">
                  <button onClick={()=>openEdit(u)} className="px-3 py-1 bg-blue-600 text-white rounded">Editar</button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {/* Modal */}
      {showModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
          <div className="absolute inset-0 bg-black/40" onClick={()=>setShowModal(false)} />

          <form onSubmit={submitForm} className="relative bg-white rounded-lg shadow-lg max-w-md w-full p-6 z-10">
            <h3 className="text-lg font-bold mb-2">{editingUser ? 'Editar usuario' : 'Crear usuario'}</h3>

            {errors.general && <div className="text-red-700 mb-2">{errors.general.join(' ')}</div>}

            <label className="block text-sm">
              Nombre
              <input
                className="w-full border px-3 py-2 mt-1 rounded"
                value={form.name}
                onChange={(e)=>setField('name', e.target.value)}
                />
              {errors.name && <div className="text-xs text-red-600 mt-1">{errors.name[0]}</div>}
            </label>

            <label className="block text-sm mt-3">
              Email
              <input
                className="w-full border px-3 py-2 mt-1 rounded"
                value={form.email}
                onChange={(e)=>setField('email', e.target.value)}
                />
              {errors.email && <div className="text-xs text-red-600 mt-1">{errors.email[0]}</div>}
            </label>

            <label className="block text-sm mt-3">
              Seller code (opcional)
              <input
                className="w-full border px-3 py-2 mt-1 rounded"
                value={form.seller_code}
                onChange={(e)=>setField('seller_code', e.target.value)}
              />
            </label>

            <label className="block text-sm mt-3">
              Contraseña {editingUser && <span className="text-xs text-gray-500">(dejar vacío para no cambiar)</span>}
              <input
                type="password"
                className="w-full border px-3 py-2 mt-1 rounded"
                value={form.password}
                onChange={(e)=>setField('password', e.target.value)}
              />
              {errors.password && <div className="text-xs text-red-600 mt-1">{errors.password[0]}</div>}
            </label>

            <div className="mt-4">
              <div className="text-sm text-gray-600 mb-2">Rol</div>
              <div className="flex gap-2">
                {ALLOWED_ROLES.map(r => (
                  <label key={r} className={`cursor-pointer px-3 py-2 rounded border ${form.role === r ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white text-gray-700'}`}>
                    <input
                      type="radio"
                      name="role"
                      value={r}
                      checked={form.role === r}
                      onChange={() => setField('role', r)}
                      className="hidden"
                    />
                    <span className="capitalize">{r}</span>
                  </label>
                ))}
              </div>
              {errors.role && <div className="text-xs text-red-600 mt-1">{errors.role[0]}</div>}
            </div>

            <div className="mt-5 flex justify-end gap-2">
              <button type="button" onClick={()=>setShowModal(false)} className="px-4 py-2 rounded bg-gray-100">Cancelar</button>
              <button type="submit" disabled={submitting} className="px-4 py-2 rounded bg-blue-600 text-white">
                {submitting ? (editingUser ? 'Guardando…' : 'Creando…') : (editingUser ? 'Guardar' : 'Crear')}
              </button>
            </div>
          </form>
        </div>
      )}
    </div>
  );
}

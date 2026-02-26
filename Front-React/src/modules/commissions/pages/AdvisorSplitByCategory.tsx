// AdvisorBudgetsPage.tsx
import { useEffect, useMemo, useState } from 'react';
import api from '../../../api/axios';

/* ================= TYPES ================= */
type Budget = { id: number; name: string; start_date?: string; end_date?: string };
type User = { id: number; name: string };
type Row = {
  id?: number | null;
  category_id?: number | null;
  category_classification: string;
  name: string;
  budget_usd: number;
  updated?: boolean;
};

type Specialist = {
  id?: number;
  budget_id: number;
  user_id: number;
  business_line?: string | null;
  category_id?: number | null;
  valid_from?: string;
  valid_to?: string | null;
  note?: string | null;
};

/* ================= COMPONENT ================= */
export default function AdvisorBudgetsPage() {
  const [budgets, setBudgets] = useState<Budget[]>([]);
  const [users, setUsers] = useState<User[]>([]);

  const [budgetId, setBudgetId] = useState<number | null>(null);
  const [userId, setUserId] = useState<number | null>(null);
  const [rows, setRows] = useState<Row[]>([]);
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState<{ type: 'ok' | 'error'; text: string } | null>(null);
console.log(loading)
  // Advisor split UI (Asesor A = Montblanc active, Asesor B = Parbel active)
  const [advisorAPct, setAdvisorAPct] = useState<number>(50);
  const [advisorBPct, setAdvisorBPct] = useState<number>(50);
  const [advisorSplit, setAdvisorSplit] = useState<any>(null);
  const [savingSplit, setSavingSplit] = useState(false);

  // advisor budgets (from commissions/by-seller)
  const [montAdvisorBudgetUsd, setMontAdvisorBudgetUsd] = useState<number>(0);
  const [parbelAdvisorBudgetUsd, setParbelAdvisorBudgetUsd] = useState<number>(0);

  // specialists per line
  const [specialistMont, setSpecialistMont] = useState<Specialist | null>(null);
  const [specialistParbel, setSpecialistParbel] = useState<Specialist | null>(null);
  const [historyMont, setHistoryMont] = useState<Specialist[]>([]);
  const [historyParbel, setHistoryParbel] = useState<Specialist[]>([]);
  const [assigning, setAssigning] = useState(false);

  // UI: active tab/line
  const [activeLine, setActiveLine] = useState<'montblanc' | 'parbel'>('montblanc');

  // split UI Montblanc <-> Parbel (category split)
  const [montPct, setMontPct] = useState<number | null>(40);
  const [parbelPct, setParbelPct] = useState<number | null>(60);
  const [splitCalc, setSplitCalc] = useState<any>(null);
  const [calculatingSplit, setCalculatingSplit] = useState(false);
console.log(setMontPct)
console.log(setParbelPct)
console.log(calculatingSplit)
  const [advisorPct, setAdvisorPct] = useState<number>(0);
  const [advisorPoolUsd, setAdvisorPoolUsd] = useState<number>(0);

  const DEFAULT_MONT = useMemo(() => [
    { category_classification: '19', name: 'Gifts' },
    { category_classification: '14', name: 'Watches' },
    { category_classification: '15', name: 'Jewerly' },
    { category_classification: '16', name: 'Sunglasses' },
    { category_classification: '21', name: 'Electronics' },
  ], []);

  const DEFAULT_PARBEL = useMemo(() => [
    { category_classification: '13', name: 'Skin care' },
    { category_classification: 'fragancias', name: 'Fragancias' },
  ], []);

  const isParbelClassification = (c: string) => {
    const key = String(c).toLowerCase();
    return key === '13' || key === 'fragancias' || key.includes('frag') || key.includes('skin');
  };

  // load budgets & sellers meta
  useEffect(() => {
    (async function loadMeta() {
      try {
        const [bRes, uRes] = await Promise.all([api.get('budgets'), api.get('/users/sellers')]);
        setBudgets(bRes.data ?? []);
        setUsers(uRes.data ?? []);
      } catch (e) {
        console.error(e);
      }
    })();
  }, []);

  // reload category budgets & specialists & saved split
  useEffect(() => {
    if (!budgetId) {
      setRows([]);
      setSpecialistMont(null);
      setSpecialistParbel(null);
      setHistoryMont([]);
      setHistoryParbel([]);
      setAdvisorPct(0);
      setAdvisorPoolUsd(0);
      setAdvisorSplit(null);
      setMontAdvisorBudgetUsd(0);
      setParbelAdvisorBudgetUsd(0);
      return;
    }

    (async function loadAll() {
      setLoading(true);
      try {
        const res = await api.get('advisors/category-budgets', { params: { budget_id: budgetId, user_id: userId || undefined } });

        const payloadRows = Array.isArray(res.data?.rows) ? res.data.rows : [];
        const data: Row[] = payloadRows.map((r: any) => ({
          id: r.saved_id ?? r.id ?? null,
          category_id: r.category_id ?? null,
          category_classification: String(r.category_classification ?? r.category_id ?? ''),
          name: r.name ?? (r.category_classification ?? r.category_id ?? ''),
          budget_usd: Number(r.budget_usd ?? 0),
        }));

        const advisorPool = res.data?.advisor_pool;
        if (advisorPool) {
          setAdvisorPct(Number(advisorPool.pct ?? 0));
          setAdvisorPoolUsd(Number(advisorPool.pool_usd ?? 0));
        }

        if (!data.length) {
          setRows([
            ...DEFAULT_MONT.map(d => ({ id: null, category_id: null, category_classification: d.category_classification, name: d.name, budget_usd: 0 })),
            ...DEFAULT_PARBEL.map(d => ({ id: null, category_id: null, category_classification: d.category_classification, name: d.name, budget_usd: 0 })),
          ]);
        } else {
          const map = new Map<string, Row>();
          data.forEach(r => map.set(r.category_classification, r));
          [...DEFAULT_MONT, ...DEFAULT_PARBEL].forEach(d => {
            if (!map.has(d.category_classification)) {
              map.set(d.category_classification, { id: null, category_id: null, category_classification: d.category_classification, name: d.name, budget_usd: 0 });
            }
          });
          const ordered: Row[] = [];
          [...DEFAULT_MONT, ...DEFAULT_PARBEL].forEach(d => {
            const key = d.category_classification;
            if (map.has(key)) ordered.push(map.get(key)!);
            map.delete(key);
          });
          map.forEach(v => ordered.push(v));
          setRows(ordered);
        }

        // load specialists per business_line
        try {
          const [mRes, pRes] = await Promise.all([
            api.get('advisors/specialists', { params: { budget_id: budgetId, business_line: 'montblanc' } }),
            api.get('advisors/specialists', { params: { budget_id: budgetId, business_line: 'parbel' } }),
          ]);

          const montList: Specialist[] = Array.isArray(mRes.data) ? mRes.data : [];
          const parList: Specialist[] = Array.isArray(pRes.data) ? pRes.data : [];

          const activeMont = montList.find(s => !s.valid_to) ?? montList[0] ?? null;
          const activePar = parList.find(s => !s.valid_to) ?? parList[0] ?? null;

          setSpecialistMont(activeMont);
          setHistoryMont(montList);

          setSpecialistParbel(activePar);
          setHistoryParbel(parList);

        } catch (e) {
          // ignore if endpoint doesn't support business_line filter
        }

        // load saved advisor split (if any)
        try {
          const splitRes = await api.get('advisors/get-split', { params: { budget_id: budgetId } });
          if (splitRes?.data?.budget_id) {
            setAdvisorAPct(Number(splitRes.data.advisor_a_pct ?? 50));
            setAdvisorBPct(Number(splitRes.data.advisor_b_pct ?? 50));
            setAdvisorSplit(splitRes.data);
          } else {
            setAdvisorAPct(50);
            setAdvisorBPct(50);
          }
        } catch (e) {
          // not critical
        }
      } catch (err) {
        console.error('load error', err);
        setMessage({ type: 'error', text: 'Error cargando datos' });
        setTimeout(() => setMessage(null), 2000);
      } finally {
        setLoading(false);
      }
    })();
  }, [budgetId, userId, DEFAULT_MONT, DEFAULT_PARBEL]);

  // helper: load advisor user_budget_usd via commissions/by-seller/:userId
  const loadAdvisorBudget = async (userId?: number | null) => {
    if (!userId || !budgetId) return 0;
    try {
      // endpoint used in DualCommissionAdmin
      const res = await api.get(`commissions/by-seller/${userId}`, { params: { budget_ids: [budgetId] } });
      return Number(res.data?.user_budget_usd ?? 0);
    } catch (e: any) {
      console.warn('could not load advisor budget', userId, e?.response?.status ?? e);
      return 0;
    }
  };

  // whenever active specialists change (or budgetId), refresh their individual budgets
  useEffect(() => {
    let cancelled = false;
    (async () => {
      if (!budgetId) {
        setMontAdvisorBudgetUsd(0);
        setParbelAdvisorBudgetUsd(0);
        return;
      }
      if (specialistMont?.user_id) {
        const v = await loadAdvisorBudget(specialistMont.user_id);
        if (!cancelled) setMontAdvisorBudgetUsd(v);
      } else {
        setMontAdvisorBudgetUsd(0);
      }
      if (specialistParbel?.user_id) {
        const v = await loadAdvisorBudget(specialistParbel.user_id);
        if (!cancelled) setParbelAdvisorBudgetUsd(v);
      } else {
        setParbelAdvisorBudgetUsd(0);
      }
    })();
    return () => { cancelled = true; };
  }, [specialistMont?.user_id, specialistParbel?.user_id, budgetId]);

  const totalAssigned = useMemo(() => rows.reduce((s, r) => s + Number(r.budget_usd || 0), 0), [rows]);

  const updateRow = (index: number, patch: Partial<Row>) => {
    setRows(prev => prev.map((r, i) => i === index ? { ...r, ...patch, updated: true } : r));
  };

  const saveAll = async () => {
    if (!budgetId) { setMessage({ type: 'error', text: 'Selecciona presupuesto' }); setTimeout(()=>setMessage(null),2000); return; }
    setSaving(true);
    try {
      const payload = rows.map(r => ({
        id: r.id ?? null,
        budget_id: budgetId,
        user_id: userId ?? null,
        category_id: r.category_id,
        category_classification: r.category_classification,
        budget_usd: Number(r.budget_usd || 0),
        business_line: isParbelClassification(r.category_classification) ? 'parbel' : 'montblanc',
      }));

      try {
        await api.post('advisors/category-budgets/bulk', payload);
      } catch (bulkErr) {
        console.warn('bulk failed, performing per-row fallback', bulkErr);
        for (const p of payload) {
          try {
            await api.post('advisors/category-budgets', p);
          } catch (rowErr) {
            console.error('failed saving row', p, rowErr);
            setMessage({ type: 'error', text: 'Algunas filas no se guardaron correctamente' });
            setTimeout(()=>setMessage(null),2000);
          }
        }
      }

      await (async () => {
        // reload authoritative rows
        await (async () => { await api.get('advisors/category-budgets', { params: { budget_id: budgetId, user_id: userId || undefined } }); })();
        await reloadCategoryBudgets();
      })();

      setMessage({ type: 'ok', text: 'Guardado exitoso' });
      setTimeout(()=>setMessage(null),2000);
    } catch (e) {
      console.error('saveAll error', e);
      setMessage({ type: 'error', text: 'Error guardando' });
      setTimeout(()=>setMessage(null),2000);
    } finally {
      setSaving(false);
    }
  };

  // reload helper used above
  const reloadCategoryBudgets = async () => {
    if (!budgetId) return;
    try {
      const res = await api.get('advisors/category-budgets', {
        params: { budget_id: budgetId, user_id: userId || undefined }
      });

      const payloadRows = Array.isArray(res.data?.rows) ? res.data.rows : [];

      const data: Row[] = payloadRows.map((r: any) => ({
        id: r.saved_id ?? r.id ?? null,
        category_id: r.category_id ?? null,
        category_classification: String(r.category_classification ?? ''),
        name: r.name ?? r.category_classification ?? '',
        budget_usd: Number(r.budget_usd ?? 0),
      }));

      setRows(prev => {
        if (!data.length) {
          return [
            ...DEFAULT_MONT.map(d => ({ id: null, category_id: null, category_classification: d.category_classification, name: d.name, budget_usd: 0 })),
            ...DEFAULT_PARBEL.map(d => ({ id: null, category_id: null, category_classification: d.category_classification, name: d.name, budget_usd: 0 })),
          ];
        }
        console.log(prev)

        const map = new Map<string, Row>();
        data.forEach(r => map.set(r.category_classification, r));
        [...DEFAULT_MONT, ...DEFAULT_PARBEL].forEach(d => {
          if (!map.has(d.category_classification)) {
            map.set(d.category_classification, { id: null, category_id: null, category_classification: d.category_classification, name: d.name, budget_usd: 0 });
          }
        });
        const ordered: Row[] = [];
        [...DEFAULT_MONT, ...DEFAULT_PARBEL].forEach(d => {
          const key = d.category_classification;
          if (map.has(key)) ordered.push(map.get(key)!);
          map.delete(key);
        });
        map.forEach(v => ordered.push(v));
        return ordered;
      });

      const advisorPool = res.data?.advisor_pool;
      if (advisorPool) {
        setAdvisorPct(Number(advisorPool.pct ?? 0));
        setAdvisorPoolUsd(Number(advisorPool.pool_usd ?? 0));
      }
    } catch (e) {
      console.error('reload error', e);
    }
  };

  const saveOne = async (index: number) => {
    if (!budgetId) { setMessage({ type: 'error', text: 'Selecciona presupuesto' }); setTimeout(()=>setMessage(null),1500); return; }
    const r = rows[index];
    if (!r) return;
    const payload = {
      id: r.id ?? null,
      budget_id: budgetId,
      user_id: userId ?? null,
      category_id: r.category_id,
      category_classification: r.category_classification,
      budget_usd: Number(r.budget_usd || 0),
      business_line: isParbelClassification(r.category_classification) ? 'parbel' : 'montblanc',
    };

    try {
      await api.post('advisors/category-budgets', payload);
      await reloadCategoryBudgets();
      setMessage({ type: 'ok', text: 'Guardado' });
    } catch (e) {
      console.error('saveOne error', e);
      setMessage({ type: 'error', text: 'Error guardando' });
    } finally {
      setTimeout(() => setMessage(null), 1500);
    }
  };

  const assignSpecialistForLine = async (userIdToAssign: number, line: 'montblanc' | 'parbel') => {
    if (!budgetId) { setMessage({ type: 'error', text: 'Selecciona presupuesto' }); setTimeout(()=>setMessage(null),1500); return; }
    setAssigning(true);
    try {
      await api.post('advisors/specialists', { budget_id: budgetId, user_id: userIdToAssign, business_line: line });
      const res = await api.get('advisors/specialists', { params: { budget_id: budgetId, business_line: line } });
      const list: Specialist[] = Array.isArray(res.data) ? res.data : [];
      if (line === 'montblanc') {
        setSpecialistMont(list.find(s => !s.valid_to) ?? list[0] ?? null);
        setHistoryMont(list);
      } else {
        setSpecialistParbel(list.find(s => !s.valid_to) ?? list[0] ?? null);
        setHistoryParbel(list);
      }
      setMessage({ type: 'ok', text: 'Asesor asignado' });
    } catch (e) {
      console.error('assign error', e);
      setMessage({ type: 'error', text: 'Error asignando asesor' });
    } finally {
      setAssigning(false);
      setTimeout(() => setMessage(null), 1800);
    }
  };

  const calculateSplit = async () => {
    if (!budgetId) { setMessage({ type: 'error', text: 'Selecciona presupuesto' }); setTimeout(()=>setMessage(null),1500); return; }
    setCalculatingSplit(true);
    try {
      const res = await api.get('advisors/compute-split', {
        params: {
          budget_id: budgetId,
          user_id: userId ?? undefined,
          mont_pct: montPct,
          parbel_pct: parbelPct
        }
      });
      setSplitCalc(res.data);
      console.log(calculateSplit)

      if (res.data?.montblanc?.category_budgets) {
        const categoryBudgets = res.data.montblanc.category_budgets;
        setRows(prev => {
          const map = new Map<string, Row>();
          prev.forEach(r => map.set(r.category_classification, r));
          Object.entries(categoryBudgets).forEach(([k, v]) => {
            const key = String(k);
            const existing = map.get(key);
            if (existing) {
              map.set(key, { ...existing, budget_usd: Number(v), updated: true });
            } else {
              map.set(key, { id: null, category_id: null, category_classification: key, name: `Category ${key}`, budget_usd: Number(v), updated: true });
            }
          });
          return Array.from(map.values());
        });
      }
    } catch (e) {
      console.error('calc error', e);
      setMessage({ type: 'error', text: 'Error calculando split' });
      setTimeout(()=>setMessage(null),1700);
    } finally {
      setCalculatingSplit(false);
    }
  };

  const applyParbelPrefill = () => {
    if (!splitCalc) { setMessage({ type: 'error', text: 'No hay cálculo para aplicar' }); setTimeout(()=>setMessage(null),1500); return; }
    setRows(prev => {
      const next = [...prev];
      const skinAssigned = splitCalc?.parbel?.skin?.assigned_usd ?? null;
      const fragAssigned = splitCalc?.parbel?.fragancias?.assigned_usd ?? null;

      const idxSkin = next.findIndex(r => String(r.category_classification) === '13' || String(r.name).toLowerCase().includes('skin'));
      if (idxSkin >= 0 && skinAssigned !== null) next[idxSkin] = { ...next[idxSkin], budget_usd: Number(skinAssigned), updated: true };
      else if (skinAssigned !== null) next.push({ id: null, category_id: null, category_classification: '13', name: 'Skin care', budget_usd: Number(skinAssigned), updated: true });

      const idxFrag = next.findIndex(r => String(r.category_classification).toLowerCase() === 'fragancias' || String(r.name).toLowerCase().includes('frag'));
      if (idxFrag >= 0 && fragAssigned !== null) next[idxFrag] = { ...next[idxFrag], budget_usd: Number(fragAssigned), updated: true };
      else if (fragAssigned !== null) next.push({ id: null, category_id: null, category_classification: 'fragancias', name: 'Fragancias (PARBEL)', budget_usd: Number(fragAssigned), updated: true });

      return next;
    });
    console.log(applyParbelPrefill)

    setMessage({ type: 'ok', text: 'Pre-fill Parbel aplicado' });
    setTimeout(()=>setMessage(null),1500);
  };

  const montRows = rows.filter(r => !isParbelClassification(r.category_classification));
  const parbelRows = rows.filter(r => isParbelClassification(r.category_classification));
  const findRowIndexByClassification = (classification: string) => rows.findIndex(r => String(r.category_classification) === String(classification));

  // --- Advisor split helpers (frontend) ---
  const calculateAdvisorSplit = async () => {
    if (!budgetId) return;
    try {
      const res = await api.get('advisors/split-pool', {
        params: {
          budget_id: budgetId,
          advisor_a_pct: advisorAPct,
          advisor_b_pct: advisorBPct,
        }
      });
      setAdvisorSplit(res.data);
    } catch (e) {
      console.error(e);
      setMessage({ type: 'error', text: 'Error calculando split asesores' });
      setTimeout(()=>setMessage(null),1500);
    }
  };

  const saveAdvisorSplit = async () => {
    if (!budgetId) { setMessage({ type: 'error', text: 'Selecciona presupuesto' }); setTimeout(()=>setMessage(null),1500); return; }
    const aId = specialistMont?.user_id ?? null;
    const bId = specialistParbel?.user_id ?? null;
    if (!aId || !bId) {
      setMessage({ type: 'error', text: 'Falta especialista activo en Montblanc o Parbel' });
      setTimeout(()=>setMessage(null),1800);
      return;
    }
    setSavingSplit(true);
    try {
      const payload = {
        budget_id: budgetId,
        advisor_a_id: aId,
        advisor_a_pct: Number(advisorAPct || 0),
        advisor_b_id: bId,
        advisor_b_pct: Number(advisorBPct || 0),
      };
      await api.post('advisors/save-split', payload);
      // reload saved split
      const res = await api.get('advisors/get-split', { params: { budget_id: budgetId } });
      setAdvisorSplit(res.data);
      setMessage({ type: 'ok', text: 'Distribución guardada' });
    } catch (e) {
      console.error('save split error', e);
      setMessage({ type: 'error', text: 'Error guardando distribución' });
    } finally {
      setSavingSplit(false);
      setTimeout(()=>setMessage(null),1800);
    }
  };

  const findUserName = (id?: number | null) => {
    if (!id) return '';
    const u = users.find(x => x.id === id);
    return u?.name ?? `User ${id}`;
  };

  return (
    <div className="p-6 max-w-6xl mx-auto">
      <header className="flex items-start justify-between gap-4 mb-6">
        <div>
          <h1 className="text-2xl font-extrabold">Presupuestos por categoría — Asesor</h1>
          <p className="text-sm text-gray-500">Asigna presupuestos por categoría y especialistas por línea (Montblanc / Parbel)</p>
        </div>

        <div className="flex gap-3 items-center">
          <select className="border rounded px-3 py-2 text-sm" value={budgetId ?? ''} onChange={e => setBudgetId(e.target.value ? Number(e.target.value) : null)}>
            <option value="">Selecciona presupuesto</option>
            {budgets.map(b => <option key={b.id} value={b.id}>{b.name}</option>)}
          </select>

          <select className="border rounded px-3 py-2 text-sm" value={userId ?? ''} onChange={e => setUserId(e.target.value ? Number(e.target.value) : null)}>
            <option value="">(opcional) Filtrar por asesor para ver datos</option>
            {users.map(u => <option key={u.id} value={u.id}>{u.name}</option>)}
          </select>
        </div>
      </header>

      {message && <div className={`mb-4 p-3 rounded ${message.type === 'ok' ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'}`}>{message.text}</div>}

      <section className="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div className="md:col-span-2 space-y-4">
          <div className="bg-white shadow rounded p-4">
            <div className="flex items-center justify-between mb-4">
              <h2 className="font-semibold">Resumen</h2>

              <div className="text-sm text-gray-600">Total asignado: <strong>{totalAssigned.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })} USD</strong></div>

              <div className="bg-white shadow rounded p-4">
                <h3 className="font-semibold">PCT Asesores</h3>
                <div className="mt-3 text-sm text-gray-700 space-y-2">
                  <div><strong>% Participation:</strong> {advisorPct.toFixed(2)}%</div>
                  <div><strong>Presupuesto Asesores (USD):</strong>{" "}{advisorPoolUsd.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</div>
                </div>
              </div>
            </div>

            {/* Montblanc */}
            <div className="mb-4">
              <h3 className="font-medium mb-2">Montblanc</h3>
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                {montRows.map(r => {
                  const idx = findRowIndexByClassification(r.category_classification);
                  return (
                    <div key={`m-${r.category_classification}`} className="border rounded p-3 flex flex-col gap-2 bg-gray-50">
                      <div className="flex justify-between items-start">
                        <div>
                          <div className="text-sm font-medium">{r.name}</div>
                          <div className="text-xxs text-gray-500">code: {r.category_classification}</div>
                        </div>
                        <div>
                          <button onClick={() => idx >= 0 && saveOne(idx)} disabled={!(idx >= 0 && rows[idx].updated)} className={`text-sm px-2 py-1 rounded border ${rows[idx]?.updated ? 'bg-white' : 'bg-gray-50 text-gray-400 cursor-not-allowed'}`}>Guardar</button>
                        </div>
                      </div>

                      <div className="flex gap-2 items-center">
                        <input type="number" min={0} step={0.01} className="w-full border rounded px-2 py-2 text-right" value={r.budget_usd ?? 0} onChange={e => idx >= 0 && updateRow(idx, { budget_usd: Number(e.target.value || 0) })} />
                        <div className="text-sm text-gray-600">USD</div>
                      </div>

                      <div className="flex gap-2 text-xs text-gray-500">
                        <div>id: {r.id ?? '-'}</div>
                        <div className="ml-auto">{r.updated ? <span className="text-indigo-600">Modificado</span> : 'Sin cambios'}</div>
                      </div>
                    </div>
                  );
                })}
              </div>
            </div>

            {/* Parbel */}
            <div>
              <h3 className="font-medium mb-2">Parbel — Skin & Fragancias</h3>
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                {parbelRows.map(r => {
                  const idx = findRowIndexByClassification(r.category_classification);
                  return (
                    <div key={`p-${r.category_classification}`} className="border rounded p-3 flex flex-col gap-2 bg-gray-50">
                      <div className="flex justify-between items-start">
                        <div>
                          <div className="text-sm font-medium">{r.name}</div>
                          <div className="text-xxs text-gray-500">code: {r.category_classification}</div>
                        </div>
                        <div>
                          <button onClick={() => idx >= 0 && saveOne(idx)} disabled={!(idx >= 0 && rows[idx].updated)} className={`text-sm px-2 py-1 rounded border ${rows[idx]?.updated ? 'bg-white' : 'bg-gray-50 text-gray-400 cursor-not-allowed'}`}>Guardar</button>
                        </div>
                      </div>

                      <div className="flex gap-2 items-center">
                        <input type="number" min={0} step={0.01} className="w-full border rounded px-2 py-2 text-right" value={r.budget_usd ?? 0} onChange={e => idx >= 0 && updateRow(idx, { budget_usd: Number(e.target.value || 0) })} />
                        <div className="text-sm text-gray-600">USD</div>
                      </div>

                      <div className="flex gap-2 text-xs text-gray-500">
                        <div>id: {r.id ?? '-'}</div>
                        <div className="ml-auto">{r.updated ? <span className="text-indigo-600">Modificado</span> : 'Sin cambios'}</div>
                      </div>
                    </div>
                  );
                })}
              </div>
            </div>

            <div className="mt-4 flex justify-end gap-2">
              <button onClick={() => setRows(prev => prev.map(r => ({ ...r, budget_usd: 0, updated: true })))} className="px-3 py-2 border rounded text-sm">Reset</button>
              <button onClick={saveAll} disabled={saving || !rows.some(r => r.updated)} className={`px-4 py-2 rounded text-white ${saving || !rows.some(r => r.updated) ? 'bg-gray-400 cursor-not-allowed' : 'bg-indigo-600'}`}>{saving ? 'Guardando...' : 'Guardar todo'}</button>
            </div>
          </div>
        </div>

        <aside className="space-y-4">
          {/* Especialistas por línea */}
          <div className="bg-white shadow rounded p-4">
            <h3 className="font-semibold">Especialistas por línea</h3>
            <div className="mt-3">
              <div className="flex gap-2 mb-3">
                <button onClick={() => setActiveLine('montblanc')} className={`px-3 py-1 rounded ${activeLine === 'montblanc' ? 'bg-indigo-600 text-white' : 'bg-gray-100'}`}>Montblanc</button>
                <button onClick={() => setActiveLine('parbel')} className={`px-3 py-1 rounded ${activeLine === 'parbel' ? 'bg-indigo-600 text-white' : 'bg-gray-100'}`}>Parbel</button>
              </div>

              {activeLine === 'montblanc' ? (
                <div>
                  <div className="text-sm text-gray-600 mb-2">Activo: {specialistMont ? findUserName(specialistMont.user_id) : 'Ninguno'}</div>
                  <select value={specialistMont?.user_id ?? ''} onChange={e => setSpecialistMont(prev => ({ ...(prev ?? { budget_id: budgetId ?? 0, user_id: 0 }), user_id: Number(e.target.value), business_line: 'montblanc' }))} className="border rounded px-2 py-2 w-full mb-2">
                    <option value="">Selecciona asesor Montblanc</option>
                    {users.map(u => <option key={`m-${u.id}`} value={u.id}>{u.name}</option>)}
                  </select>
                  <button onClick={() => specialistMont?.user_id && assignSpecialistForLine(specialistMont.user_id, 'montblanc')} disabled={assigning || !specialistMont?.user_id} className={`w-full px-3 py-2 rounded text-white ${assigning ? 'bg-gray-400' : 'bg-emerald-600'}`}>Asignar Montblanc</button>

                  <div className="mt-3 text-sm text-gray-600">
                    <div className="font-medium">Historial Montblanc</div>
                    <div className="max-h-36 overflow-auto mt-2">
                      {historyMont.length ? historyMont.map(h => (
                        <div key={`${h.user_id}-${h.valid_from ?? ''}`} className="py-1 border-b last:border-b-0">
                          <div>{findUserName(h.user_id)}</div>
                          <div className="text-xxs text-gray-500">{h.valid_from ?? '-'} → {h.valid_to ?? 'activo'}</div>
                        </div>
                      )) : <div className="text-xs text-gray-400">Sin historial</div>}
                    </div>
                  </div>
                </div>
              ) : (
                <div>
                  <div className="text-sm text-gray-600 mb-2">Activo: {specialistParbel ? findUserName(specialistParbel.user_id) : 'Ninguno'}</div>
                  <select value={specialistParbel?.user_id ?? ''} onChange={e => setSpecialistParbel(prev => ({ ...(prev ?? { budget_id: budgetId ?? 0, user_id: 0 }), user_id: Number(e.target.value), business_line: 'parbel' }))} className="border rounded px-2 py-2 w-full mb-2">
                    <option value="">Selecciona asesor Parbel</option>
                    {users.map(u => <option key={`p-${u.id}`} value={u.id}>{u.name}</option>)}
                  </select>
                  <button onClick={() => specialistParbel?.user_id && assignSpecialistForLine(specialistParbel.user_id, 'parbel')} disabled={assigning || !specialistParbel?.user_id} className={`w-full px-3 py-2 rounded text-white ${assigning ? 'bg-gray-400' : 'bg-emerald-600'}`}>Asignar Parbel</button>

                  <div className="mt-3 text-sm text-gray-600">
                    <div className="font-medium">Historial Parbel</div>
                    <div className="max-h-36 overflow-auto mt-2">
                      {historyParbel.length ? historyParbel.map(h => (
                        <div key={`${h.user_id}-${h.valid_from ?? ''}`} className="py-1 border-b last:border-b-0">
                          <div>{findUserName(h.user_id)}</div>
                          <div className="text-xxs text-gray-500">{h.valid_from ?? '-'} → {h.valid_to ?? 'activo'}</div>
                        </div>
                      )) : <div className="text-xs text-gray-400">Sin historial</div>}
                    </div>
                  </div>
                </div>
              )}
            </div>
          </div>

          {/* Split Montblanc <-> Parbel (sidebar) with advisor budgets and % inputs */}
          <div className="bg-white shadow rounded p-4">
            <h3 className="font-semibold">Split Montblanc ↔ Parbel</h3>

            <div className="mt-3 text-sm text-gray-700 space-y-3">
              <div>
                <div className="text-xxs text-gray-500">Asesor A (Montblanc)</div>
                <div className="font-medium">{specialistMont ? findUserName(specialistMont.user_id) : <em className="text-red-500">No hay asesor Montblanc activo</em>}</div>
                <div className="text-xs text-gray-500">PPTO: <strong>{Number(montAdvisorBudgetUsd ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })} USD</strong></div>
              </div>

              <div>
                <div className="text-xxs text-gray-500">Asesor B (Parbel)</div>
                <div className="font-medium">{specialistParbel ? findUserName(specialistParbel.user_id) : <em className="text-red-500">No hay asesor Parbel activo</em>}</div>
                <div className="text-xs text-gray-500">PPTO: <strong>{Number(parbelAdvisorBudgetUsd ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })} USD</strong></div>
              </div>

              <div className="grid grid-cols-2 gap-2">
                <div>
                  <label className="text-xs">Asesor A %</label>
                  <input type="number" min={0} max={100} value={advisorAPct} onChange={e => setAdvisorAPct(Number(e.target.value || 0))} className="w-full rounded px-2 py-1 text-sm border" />
                </div>
                <div>
                  <label className="text-xs">Asesor B %</label>
                  <input type="number" min={0} max={100} value={advisorBPct} onChange={e => setAdvisorBPct(Number(e.target.value || 0))} className="w-full rounded px-2 py-1 text-sm border" />
                </div>
              </div>

              <div className="flex gap-2">
                <button onClick={calculateAdvisorSplit} className="px-3 py-2 rounded text-white bg-indigo-600">Calcular distribución</button>
                <button onClick={saveAdvisorSplit} disabled={savingSplit || !(specialistMont && specialistParbel)} className={`px-3 py-2 rounded text-white ${savingSplit ? 'bg-gray-400' : (specialistMont && specialistParbel ? 'bg-emerald-600' : 'bg-gray-300 cursor-not-allowed')}`}>
                  {savingSplit ? 'Guardando...' : 'Guardar distribución'}
                </button>
              </div>

              {advisorSplit && (
                <div className="text-sm text-gray-700">
                  <div><strong>Pool total:</strong> {Number(advisorSplit.advisor_pool_usd ?? advisorPoolUsd).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })} USD</div>
                  <div>Asesor A (Montblanc): {Number(advisorSplit.advisor_a?.assigned_usd ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })} USD</div>
                  <div>Asesor B (Parbel): {Number(advisorSplit.advisor_b?.assigned_usd ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })} USD</div>
                </div>
              )}

            </div>
          </div>

          <div className="bg-white shadow rounded p-4">
            <h3 className="font-semibold">Ayuda / Notas</h3>
            <p className="text-sm text-gray-500">Montblanc: categories 19,14,15,16,21. Parbel: skin (13) + fragancias.</p>
            <p className="text-xs text-gray-400 mt-2">Endpoints: advisors/category-budgets, advisors/specialists, advisors/save-split, advisors/get-split, commissions/by-seller/:userId (para PPTO asesor).</p>
          </div>
        </aside>
      </section>
    </div>
  );
}
// CommissionLeadersDashboard.tsx
import { useCallback, useEffect, useRef, useState, type JSX } from 'react';
import api from '../../../api/axios'; // tu instancia axios (baseURL '/api')
import type { AxiosResponse, CancelTokenSource } from 'axios';
import * as XLSX from 'xlsx';

/** Tipos */
type Leader = {
  id?: number | null;
  name: string;
  type: 'type_a' | 'type_b';
  commission_pct?: number | null;
  pdvs?: string[]; // ahora opcional; backend ignora pdvs
  notes?: string | null;
  absences?: any[]; // puede ser array de objetos { id, absent_date, reason } cuando viene persistido
};

type BudgetItem = { id: number; name: string; target_amount?: number | null; start_date?: string; end_date?: string };

type CommissionConfig = {
  type_a: { pct_80: number; pct_100: number; pct_120: number };
  type_b: { pct_80: number; pct_100: number; pct_120: number };
};

const DEFAULT_CONFIG: CommissionConfig = {
  type_a: { pct_80: 0.0007, pct_100: 0.0011, pct_120: 0.0013 },
  type_b: { pct_80: 0.00035, pct_100: 0.00056, pct_120: 0.00065 },
};

/** Helpers */
const money = (n?: number | null) =>
  typeof n === 'number' ? n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : '-';

const debounceMs = 600;

/** Componente */
export default function CommissionLeadersDashboard(): JSX.Element {
  // data
  const [budgets, setBudgets] = useState<BudgetItem[]>([]);
  const [leaders, setLeaders] = useState<Leader[]>([]);
  const [config, setConfig] = useState<CommissionConfig>(DEFAULT_CONFIG);

  // UI state
  const [selectedBudget, setSelectedBudget] = useState<number | null>(null);
  const [loading, setLoading] = useState<boolean>(false); // carga initial budgets/leaders
  const [calcLoading, setCalcLoading] = useState<boolean>(false);
  const [error, setError] = useState<string | null>(null);
  const [enriched, setEnriched] = useState<any>(null);
  const [_, setRawCalc] = useState<any>(null);

  // options
  const [usePersistedLeaders] = useState<boolean>(true);
  const [arrivalsPct, setArrivalsPct] = useState<number>(60);
  const [departuresPct, setDeparturesPct] = useState<number>(40);
  const [trmAvg] = useState<number | null>(null);


  // leader modal
  const [showLeaderModal, setShowLeaderModal] = useState(false);
  const [editingLeader, setEditingLeader] = useState<Leader | null>(null);

  // config modal
  const [showConfigModal, setShowConfigModal] = useState(false);

  // split modal
  const [showSplitModal, setShowSplitModal] = useState(false);
  const [savingSplit, setSavingSplit] = useState(false);

  // references for debounce & cancel
  const debounceTimer = useRef<number | null>(null);
  const cancelSource = useRef<CancelTokenSource | null>(null);

  // ---------- Aux helpers for absences CRUD ----------
  async function addAbsence(leaderId:number, date:string, reason?:string) {
    await api.post(`/commission-leaders/${leaderId}/absences`, {
      absent_date: date,
      reason: reason ?? null
    });
  }

  async function deleteAbsence(leaderId:number, absenceId:number) {
    await api.delete(`/commission-leaders/${leaderId}/absences/${absenceId}`);
  }

  async function loadAbsences(leaderId:number) {
    const res = await api.get(`/commission-leaders/${leaderId}/absences`);
    return res.data?.data ?? [];
  }

  // ---------- Inicial cargado ----------
  useEffect(() => {
    (async () => {
      setLoading(true);
      try {
        const [bRes, lRes, cRes] = await Promise.all([
          api.get('/budgets'),
          api.get('/commission-leaders'),
          api.get('/commission-leaders/config').catch(() => ({ data: DEFAULT_CONFIG })),
        ]);

        // budgets: adapt payload
        const budgetsPayload = Array.isArray(bRes.data?.data ?? bRes.data) ? (bRes.data.data ?? bRes.data) : [];
        setBudgets(budgetsPayload as BudgetItem[]);
        if (budgetsPayload.length > 0 && !selectedBudget) setSelectedBudget((budgetsPayload as BudgetItem[])[0].id);

        // leaders (normalize) — forzamos pdvs en UI a ARRIVALS+DEPARTURES para consistencia
        const leadersRaw = lRes.data?.data ?? [];
        const normalized = (leadersRaw || []).map((l: any) => ({
          id: l.id ?? null,
          name: l.name ?? '',
          type: l.type ?? 'type_b',
          commission_pct: l.commission_pct ?? null,
          // backend ahora ignora pdvs; para UI devolvemos la pareja fija
          pdvs: ['DEPARTURES','ARRIVALS'],
          notes: l.notes ?? null,
          absences: l.absences ?? [],
        })) as Leader[];
        setLeaders(normalized);

        // config (if returned raw)
        const cfg = cRes.data ?? DEFAULT_CONFIG;
        setConfig(cfg);
      } catch (e:any) {
        console.error('Inicial load error', e);
        setError('No se pudieron cargar datos iniciales. Revisa la consola.');
      } finally {
        setLoading(false);
      }
    })();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // ---------- Request cancellation helper ----------
  const cancelOngoing = useCallback(() => {
    if (cancelSource.current) {
      try { cancelSource.current.cancel('aborted'); } catch(_) {}
      cancelSource.current = null;
    }
  }, []);

  // ---------- Calcula de forma dinámica con debounce ----------
  const triggerCalculate = useCallback((opts?: { force?: boolean }) => {
    setError(null);

    const doCalculate = async () => {
      setCalcLoading(true);
      cancelOngoing();

      // prepare cancel token
      const source = (api as any).CancelToken ? (api as any).CancelToken.source() : null;
      if (source) cancelSource.current = source;

      try {
        const payload: any = {
          budget_id: selectedBudget,
          persist: usePersistedLeaders,
          arrivals_pct: arrivalsPct,
          departures_pct: departuresPct,
        };

        if (trmAvg && trmAvg > 0) payload.trm_avg = trmAvg;

        // if not using persisted leaders, send the current leaders
        if (!usePersistedLeaders) {
          payload.leaders = (leaders || []).map(l => ({
            id: l.id,
            name: l.name,
            type: l.type,
            commission_pct: l.commission_pct,
            // NO enviamos pdvs: backend ya no necesita este campo
            absences: l.absences ?? [],
          }));
        }

        const axiosOpts = source ? { cancelToken: source.token } : {};
        const res: AxiosResponse = await api.post('/commission-leaders/calculate', payload, axiosOpts);
        setRawCalc(res.data ?? null);

        // si el response trae progress con split, actualizamos (solo si viene distinto)
        try {
          const progress = res.data?.progress;
          if (progress) {
            const a = (typeof progress.arrivals_pct !== 'undefined') ? Number(progress.arrivals_pct) : null;
            const d = (typeof progress.departures_pct !== 'undefined') ? Number(progress.departures_pct) : null;
            if (a !== null && d !== null && (a !== arrivalsPct || d !== departuresPct)) {
              setArrivalsPct(a);
              setDeparturesPct(d);
            }
          }
        } catch (_) { /* ignore */ }

        // enriquecer en el frontend para UI (evita tocar backend)
        const enrichedLocal = enrichResult(res.data, config, arrivalsPct, departuresPct, leaders, trmAvg);
        setEnriched(enrichedLocal);
      } catch (err:any) {
        if ((api as any).isCancel && (api as any).isCancel(err)) {
          // cancelado deliberadamente
          console.debug('Petición cancelada');
        } else {
          console.error('Error calculate', err);
          setError('Error calculando comisiones. Revisa la consola para más detalles.');
        }
      } finally {
        setCalcLoading(false);
        cancelSource.current = null;
      }
    };

    // si piden force: ejecutamos inmediatamente (sin debounce)
    if (opts?.force) {
      if (debounceTimer.current) {
        window.clearTimeout(debounceTimer.current);
        debounceTimer.current = null;
      }
      void doCalculate();
      return;
    }

    // clear previous timer
    if (debounceTimer.current) {
      window.clearTimeout(debounceTimer.current);
      debounceTimer.current = null;
    }

    debounceTimer.current = window.setTimeout(() => {
      void doCalculate();
    }, debounceMs);
  }, [selectedBudget, usePersistedLeaders, arrivalsPct, departuresPct, trmAvg, leaders, config, cancelOngoing]);

  // recalcular automáticamente cuando cambie presupuesto, split, persist flag o TRM
  useEffect(() => {
    if (selectedBudget) {
      triggerCalculate();
    }
    // cleanup: cancel debounce on unmount
    return () => {
      if (debounceTimer.current) { window.clearTimeout(debounceTimer.current); debounceTimer.current = null; }
      cancelOngoing();
    };
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selectedBudget, usePersistedLeaders, arrivalsPct, departuresPct, trmAvg]);

  // Cuando cambia el presupuesto seleccionado, intentamos leer el split guardado (si viene en la API)
  useEffect(() => {
    if (!selectedBudget) return;

    (async () => {
      try {
        const res = await api.get(`/commissions/store-split/${selectedBudget}`);

        if (res.data) {
          setArrivalsPct(Number(res.data.arrivals_pct ?? 60));
          setDeparturesPct(Number(res.data.departures_pct ?? 40));
        }

      } catch (e) {
        console.error("Error loading split", e);
      }
    })();

  }, [selectedBudget]);

  // ---------- Helpers UI / enrichment ----------
  function pdvLabel(raw: string) {
    const k = (raw || '').toUpperCase();
    if (k === 'COLS1') return 'DEPARTURES';
    if (k === 'COLS2') return 'ARRIVALS';
    return k;
  }

  function getPctForTypeAndBracket(cfg: CommissionConfig, type: 'type_a'|'type_b', bracket: string) {
    const map: Record<string,string> = { '80_99': 'pct_80', '100_119': 'pct_100', 'gte120': 'pct_120', 'lt80': 'pct_80' };
    // @ts-ignore
    return Number(cfg[type][map[bracket] ?? 'pct_80'] ?? 0);
  }

  /**
   * Enrich result: devuelve perLeaderResult con pdv_details (commission_usd + commission_cop),
   * storeCommissionTotals por PDV (USD), salesSummary y además trm usada (enriched.trm).
   *
   * trmOverride: valor que viene del input TRM (estado trmAvg). Se usa si backend no devuelve trm.
   */
  function enrichResult(data: any, cfg: CommissionConfig, arrivalsSplitPct: number, departuresSplitPct: number, leadersLocal: Leader[], trmOverride: number | null) {
    const budgetTarget = Number(data?.meta?.budget_amount ?? 0);
    const perStoreRaw = data?.perStore ?? {};
    const backendTrm = (data?.meta && typeof data.meta.trm_avg === 'number') ? Number(data.meta.trm_avg) : null;
    const perStore: Record<string, { total_sales: number }> = {};

    if (Array.isArray(perStoreRaw)) {
      perStoreRaw.forEach((s: any) => {
        const key = (s.pdv ?? s.key ?? '').toString().toUpperCase();
        perStore[key] = { total_sales: Number(s.total_sales ?? 0) };
      });
    } else if (typeof perStoreRaw === 'object') {
      Object.keys(perStoreRaw).forEach(k => {
        const key = k.toUpperCase();
        const v = perStoreRaw[k];
        if (typeof v === 'object') perStore[key] = { total_sales: Number(v.total_sales ?? 0) };
        else perStore[key] = { total_sales: Number(v ?? 0) };
      });
    }

    perStore['COLS1'] = perStore['COLS1'] ?? { total_sales: 0 };
    perStore['COLS2'] = perStore['COLS2'] ?? { total_sales: 0 };
    perStore['DEPARTURES'] = perStore['DEPARTURES'] ?? { total_sales: perStore['COLS1'].total_sales ?? 0 };
    perStore['ARRIVALS'] = perStore['ARRIVALS'] ?? { total_sales: perStore['COLS2'].total_sales ?? 0 };

    const storeTargets: Record<string, number> = {};
    storeTargets['COLS1'] = Math.round((budgetTarget * (departuresSplitPct / 100)) * 100) / 100;
    storeTargets['COLS2'] = Math.round((budgetTarget * (arrivalsSplitPct / 100)) * 100) / 100;
    storeTargets['DEPARTURES'] = storeTargets['COLS1'];
    storeTargets['ARRIVALS'] = storeTargets['COLS2'];

    const storeInfo: Record<string, any> = {};
    ['COLS1','COLS2','DEPARTURES','ARRIVALS'].forEach((pdv) => {
      const sales = Number(perStore[pdv]?.total_sales ?? 0);
      const target = Number(storeTargets[pdv === 'DEPARTURES' || pdv === 'COLS1' ? 'COLS1' : 'COLS2'] ?? 0);
      const pct = target > 0 ? (sales / target) * 100 : 0;
      let bracket: 'lt80' | '80_99' | '100_119' | 'gte120' = 'lt80';
      if (pct >= 120) bracket = 'gte120';
      else if (pct >= 100) bracket = '100_119';
      else if (pct >= 80) bracket = '80_99';
      storeInfo[pdv] = { sales, target, pct: Math.round(pct*100)/100, bracket };
    });

    // Build per-leader results using backend perLeader as source of truth when possible
    const backendPerLeader = Array.isArray(data?.perLeader) ? data.perLeader : [];
    const backendMap = new Map<string, any>();
    backendPerLeader.forEach((b: any) => { backendMap.set(String(b.name ?? b.id ?? ''), b); });

    // prefer backend TRM, otherwise use override (input), otherwise null
    const trmToUse = backendTrm ?? (typeof trmOverride === 'number' && !isNaN(trmOverride) ? Number(trmOverride) : null);

    const perLeaderResult = (leadersLocal || []).map((L) => {
      const backend = backendMap.get(String(L.name)) ?? {};
      const backendDetail = backend.detail_pdvs ?? {};
      const pdvDetails: any[] = [];

      // helper to push a pdv detail with COP computed
      const pushDetail = (pdv: string, total_sales: number, excluded: number, effective: number, bracket: string, cfgPct: number, commissionUsd: number) => {
        const commissionCop = trmToUse ? Math.round(commissionUsd * trmToUse * 100) / 100 : null;
        pdvDetails.push({ pdv, total_sales, excluded, effective, bracket, cfgPct, commission_usd: Math.round(commissionUsd*100)/100, commission_cop: commissionCop });
      };

      if (backendDetail && Object.keys(backendDetail).length) {
        Object.keys(backendDetail).forEach(k => {
          const r = backendDetail[k];
          const pdv = (k || '').toUpperCase();
          const total_sales = Number(r.total_sales ?? 0);
          const excluded = Number(r.excluded_by_absences ?? 0);
          const effective = Math.max(0, total_sales - excluded);
          const bracket = (storeInfo[pdv]?.bracket ?? 'lt80');
          const cfgPct = getPctForTypeAndBracket(cfg, L.type, bracket);
          const commission = effective * cfgPct;
          pushDetail(pdv, total_sales, excluded, effective, bracket, cfgPct, commission);
        });
      } else {
        const pdvList = (L.pdvs && L.pdvs.length) ? L.pdvs : ['COLS1','COLS2'];
        pdvList.forEach(p => {
          const pdv = pdvLabel(p);
          const total_sales = Number(perStore[pdv]?.total_sales ?? 0);
          const excluded = 0;
          const effective = Math.max(0, total_sales - excluded);
          const bracket = storeInfo[pdv]?.bracket ?? 'lt80';
          const cfgPct = getPctForTypeAndBracket(cfg, L.type, bracket);
          const commission = effective * cfgPct;
          pushDetail(pdv, total_sales, excluded, effective, bracket, cfgPct, commission);
        });
      }

      // compute separated totals for departures/arrivals
      const commissionUsdDepartures = pdvDetails.reduce((acc, d) => acc + (d.pdv === 'DEPARTURES' ? Number(d.commission_usd ?? 0) : 0), 0);
      const commissionUsdArrivals = pdvDetails.reduce((acc, d) => acc + (d.pdv === 'ARRIVALS' ? Number(d.commission_usd ?? 0) : 0), 0);
      const commissionCopDepartures = trmToUse ? Math.round(commissionUsdDepartures * trmToUse * 100) / 100 : null;
      const commissionCopArrivals = trmToUse ? Math.round(commissionUsdArrivals * trmToUse * 100) / 100 : null;

      const commissionSum = pdvDetails.reduce((acc, d) => acc + Number(d.commission_usd ?? 0), 0);
      const totalEffective = pdvDetails.reduce((acc, d) => acc + Number(d.effective ?? 0), 0);
      const totalExcluded = pdvDetails.reduce((acc, d) => acc + Number(d.excluded ?? 0), 0);

      const commissionCopTotal = trmToUse ? Math.round(commissionSum * trmToUse * 100) / 100 : ((commissionCopDepartures ?? 0) + (commissionCopArrivals ?? 0));

      return {
        id: L.id,
        name: L.name,
        type: L.type,
        pdvs: ['DEPARTURES','ARRIVALS'],
        total_sales: Math.round(totalEffective*100)/100,
        total_excluded: Math.round(totalExcluded*100)/100,
        commission: Math.round(commissionSum*100)/100,
        commission_usd_departures: Math.round(commissionUsdDepartures*100)/100,
        commission_usd_arrivals: Math.round(commissionUsdArrivals*100)/100,
        commission_cop_departures: commissionCopDepartures,
        commission_cop_arrivals: commissionCopArrivals,
        commission_cop_total: commissionCopTotal,
        pdv_details: pdvDetails,
      };
    });

    const storeCommissionTotals: Record<string, number> = { 'DEPARTURES': 0, 'ARRIVALS': 0, 'COLS1': 0, 'COLS2': 0 };
    perLeaderResult.forEach((pl:any) => {
      (pl.pdv_details || []).forEach((d:any) => {
        const key = (d.pdv ?? '').toUpperCase();
        storeCommissionTotals[key] = (storeCommissionTotals[key] ?? 0) + Number(d.commission_usd ?? 0);
      });
    });

    const departuresPres = storeTargets['COLS1'] ?? 0;
    const arrivalsPres = storeTargets['COLS2'] ?? 0;
    const departuresReal = storeInfo['DEPARTURES']?.sales ?? 0;
    const arrivalsReal = storeInfo['ARRIVALS']?.sales ?? 0;
    const departuresPctAchieved = departuresPres > 0 ? Math.round((departuresReal / departuresPres) * 10000)/100 : null;
    const arrivalsPctAchieved = arrivalsPres > 0 ? Math.round((arrivalsReal / arrivalsPres) * 10000)/100 : null;
    const totalPres = (departuresPres ?? 0) + (arrivalsPres ?? 0);
    const totalReal = (departuresReal ?? 0) + (arrivalsReal ?? 0);
    const totalPct = totalPres > 0 ? Math.round((totalReal / totalPres) * 10000)/100 : null;

    return {
      budgetTarget,
      arrivalsSplitPct,
      departuresSplitPct,
      storeTargets,
      storeInfo,
      perLeaderResult,
      storeCommissionTotals,
      trm: trmToUse,
      salesSummary: {
        DEPARTURES: { presupuesto: departuresPres, real: departuresReal, pct_achieved: departuresPctAchieved },
        ARRIVALS: { presupuesto: arrivalsPres, real: arrivalsReal, pct_achieved: arrivalsPctAchieved },
        TOTAL: { presupuesto: totalPres, real: totalReal, pct_achieved: totalPct },
      },
      raw: data,
    };
  }

  // ---------- CRUD leaders (open modal / save / delete) ----------
  async function openNewLeaderModal() {
    // ya no solicitamos pdvs al crear
    setEditingLeader({ id: null, name: '', type: 'type_b', commission_pct: null, pdvs: [], notes: '', absences: [] });
    setShowLeaderModal(true);
  }

  async function openEditLeaderModal(l: Leader) {
    try {
      let absences = [];
      if (l.id) {
        const res = await api.get(`/commission-leaders/${l.id}/absences`);
        absences = res.data?.data ?? [];
      }

      // Forzamos mantener la UI con ambas tiendas (no editable)
      setEditingLeader({
        ...l,
        pdvs: ['DEPARTURES','ARRIVALS'],
        absences
      });

      setShowLeaderModal(true);
    } catch (e) {
      console.error(e);
    }
  }

  async function saveLeader(leader: Leader | null) {
    if (!leader) return;
    try {
      const payload: any = {
        name: leader.name,
        type: leader.type,
        commission_pct: leader.commission_pct,
        notes: leader.notes,
        // IMPORTANTE: no enviamos 'pdvs'
      };
      if (leader.id) {
        await api.put(`/commission-leaders/${leader.id}`, payload);
      } else {
        await api.post(`/commission-leaders`, payload);
      }
      // reload leaders
      const res = await api.get('/commission-leaders');
      const items = res.data?.data ?? [];
      setLeaders(items.map((l:any) => ({
        id: l.id ?? null,
        name: l.name,
        type: l.type,
        commission_pct: l.commission_pct ?? null,
        pdvs: ['DEPARTURES','ARRIVALS'], // UI fijo
        notes: l.notes ?? null,
        absences: l.absences ?? [],
      })));
      setShowLeaderModal(false);
      setEditingLeader(null);
      // recalculate
      triggerCalculate();
    } catch (e:any) {
      console.error('saveLeader', e);
      alert('Error guardando líder');
    }
  }

  async function deleteLeader(id?: number | null) {
    if (!id) return;
    if (!confirm('Eliminar líder?')) return;
    try {
      await api.delete(`/commission-leaders/${id}`);
      // reload
      const res = await api.get('/commission-leaders');
      setLeaders((res.data?.data ?? []).map((l:any) => ({ id: l.id, name: l.name, type: l.type, commission_pct: l.commission_pct ?? null, pdvs: ['DEPARTURES','ARRIVALS'], notes: l.notes ?? null, absences: l.absences ?? [] })));
      setShowLeaderModal(false);
      setEditingLeader(null);
      triggerCalculate();
    } catch (e) {
      console.error('delete leader', e);
      alert('No se pudo eliminar líder');
    }
  }

  // persist config
  async function saveConfigToServer(cfg: CommissionConfig) {
    try {
      await api.post('/commission-leaders/config', cfg);
      setConfig(cfg);
      setShowConfigModal(false);
      // recalc
      triggerCalculate();
    } catch (e) {
      console.warn('No pudimos persistir config', e);
      setConfig(cfg);
      setShowConfigModal(false);
    }
  }

  // ---------- Save / Read split (arrivals/departures) ----------
  async function saveSplitToServer() {
    if (!selectedBudget) { alert('Selecciona un presupuesto primero'); return; }
    const a = arrivalsPct;
    const d = departuresPct;
    if (isNaN(a) || isNaN(d) || a < 0 || d < 0 || a > 100 || d > 100) { alert('Por favor ingresa porcentajes válidos (0-100).'); return; }
    setSavingSplit(true);
    try {
      // endpoint backend: saveStoreSplit
      await api.post('/commissions/save-store-split', {
        budget_id: selectedBudget,
        arrivals_pct: a,
        departures_pct: d,
      });
      alert('Split guardado correctamente.');
      setShowSplitModal(false);
      // trigger recalc para que backend lo use en el siguiente cálculo
      triggerCalculate({ force: true });
    } catch (e) {
      console.error('Error guardando split', e);
      alert('Error al guardar split (verifica la ruta del backend).');
    } finally {
      setSavingSplit(false);
    }
  }

  // ---------- Export Excel (tabla detallada) ----------
  function exportExcelDetailed() {
    if (!enriched) { alert('Ejecuta cálculo primero'); return; }

    const rows: any[][] = [];
    // Header friendly titles
    rows.push(['Nombre', 'Tipo', 'PDV', 'Ventas (USD)', 'Excluidas (USD)', 'Efectivas (USD)', 'Comisión (USD)', 'Comisión (COP)']);

    enriched.perLeaderResult.forEach((pl:any) => {
      const typeLabel = pl.type === 'type_a' ? 'A' : 'B';
      (pl.pdv_details || []).forEach((d:any) => {
        rows.push([
          pl.name,
          typeLabel,
          d.pdv,
          Number(d.total_sales ?? 0),
          Number(d.excluded ?? 0),
          Number(d.effective ?? 0),
          Number(d.commission_usd ?? 0),
          d.commission_cop !== null ? Number(d.commission_cop) : ''
        ]);
      });

      // TOTAL row per leader (styled as plain row with TOTAL label)
      const totalUsd = Number(pl.commission ?? 0);
      const totalCop = (enriched.trm && !isNaN(enriched.trm)) ? Math.round(totalUsd * enriched.trm * 100) / 100 : (pl.commission_cop_total ?? '');
      rows.push([
        pl.name + ' - TOTAL',
        '',
        '',
        Number(pl.total_sales ?? 0),
        Number(pl.total_excluded ?? 0),
        Number(pl.total_sales ?? 0),
        totalUsd,
        totalCop
      ]);
      // blank separator
      rows.push([]);
    });

    // Totals section
    const totalSales = (enriched.storeInfo?.ARRIVALS?.sales ?? 0) + (enriched.storeInfo?.DEPARTURES?.sales ?? 0);
    const totalExcluded = (enriched.perLeaderResult || []).reduce((acc:any, p:any) => acc + Number(p.total_excluded ?? 0), 0);
    const totalEffective = (enriched.perLeaderResult || []).reduce((acc:any, p:any) => acc + Number(p.total_sales ?? 0), 0);
    const totalUsd = (enriched.perLeaderResult || []).reduce((acc:any, p:any) => acc + Number(p.commission ?? 0), 0);
    const totalCop = (enriched.trm && !isNaN(enriched.trm)) ? Math.round(totalUsd * enriched.trm * 100) / 100 : (enriched.perLeaderResult || []).reduce((acc:any, p:any) => acc + Number(p.commission_cop_total ?? 0), 0);

    rows.push([]);
    rows.push(['TOTALES', '', '', totalSales, totalExcluded, totalEffective, totalUsd, totalCop]);

    const wb = XLSX.utils.book_new();
    const ws = XLSX.utils.aoa_to_sheet(rows);

    // set column widths to look nicer
    ws['!cols'] = [{ wch: 30 }, { wch: 6 }, { wch: 12 }, { wch: 14 }, { wch: 14 }, { wch: 14 }, { wch: 16 }, { wch: 16 }];

    XLSX.utils.book_append_sheet(wb, ws, 'Comisiones por Líder');
    const fileName = `comisiones_lideres_${selectedBudget ?? 'custom'}_${new Date().toISOString().slice(0,19).replace(/[:T]/g,'_')}.xlsx`;
    XLSX.writeFile(wb, fileName);
  }

  // ---------- Export Excel (con presupuesto / ventas por tienda) ----------
  function exportExcelWithBudget() {
    if (!enriched) { alert('Ejecuta cálculo primero'); return; }

    const wb = XLSX.utils.book_new();

    // Sheet 1: resumen presupuesto vs real por tienda
    const summaryRows: any[][] = [];
    summaryRows.push(['Concepto', 'DEPARTURES (USD)', 'ARRIVALS (USD)', 'TOTAL (USD)']);
    summaryRows.push(['Presupuesto', Number(enriched.salesSummary.DEPARTURES.presupuesto ?? 0), Number(enriched.salesSummary.ARRIVALS.presupuesto ?? 0), Number(enriched.salesSummary.TOTAL.presupuesto ?? 0)]);
    summaryRows.push(['Real', Number(enriched.salesSummary.DEPARTURES.real ?? 0), Number(enriched.salesSummary.ARRIVALS.real ?? 0), Number(enriched.salesSummary.TOTAL.real ?? 0)]);
    summaryRows.push(['% Cumplimiento',
      enriched.salesSummary.DEPARTURES.pct_achieved !== null ? `${enriched.salesSummary.DEPARTURES.pct_achieved.toFixed(2)}%` : '-',
      enriched.salesSummary.ARRIVALS.pct_achieved !== null ? `${enriched.salesSummary.ARRIVALS.pct_achieved.toFixed(2)}%` : '-',
      enriched.salesSummary.TOTAL.pct_achieved !== null ? `${enriched.salesSummary.TOTAL.pct_achieved.toFixed(2)}%` : '-'
    ]);
    const wsSummary = XLSX.utils.aoa_to_sheet(summaryRows);
    wsSummary['!cols'] = [{ wch: 28 }, { wch: 16 }, { wch: 16 }, { wch: 16 }];
    XLSX.utils.book_append_sheet(wb, wsSummary, 'Resumen Presupuesto');

    // Sheet 2: ventas y targets por tienda
    const storesRows: any[][] = [];
    storesRows.push(['PDV', 'Target (USD)', 'Ventas (USD)', '% Cumplimiento', ]);
    ['DEPARTURES','ARRIVALS'].forEach(k => {
      const info = enriched.storeInfo[k] ?? {};
      const target = Number(info.target ?? 0);
      const sales = Number(info.sales ?? 0);
      const pct = (typeof info.pct === 'number') ? `${info.pct.toFixed(2)}%` : '-';
      storesRows.push([k, target, sales, pct, ]);
    });
    const wsStores = XLSX.utils.aoa_to_sheet(storesRows);
    wsStores['!cols'] = [{ wch: 18 }, { wch: 16 }, { wch: 16 }, { wch: 14 }, { wch: 12 }];
    XLSX.utils.book_append_sheet(wb, wsStores, 'Ventas por Tienda');

    // Sheet 3: detalle comisiones por lider (reuse export logic but as a sheet)
    const detailRows: any[][] = [];
    detailRows.push(['Nombre', 'Tipo', 'PDV', 'Ventas (USD)', 'Excluidas (USD)', 'Efectivas (USD)', 'Comisión (USD)', 'Comisión (COP)']);
    enriched.perLeaderResult.forEach((pl:any) => {
      const typeLabel = pl.type === 'type_a' ? 'A' : 'B';
      (pl.pdv_details || []).forEach((d:any) => {
        detailRows.push([
          pl.name,
          typeLabel,
          d.pdv,
          Number(d.total_sales ?? 0),
          Number(d.excluded ?? 0),
          Number(d.effective ?? 0),
          Number(d.commission_usd ?? 0),
          d.commission_cop !== null ? Number(d.commission_cop) : ''
        ]);
      });
      const totalUsd = Number(pl.commission ?? 0);
      const totalCop = (enriched.trm && !isNaN(enriched.trm)) ? Math.round(totalUsd * enriched.trm * 100) / 100 : (pl.commission_cop_total ?? '');
      detailRows.push([pl.name + ' - TOTAL', '', '', Number(pl.total_sales ?? 0), Number(pl.total_excluded ?? 0), Number(pl.total_sales ?? 0), totalUsd, totalCop]);
      detailRows.push([]);
    });
    const wsDetail = XLSX.utils.aoa_to_sheet(detailRows);
    wsDetail['!cols'] = [{ wch: 30 }, { wch: 6 }, { wch: 12 }, { wch: 14 }, { wch: 14 }, { wch: 14 }, { wch: 16 }, { wch: 16 }];
    XLSX.utils.book_append_sheet(wb, wsDetail, 'Detalle Comisiones');

    const fileName = `comisiones_con_presupuesto_${selectedBudget ?? 'custom'}_${new Date().toISOString().slice(0,19).replace(/[:T]/g,'_')}.xlsx`;
    XLSX.writeFile(wb, fileName);
  }

  // ---------- Render ----------
  return (
    <div className="p-6 max-w-7xl mx-auto space-y-6">
      <header className="flex items-start justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold">Comisiones — Líderes (por tienda)</h1>
          <p className="text-sm text-gray-500 mt-1">Selecciona un presupuesto y la vista se actualizará automáticamente.</p>
        </div>

        <div className="flex items-center gap-2">
          <button onClick={() => { setShowConfigModal(true); }} className="px-3 py-1 border rounded bg-white">Configuración</button>
          <button onClick={openNewLeaderModal} className="px-3 py-1 rounded bg-emerald-600 text-white">Nuevo líder</button>
          <button onClick={() => { triggerCalculate({ force: true }); }} className={`px-3 py-1 rounded ${calcLoading ? 'bg-gray-400' : 'bg-indigo-600 text-white'}`}>{calcLoading ? 'Calculando...' : 'Forzar recálculo'}</button>
          <button onClick={() => setShowSplitModal(true)} className="px-3 py-1 border rounded bg-yellow-50">Editar % por tienda</button>
        </div>
      </header>

      {/* Config quick row */}
      <section className="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div className="bg-white p-4 rounded shadow space-y-3">
          <label className="text-xs text-gray-500">Presupuesto</label>

          <div className="flex gap-2">
            <select
              className="border rounded px-2 py-1 flex-1"
              value={selectedBudget ?? ''}
              onChange={e => setSelectedBudget(e.target.value ? Number(e.target.value) : null)}
            >
              <option value="">-- Presupuesto manual / sin seleccionar --</option>
              {budgets.map(b => (
                <option key={b.id} value={b.id}>
                  {b.name} — {money(Number(b.target_amount ?? 0))}
                </option>
              ))}
            </select>
          </div>

          {/* SPLIT */}
          <div className="flex items-center justify-between bg-gray-50 border rounded px-3 py-2 text-sm">
            <span className="text-gray-600">Split actual</span>

            <span className="font-semibold">
              ARRIVALS <span className="text-indigo-600">{arrivalsPct}%</span>
              {" / "}
              DEPARTURES <span className="text-indigo-600">{departuresPct}%</span>
            </span>

            <button
              onClick={() => setShowSplitModal(true)}
              className="text-xs px-2 py-1 border rounded bg-white hover:bg-gray-100"
            >
              Editar
            </button>
          </div>

          <div className="text-xs text-gray-400">
            Las opciones de presupuesto ahora se manejan automáticamente desde el selector.
          </div>
        </div>
      </section>

      {/* Error / Loading */}
      {error && <div className="bg-red-50 text-red-700 p-3 rounded">{error}</div>}
      {loading && <div className="text-sm text-gray-500">Cargando datos iniciales…</div>}

      {/* Leaders list */}
      <section className="bg-white p-4 rounded shadow">
        <h3 className="font-semibold mb-3">Líderes</h3>
        {leaders.length === 0 ? <div className="text-sm text-gray-500">No hay líderes guardados.</div> : (
          <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
            {leaders.map(l => (
              <div key={String(l.id ?? l.name)} className="border rounded p-3 hover:shadow cursor-pointer" onClick={() => openEditLeaderModal(l)}>
                <div className="flex items-center justify-between">
                  <div className="font-medium">{l.name}</div>
                  <div className="text-xs text-gray-500">{l.type === 'type_a' ? 'Líder A' : 'Líder B'}</div>
                </div>
                <div className="text-sm text-gray-600 mt-2">Tiendas: ARRIVALS, DEPARTURES</div>
                {l.notes && <div className="text-sm text-gray-500 mt-2">{l.notes}</div>}
              </div>
            ))}
          </div>
        )}
      </section>

      {/* Resultados */}
      {enriched && (
        <section className="bg-white p-4 rounded shadow space-y-4">
          <div className="flex items-center justify-between">
            <div>
              <h3 className="font-semibold">Resultado (enriquecido)</h3>
              <div className="text-sm text-gray-500">Presupuesto: {money(enriched.budgetTarget)}</div>
              <div className="text-sm text-gray-400">TRM usada: {enriched.trm ?? (trmAvg ?? '-')}</div>
            </div>
            <div className="text-sm text-gray-500">Presupuesto: {money(enriched.budgetTarget)}</div>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            {/* ARRIVALS / DEPARTURES cards */}
            {['ARRIVALS','DEPARTURES'].map((k) => {
              const info = enriched.storeInfo[k] ?? {};
              const commUsd = Number(enriched.storeCommissionTotals?.[k] ?? 0);
              const commCop = (enriched.trm && !isNaN(enriched.trm)) ? Math.round(commUsd * enriched.trm * 100) / 100 : null;
              return (
                <div key={k} className="border rounded p-3">
                  <div className="flex items-center justify-between">
                    <div className="font-medium">{k}</div>
                    <div className="text-xs text-gray-400">Franja: {info.bracket}</div>
                  </div>
                  <div className="mt-2 text-sm">Ventas: {money(info.sales)}</div>
                  <div className="text-sm">Target tienda: {money(info.target)}</div>
                  <div className="text-sm">Cumplimiento: {typeof info.pct === 'number' ? info.pct.toFixed(2)+'%' : '-'}</div>
                  <div className="mt-3 font-semibold">Comisión total tienda (USD): {money(commUsd)}</div>
                  <div className="text-sm text-gray-500">Comisión total tienda (COP): {commCop !== null ? money(commCop) : '-'}</div>
                </div>
              );
            })}
          </div>

          {/* Export buttons (justo encima de la tabla) */}
          <div className="flex items-center justify-between">
            <div className="text-lg font-medium">Comisión por líder — desglosado</div>
            <div className="flex gap-2">
              <button onClick={exportExcelDetailed} className="px-3 py-1 bg-indigo-600 text-white rounded">Exportar Excel (tabla detallada)</button>
              <button onClick={exportExcelWithBudget} className="px-3 py-1 border rounded bg-white">Exportar con presupuesto / ventas por tienda</button>
            </div>
          </div>

          {/* Per-leader table */}
          <div>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead className="bg-gray-100">
                  <tr>
                    <th className="p-2 text-left">Nombre</th>
                    <th className="p-2">Tipo</th>
                    <th className="p-2">PDV</th>
                    <th className="p-2">Ventas</th>
                    <th className="p-2">Excluidas</th>
                    <th className="p-2">Efectivas</th>
                    <th className="p-2">Comisión (USD, PDV)</th>
                    <th className="p-2">Comisión (COP)</th>
                  </tr>
                </thead>

                <tbody>
                  {Array.isArray(enriched.perLeaderResult) && enriched.perLeaderResult.flatMap((pl:any) => {
                    // helper: find pdv details
                    const dep = (pl.pdv_details || []).find((d:any) => (d.pdv || '').toUpperCase() === 'DEPARTURES') ?? { total_sales: 0, excluded: 0, effective: 0, commission_usd: 0, commission_cop: null };
                    const arr = (pl.pdv_details || []).find((d:any) => (d.pdv || '').toUpperCase() === 'ARRIVALS') ?? { total_sales: 0, excluded: 0, effective: 0, commission_usd: 0, commission_cop: null };
                    const usdDep = Number(dep.commission_usd ?? 0);
                    const usdArr = Number(arr.commission_usd ?? 0);
                    const usdTotal = Number(pl.commission ?? 0);
                    const copDep = dep.commission_cop ?? null;
                    const copArr = arr.commission_cop ?? null;
                    const copTotal = pl.commission_cop_total ?? (enriched.trm ? Math.round((usdDep + usdArr) * enriched.trm * 100) / 100 : null);

                    return [
                      // Row DEPARTURES
                      <tr key={`${pl.name}-DEPARTURES`} className="border-t">
                        <td rowSpan={3} className="p-2 align-top">{pl.name}</td>
                        <td rowSpan={3} className="p-2 align-top">{pl.type === 'type_a' ? 'A' : 'B'}</td>
                        <td className="p-2">DEPARTURES</td>
                        <td className="p-2">{money(dep.total_sales)}</td>
                        <td className="p-2">{money(dep.excluded ?? 0)}</td>
                        <td className="p-2">{money(dep.effective ?? 0)}</td>
                        <td className="p-2 font-semibold">{money(usdDep)}</td>
                        <td className="p-2">{copDep !== null ? money(copDep) : '-'}</td>
                      </tr>,

                      // Row ARRIVALS
                      <tr key={`${pl.name}-ARRIVALS`} className="border-t">
                        <td className="p-2">ARRIVALS</td>
                        <td className="p-2">{money(arr.total_sales)}</td>
                        <td className="p-2">{money(arr.excluded ?? 0)}</td>
                        <td className="p-2">{money(arr.effective ?? 0)}</td>
                        <td className="p-2 font-semibold">{money(usdArr)}</td>
                        <td className="p-2">{copArr !== null ? money(copArr) : '-'}</td>
                      </tr>,

                      // Row TOTAL
                      <tr key={`${pl.name}-TOTAL`} className="border-t bg-gray-50">
                        <td className="p-2 font-medium">TOTAL</td>
                        <td className="p-2 font-medium">{money(pl.total_sales)}</td>
                        <td className="p-2 font-medium">{money(pl.total_excluded)}</td>
                        <td className="p-2 font-medium">{money(pl.total_sales)}</td>
                        <td className="p-2 font-semibold">{money(usdTotal)}</td>
                        <td className="p-2">{copTotal !== null ? money(copTotal) : '-'}</td>
                      </tr>
                    ];
                  })}
                </tbody>

                <tfoot className="bg-gray-50 font-medium">
                  {(() => {
                    // totals
                    const totalSales = (enriched.storeInfo?.ARRIVALS?.sales ?? 0) + (enriched.storeInfo?.DEPARTURES?.sales ?? 0);
                    const totalExcluded = (enriched.perLeaderResult || []).reduce((acc:any, p:any) => acc + Number(p.total_excluded ?? 0), 0);
                    const totalEffective = (enriched.perLeaderResult || []).reduce((acc:any, p:any) => acc + Number(p.total_sales ?? 0), 0);
                    const totalUsd = (enriched.perLeaderResult || []).reduce((acc:any, p:any) => acc + Number(p.commission ?? 0), 0);
                    const totalCop = (enriched.trm && !isNaN(enriched.trm)) ? Math.round(totalUsd * enriched.trm * 100) / 100 : (enriched.perLeaderResult || []).reduce((acc:any, p:any) => acc + Number(p.commission_cop_total ?? 0), 0);

                    return (
                      <tr>
                        <td className="p-2">Totales</td>
                        <td className="p-2">-</td>
                        <td className="p-2">-</td>
                        <td className="p-2">{money(totalSales)}</td>
                        <td className="p-2">{money(totalExcluded)}</td>
                        <td className="p-2">{money(totalEffective)}</td>
                        <td className="p-2">{money(totalUsd)}</td>
                        <td className="p-2">{!isNaN(totalCop) ? money(totalCop) : '-'}</td>
                      </tr>
                    );
                  })()}
                </tfoot>
              </table>
            </div>
          </div>

          {/* Sales summary cuadro */}
          {enriched.salesSummary && (
            <div>
              <h4 className="font-medium mb-2">VENTAS — Presupuesto vs Real</h4>
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead className="bg-gray-100">
                    <tr>
                      <th className="p-2 text-left">CONCEPTO</th>
                      <th className="p-2 text-right">DEPARTURES</th>
                      <th className="p-2 text-right">ARRIVALS</th>
                      <th className="p-2 text-right">TOTAL</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr className="border-t">
                      <td className="p-2">PRESUPUESTO</td>
                      <td className="p-2 text-right">{money(enriched.salesSummary.DEPARTURES.presupuesto)}</td>
                      <td className="p-2 text-right">{money(enriched.salesSummary.ARRIVALS.presupuesto)}</td>
                      <td className="p-2 text-right">{money(enriched.salesSummary.TOTAL.presupuesto)}</td>
                    </tr>
                    <tr className="border-t bg-emerald-50">
                      <td className="p-2">REAL</td>
                      <td className="p-2 text-right">{money(enriched.salesSummary.DEPARTURES.real)}</td>
                      <td className="p-2 text-right">{money(enriched.salesSummary.ARRIVALS.real)}</td>
                      <td className="p-2 text-right">{money(enriched.salesSummary.TOTAL.real)}</td>
                    </tr>
                    <tr className="border-t">
                      <td className="p-2">% CUMPLIMIENTO</td>
                      <td className={`p-2 text-right font-semibold ${((enriched.salesSummary.DEPARTURES.pct_achieved ?? 0) < 100) ? 'text-red-600' : 'text-green-700'}`}>{enriched.salesSummary.DEPARTURES.pct_achieved !== null ? (enriched.salesSummary.DEPARTURES.pct_achieved.toFixed(2) + '%') : '-'}</td>
                      <td className={`p-2 text-right font-semibold ${((enriched.salesSummary.ARRIVALS.pct_achieved ?? 0) < 100) ? 'text-red-600' : 'text-green-700'}`}>{enriched.salesSummary.ARRIVALS.pct_achieved !== null ? (enriched.salesSummary.ARRIVALS.pct_achieved.toFixed(2) + '%') : '-'}</td>
                      <td className={`p-2 text-right font-semibold ${((enriched.salesSummary.TOTAL.pct_achieved ?? 0) < 100) ? 'text-red-600' : 'text-green-700'}`}>{enriched.salesSummary.TOTAL.pct_achieved !== null ? (enriched.salesSummary.TOTAL.pct_achieved.toFixed(2) + '%') : '-'}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          )}

        </section>
      )}

      {/* ---------- Modales (Leader / Config / Split) ---------- */}
      {showLeaderModal && editingLeader && (
        <div className="fixed inset-0 z-50 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-white max-w-2xl w-full p-4 rounded shadow overflow-auto max-h-[90vh]">
            <div className="flex justify-between items-center mb-3">
              <h4 className="font-semibold">{editingLeader.id ? 'Editar líder' : 'Nuevo líder'}</h4>

              <div className="flex gap-2">
                {editingLeader.id && (
                  <button onClick={() => deleteLeader(editingLeader.id)} className="px-2 py-1 text-red-600 border rounded">Borrar</button>
                )}
                <button onClick={() => { setShowLeaderModal(false); setEditingLeader(null); }} className="px-2 py-1 border rounded">Cerrar</button>
              </div>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
              <div>
                <label className="text-xs">Nombre</label>
                <input value={editingLeader.name} onChange={e => setEditingLeader({ ...editingLeader, name: e.target.value })} className="border px-2 py-1 w-full" />
              </div>

              <div>
                <label className="text-xs">Tipo</label>
                <select value={editingLeader.type} onChange={e => setEditingLeader({ ...editingLeader, type: e.target.value as any })} className="border px-2 py-1 w-full">
                  <option value="type_a">Líder A</option>
                  <option value="type_b">Líder B</option>
                </select>
              </div>

              {/* PDVs removed: leaders no seleccionan COLS1/COLS2 en el front */}
              <div className="col-span-2">
                <label className="text-xs">Notas</label>
                <textarea value={editingLeader.notes ?? ''} onChange={e => setEditingLeader({ ...editingLeader, notes: e.target.value })} className="border px-2 py-1 w-full" />
              </div>

              {/* NOVEDADES / AUSENCIAS */}
              {editingLeader.id && (
                <div className="col-span-2 border-t pt-3 mt-2">
                  <label className="text-xs font-semibold">Novedades (ausencias)</label>

                  <div className="space-y-2 mt-2">
                    {(editingLeader.absences ?? []).map((a: any, idx: number) => (
                      <div key={idx} className="flex items-center justify-between border p-2 rounded">
                        <div>
                          <div className="text-sm">{a.absent_date ?? a.date ?? a}</div>
                          {a.reason && <div className="text-xs text-gray-500">{a.reason}</div>}
                        </div>

                        <button onClick={async () => {
                          await deleteAbsence(editingLeader.id!, a.id ?? a.id_absence ?? 0);
                          const list = await loadAbsences(editingLeader.id!);
                          setEditingLeader({ ...editingLeader, absences: list });
                          triggerCalculate();
                        }} className="text-red-600 text-xs">eliminar</button>
                      </div>
                    ))}
                  </div>

                  {/* AGREGAR AUSENCIA */}
                  <div className="flex gap-2 mt-3">
                    <input type="date" id="absence_date" className="border px-2 py-1" />
                    <input type="text" id="absence_reason" placeholder="motivo (opcional)" className="border px-2 py-1 flex-1" />
                    <button onClick={async () => {
                      const date = (document.getElementById('absence_date') as HTMLInputElement).value;
                      const reason = (document.getElementById('absence_reason') as HTMLInputElement).value;
                      if (!date) return;
                      await addAbsence(editingLeader.id!, date, reason);
                      const list = await loadAbsences(editingLeader.id!);
                      setEditingLeader({ ...editingLeader, absences: list });
                      triggerCalculate();
                    }} className="px-3 py-1 bg-indigo-600 text-white rounded">agregar</button>
                  </div>
                </div>
              )}
            </div>

            <div className="mt-4 flex justify-end gap-2">
              <button onClick={() => { setShowLeaderModal(false); setEditingLeader(null); }} className="px-3 py-1 border rounded">Cancelar</button>
              <button onClick={() => saveLeader(editingLeader)} className="px-3 py-1 bg-emerald-600 text-white rounded">Guardar</button>
            </div>
          </div>
        </div>
      )}

      {showConfigModal && (
        <div className="fixed inset-0 z-50 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-white max-w-2xl w-full p-4 rounded shadow">
            <h4 className="font-semibold mb-3">Escala comisional — Líderes</h4>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div className="border rounded p-3">
                <div className="font-medium mb-2">Líder A (type_a)</div>
                <label className="text-xs block"> {'>='} 80% (decimal)</label>
                <input type="number" step="0.000001" value={config.type_a.pct_80} onChange={e => setConfig({...config, type_a: {...config.type_a, pct_80: Number(e.target.value)}})} className="border px-2 py-1 w-full" />
                <label className="text-xs block mt-2"> {'>='} 100%</label>
                <input type="number" step="0.000001" value={config.type_a.pct_100} onChange={e => setConfig({...config, type_a: {...config.type_a, pct_100: Number(e.target.value)}})} className="border px-2 py-1 w-full" />
                <label className="text-xs block mt-2"> {'>='} 120%</label>
                <input type="number" step="0.000001" value={config.type_a.pct_120} onChange={e => setConfig({...config, type_a: {...config.type_a, pct_120: Number(e.target.value)}})} className="border px-2 py-1 w-full" />
              </div>

              <div className="border rounded p-3">
                <div className="font-medium mb-2">Líder B (type_b)</div>
                <label className="text-xs block"> {'>='} 80% (decimal)</label>
                <input type="number" step="0.000001" value={config.type_b.pct_80} onChange={e => setConfig({...config, type_b: {...config.type_b, pct_80: Number(e.target.value)}})} className="border px-2 py-1 w-full" />
                <label className="text-xs block mt-2"> {'>='} 100%</label>
                <input type="number" step="0.000001" value={config.type_b.pct_100} onChange={e => setConfig({...config, type_b: {...config.type_b, pct_100: Number(e.target.value)}})} className="border px-2 py-1 w-full" />
                <label className="text-xs block mt-2"> {'>='} 120%</label>
                <input type="number" step="0.000001" value={config.type_b.pct_120} onChange={e => setConfig({...config, type_b: {...config.type_b, pct_120: Number(e.target.value)}})} className="border px-2 py-1 w-full" />
              </div>
            </div>

            <div className="mt-4 flex justify-end gap-2">
              <button onClick={() => setShowConfigModal(false)} className="px-3 py-1 border rounded">Cancelar</button>
              <button onClick={() => saveConfigToServer(config)} className="px-3 py-1 bg-indigo-600 text-white rounded">Guardar</button>
            </div>
          </div>
        </div>
      )}

      {/* Split modal */}
      {showSplitModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
          <div className="bg-white rounded-lg max-w-md w-full p-4 shadow-lg">
            <div className="flex items-center justify-between mb-3">
              <h3 className="text-lg font-semibold">Editar % por tienda (persistir por presupuesto)</h3>
              <button className="text-gray-500" onClick={() => setShowSplitModal(false)}>✕</button>
            </div>

            <div className="space-y-3">
              <div className="text-sm text-gray-600">Estos porcentajes se guardan por presupuesto. Selecciona primero el presupuesto (arriba a la izquierda).</div>

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="text-xxs text-gray-500">Arrivals %</label>
                  <input type="number" min={0} max={100} step={0.01} value={arrivalsPct as any} onChange={e => setArrivalsPct(e.target.value === '' ? 0 : Number(e.target.value))} className="w-full border rounded px-3 py-2 text-sm" />
                </div>
                <div>
                  <label className="text-xxs text-gray-500">Departures %</label>
                  <input type="number" min={0} max={100} step={0.01} value={departuresPct as any} onChange={e => setDeparturesPct(e.target.value === '' ? 0 : Number(e.target.value))} className="w-full border rounded px-3 py-2 text-sm" />
                </div>
              </div>

              <div className="flex items-center justify-between gap-3">
                <div className="text-xs text-gray-500">Budget seleccionado: <span className="font-medium">{selectedBudget ?? '—'}</span></div>
                <div className="flex gap-2">
                  <button onClick={() => { setShowSplitModal(false); }} className="px-3 py-2 text-sm bg-gray-100 rounded" disabled={savingSplit}>Cancelar</button>
                  <button onClick={saveSplitToServer} className="px-3 py-2 text-sm bg-indigo-600 text-white rounded" disabled={savingSplit}>{savingSplit ? 'Guardando...' : 'Guardar split'}</button>
                </div>
              </div>
            </div>
          </div>
        </div>
      )}

    </div>
  );
}
import axios from "axios";
import { API } from "../../../api/api";

export type PassengerSummaryResponse = {
  summary: {
    total_pax: number;
    observed_pax: number;
    estimated_pax: number;
    total_flights: number;
    days: number;
    avg_pax_per_day: number;
    avg_pax_per_flight: number;
    composition_base_pax?: number;
    colombian_pax: number | null;
    foreign_pax: number | null;
    colombian_pct: number | null;
    foreign_pct: number | null;
    composition_source?: string;
  };
  composition: PassengerCompositionProfile | null;
  quality: {
    flow_data_type: string;
    flow_source: string;
    composition_status: string;
    veracity_note: string;
  };
  commercial_exposure: {
    period: { year: number; month: number };
    rates: PassengerCommercialExposureRate[];
  };
  by_direction: Array<{ direction: string; flights: number; pax: number }>;
  hourly: Array<{ hour: string; pax: number; flights: number }>;
  daily: Array<{ date: string; pax: number; flights: number }>;
  airlines: Array<{ airline: string; pax: number; flights: number }>;
  routes: Array<{ route: string; direction: string; pax: number; flights: number }>;
  latest_flights: Array<{
    date: string;
    time: string | null;
    direction: string;
    airline: string;
    flight_code: string;
    origin: string | null;
    destination: string | null;
    pax: number;
    data_type: string;
  }>;
};

export type PassengerCompositionProfile = {
  id: number;
  name: string;
  valid_from: string | null;
  valid_to: string | null;
  direction: string | null;
  colombian_pct: number;
  foreign_pct: number;
  source_name: string;
  source_url: string | null;
  method: string;
  confidence_level: "HIGH" | "MEDIUM" | "LOW";
  is_active: boolean;
  notes: string | null;
  created_at: string | null;
};

export type PassengerBatch = {
  id: number;
  filename: string;
  source_type: string;
  observed_scope: string | null;
  source_path: string | null;
  source_url: string | null;
  status: string;
  period_start: string | null;
  period_end: string | null;
  rows_imported: number;
  rows_skipped: number;
  total_pax: number;
  created_at: string | null;
};

export type PassengerSourceFile = {
  id: number;
  provider: string;
  drive_item_id: string;
  name: string;
  extension: string | null;
  size: number;
  web_url: string | null;
  parent_path: string | null;
  source_last_modified_at: string | null;
  discovered_at: string | null;
  downloaded_at: string | null;
  status: string;
  checksum: string | null;
  notes: Record<string, unknown> | null;
};

export type PassengerMonthlyFact = {
  id: number;
  year: number;
  month: number;
  airport_iata: string;
  direction: string;
  fact_type: string;
  source_type: string;
  value: number;
  records_count: number;
  source_name: string | null;
  source_period: string | null;
  confidence_level: "HIGH" | "MEDIUM" | "LOW";
  metadata: Record<string, unknown> | null;
};

export type PassengerCommercialExposureRate = {
  id: number;
  year: number;
  month: number;
  airport_iata: string;
  direction: string;
  commercial_pax: number;
  official_airport_pax: number | null;
  exposure_pct: number | null;
  method: string;
  confidence_level: "HIGH" | "MEDIUM" | "LOW";
  notes: Record<string, unknown> | null;
  calculated_at: string | null;
};

export type PassengerMonthlyEstimate = {
  year: number;
  month: number;
  period: string;
  direction: "arrival" | "departure";
  flights: number;
  base_pax: number;
  commercial_exposed_pax: number;
  colombian_pax: number;
  foreign_pax: number;
  colombian_pct: number | null;
  foreign_pct: number | null;
  high_confidence: number;
  medium_confidence: number;
  low_confidence: number;
};

export type PassengerRecalculateAllResponse = {
  message: string;
  exposure: {
    periods_found: number;
    periods_calculated: number;
    periods_failed: number;
    results: Array<{
      period: {
        year: number;
        month: number;
        records_count: number;
        pax: number;
      };
      rates: PassengerCommercialExposureRate[];
    }>;
    errors: Array<{
      period: {
        year: number;
        month: number;
        records_count: number;
        pax: number;
      };
      error: string;
    }>;
  };
  estimates: {
    model_version: string;
    filters: Record<string, unknown>;
    processed: number;
    created: number;
    updated: number;
    without_composition: number;
    without_exposure: number;
    totals: {
      base_pax: number;
      commercial_exposed_pax: number;
      colombian_pax: number;
      foreign_pax: number;
    };
  };
};

export type PassengerForecastRun = {
  id: number;
  target_year: number;
  target_month: number;
  target_period: string;
  airport_iata: string;
  run_date: string | null;
  cutoff_date: string | null;
  status: string;
  method: string;
  model_version: string;
  actual_pax_to_date: number | null;
  predicted_remaining_pax: number | null;
  predicted_total_pax: number | null;
  predicted_colombian_pct: number | null;
  predicted_foreign_pct: number | null;
  confidence_level: "HIGH" | "MEDIUM" | "LOW";
  input_sources: Record<string, unknown> | null;
  explanation: {
    openai_used?: boolean;
    executive_summary?: string;
    forecast_drivers?: string[];
    risks?: string[];
    accuracy_monitoring_plan?: string[];
    failure_modes?: string[];
    recommendations?: string[];
    openai_error?: string;
  } | null;
  email?: { sent: boolean; to: string; error?: string };
  created_at: string | null;
};

export type PassengerExternalSignal = {
  id: number;
  date_from: string | null;
  date_to: string | null;
  signal_type: string;
  name: string;
  location: string | null;
  source_name: string | null;
  source_url: string | null;
  source_published_at: string | null;
  expected_impact: string;
  impact_direction: string | null;
  impact_score: number;
  verification_status: string;
  notes: string | null;
  metadata: Record<string, unknown> | null;
};

export type PassengerExternalSignalImpact = {
  summary: {
    months_analyzed: number;
    months_with_signals: number;
    months_without_signals: number;
    avg_pax_with_signals: number | null;
    avg_pax_without_signals: number | null;
    difference_pct: number | null;
    note: string;
  };
  months: Array<{
    period: string;
    year: number;
    month: number;
    pax: number;
    flights: number;
    colombian_pax: number;
    foreign_pax: number;
    colombian_pct: number | null;
    foreign_pct: number | null;
    signals_count: number;
    signal_score: number;
    top_signals: PassengerExternalSignal[];
    previous_3_month_avg: number | null;
    lift_vs_previous_3_pct: number | null;
    signal_intensity: "none" | "low" | "medium" | "high";
    analysis: string;
  }>;
};

export type PassengerMigrationMicrodataAudit = {
  source: {
    name: string;
    url: string;
    method: string;
    priority_note: string;
  };
  filters: { year: number | null; month: number | null };
  profiles: PassengerCompositionProfile[];
  monthly_facts: PassengerMonthlyFact[];
  fallback_profiles_present: PassengerCompositionProfile[];
  warning: string | null;
};

export async function getPassengerSummary(params?: Record<string, string>) {
  const { data } = await axios.get<PassengerSummaryResponse>(`${API}/passenger-intelligence/summary`, { params });
  return data;
}

export async function getPassengerBatches() {
  const { data } = await axios.get<PassengerBatch[]>(`${API}/passenger-intelligence/batches`);
  return data;
}

export async function getPassengerSourceFiles() {
  const { data } = await axios.get<PassengerSourceFile[]>(`${API}/passenger-intelligence/source-files`);
  return data;
}

export async function syncPassengerOneDriveFiles() {
  const { data } = await axios.post<{ message: string; files: PassengerSourceFile[] }>(`${API}/passenger-intelligence/onedrive/sync-files`, {
    recursive: true,
  });
  return data;
}

export async function importPassengerOneDriveFile(sourceFileId?: number) {
  const payload = sourceFileId ? { source_file_id: sourceFileId } : { limit: 5 };
  const { data } = await axios.post(`${API}/passenger-intelligence/onedrive/import`, payload);
  return data;
}

export async function getPassengerMonthlyFacts(params?: { year?: number; month?: number }) {
  const { data } = await axios.get<PassengerMonthlyFact[]>(`${API}/passenger-intelligence/monthly-facts`, { params });
  return data;
}

export async function getPassengerMonthlyEstimates(params?: { year?: number; date_from?: string; date_to?: string; direction?: string; data_type?: string }) {
  const { data } = await axios.get<PassengerMonthlyEstimate[]>(`${API}/passenger-intelligence/monthly-estimates`, { params });
  return data;
}

export async function getPassengerForecasts() {
  const { data } = await axios.get<PassengerForecastRun[]>(`${API}/passenger-intelligence/forecasts`);
  return data;
}

export async function getPassengerExternalSignals(params?: { year?: number; date_from?: string; date_to?: string; signal_type?: string }) {
  const { data } = await axios.get<PassengerExternalSignal[]>(`${API}/passenger-intelligence/external-signals`, { params });
  return data;
}

export async function getPassengerExternalSignalImpact(params?: { year?: number }) {
  const { data } = await axios.get<PassengerExternalSignalImpact>(`${API}/passenger-intelligence/external-signals/impact`, { params });
  return data;
}

export async function syncPassengerExternalSignals(payload?: { year?: number }) {
  const { data } = await axios.post<{ years: number[]; created: number; updated: number; total: number }>(
    `${API}/passenger-intelligence/external-signals/sync`,
    payload || {}
  );
  return data;
}

export async function generatePassengerForecast(payload?: {
  target_year?: number;
  target_month?: number;
  run_date?: string;
  send_email?: boolean;
  email?: string;
}) {
  const { data } = await axios.post<{ message: string; forecast: PassengerForecastRun }>(
    `${API}/passenger-intelligence/forecasts/generate`,
    payload || {}
  );
  return data;
}

export async function recalculatePassengerExposure(payload?: { year?: number; month?: number }) {
  const { data } = await axios.post<{ message: string; period: { year: number; month: number }; rates: PassengerCommercialExposureRate[] }>(
    `${API}/passenger-intelligence/exposure/recalculate`,
    payload || {}
  );
  return data;
}

export async function recalculatePassengerAll(payload?: { year?: number; date_from?: string; date_to?: string; direction?: string; data_type?: string }) {
  const { data } = await axios.post<PassengerRecalculateAllResponse>(`${API}/passenger-intelligence/recalculate-all`, payload || {});
  return data;
}

export async function importPassengerExcel(file: File) {
  const fd = new FormData();
  fd.append("file", file);

  const { data } = await axios.post(`${API}/passenger-intelligence/import`, fd, {
    headers: { "Content-Type": "multipart/form-data" },
  });

  return data;
}

export async function importPassengerMigrationMicrodata(file: File, recalculateEstimates = true) {
  const fd = new FormData();
  fd.append("file", file);
  fd.append("recalculate_estimates", recalculateEstimates ? "1" : "0");

  const { data } = await axios.post(`${API}/passenger-intelligence/migration-microdata/import`, fd, {
    headers: { "Content-Type": "multipart/form-data" },
  });

  return data;
}

export async function getPassengerMigrationMicrodataAudit(params?: { year?: number; month?: number }) {
  const { data } = await axios.get<PassengerMigrationMicrodataAudit>(`${API}/passenger-intelligence/migration-microdata/audit`, { params });
  return data;
}

export async function getPassengerProfiles() {
  const { data } = await axios.get<PassengerCompositionProfile[]>(`${API}/passenger-intelligence/profiles`);
  return data;
}

export async function createPassengerProfile(payload: {
  name: string;
  valid_from?: string;
  valid_to?: string;
  direction?: string;
  colombian_pct: number;
  source_name: string;
  source_url?: string;
  method?: string;
  confidence_level?: string;
  notes?: string;
}) {
  const { data } = await axios.post<PassengerCompositionProfile>(`${API}/passenger-intelligence/profiles`, payload);
  return data;
}

export async function syncPassengerOfficialSources(payload?: { year?: number; month?: number }) {
  const { data } = await axios.post(`${API}/passenger-intelligence/sync-official-sources`, payload || {});
  return data;
}

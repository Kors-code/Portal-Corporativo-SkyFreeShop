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
    colombian_pax: number | null;
    foreign_pax: number | null;
    colombian_pct: number | null;
    foreign_pct: number | null;
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

export async function getPassengerMonthlyEstimates(params?: { year?: number; date_from?: string; date_to?: string; direction?: string }) {
  const { data } = await axios.get<PassengerMonthlyEstimate[]>(`${API}/passenger-intelligence/monthly-estimates`, { params });
  return data;
}

export async function recalculatePassengerExposure(payload?: { year?: number; month?: number }) {
  const { data } = await axios.post<{ message: string; period: { year: number; month: number }; rates: PassengerCommercialExposureRate[] }>(
    `${API}/passenger-intelligence/exposure/recalculate`,
    payload || {}
  );
  return data;
}

export async function recalculatePassengerAll(payload?: { year?: number; date_from?: string; date_to?: string; direction?: string }) {
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

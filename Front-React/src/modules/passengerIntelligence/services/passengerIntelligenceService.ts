import axios from "axios";
import { API } from "../../../api/api";

export type PassengerSummaryResponse = {
  summary: {
    total_pax: number;
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
  status: string;
  period_start: string | null;
  period_end: string | null;
  rows_imported: number;
  rows_skipped: number;
  total_pax: number;
  created_at: string | null;
};

export async function getPassengerSummary(params?: Record<string, string>) {
  const { data } = await axios.get<PassengerSummaryResponse>(`${API}/passenger-intelligence/summary`, { params });
  return data;
}

export async function getPassengerBatches() {
  const { data } = await axios.get<PassengerBatch[]>(`${API}/passenger-intelligence/batches`);
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

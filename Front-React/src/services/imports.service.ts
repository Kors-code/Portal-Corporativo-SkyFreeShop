// src/services/imports.service.ts
import axios from 'axios';

import { API } from '../api/api';
import api from '../api/axios';

export type ImportBatch = {
  id: number;
  filename: string;
  checksum?: string;
  status?: string;
  rows?: number | null;
  created_at?: string | null;
  note?: string | null;
  path?: string | null;
};

// Subir archivo (tu endpoint existing /import-sales)
export const importSalesFile = (file: File , storeId: number) => {
  const fd = new FormData();
  fd.append('file', file);
  fd.append('store_id', String(storeId));
  return axios.post(`${API}/import-sales`, fd, {
    headers: { 'Content-Type': 'multipart/form-data' },
  });
};

export const startSalesImport = (file: File, storeId: number) => {
  const fd = new FormData();
  fd.append('file', file);
  fd.append('store_id', String(storeId));
  return axios.post(`${API}/import-sales/start`, fd, {
    headers: { 'Content-Type': 'multipart/form-data' },
  });
};

export const processSalesImportChunk = (payload: {
  path: string;
  batch_id: number;
  store_id: number;
  next_row: number;
  total_rows: number;
  chunk_size?: number;
}) => {
  return axios.post(`${API}/import-sales/chunk`, payload);
};
export interface StoreOption {
  id: number;
  name: string;
  code: string;
  type?: string | null;
}
// Obtener lista de imports (soporta respuesta paginada o arreglo simple)
export const getImports = async (params?: Record<string, any>) => {
  const res = await axios.get(`${API}/imports`, { params });
  // si backend devuelve paginado: res.data.data; si devuelve array: res.data
  return (res.data && res.data.data) ? res.data : res.data;
};
export const getStores = async (): Promise<StoreOption[]> => {
  const response = await api.get("stores");
  return response.data;
};
// Obtener detalle de un import
export const getImport = async (id: number) => {
  const res = await axios.get(`${API}/imports/${id}`);
  return res.data;
};

// Eliminar un import
export const deleteImport = async (id: number) => {
  return axios.delete(`${API}/imports/${id}`);
};

// Eliminación masiva
export const deleteImports = async (ids: number[]) => {
  return axios.post(`${API}/imports/bulk-delete`, { ids });
};

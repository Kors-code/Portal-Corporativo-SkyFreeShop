// src/services/imports.service.ts
import axios from 'axios';

// const API = 'http://127.0.0.1:8000/api/v1';
const API = 'https://skyfreeshopdutyfree.com/api/v1';

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
export const importSalesFile = (file: File) => {
  const fd = new FormData();
  fd.append('file', file);
  return axios.post(`${API}/import-sales`, fd, {
    headers: { 'Content-Type': 'multipart/form-data' },
  });
};

// Obtener lista de imports (soporta respuesta paginada o arreglo simple)
export const getImports = async (params?: Record<string, any>) => {
  const res = await axios.get(`${API}/imports`, { params });
  // si backend devuelve paginado: res.data.data; si devuelve array: res.data
  return (res.data && res.data.data) ? res.data : res.data;
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

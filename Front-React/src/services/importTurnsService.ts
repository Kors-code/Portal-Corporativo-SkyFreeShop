import axios from 'axios';

import { API } from "../api/api";

export type ImportBatch = {
  id: number;
  filename?: string;
  rows?: number;
  status?: string;
  created_at?: string;
};

export const getImports = () => axios.get(`${API}/imports/turns`);
export const getImport = (id:number) => axios.get(`${API}/imports/turns/${id}`);
export const importTurnsFile = (file: File) => {
  const fd = new FormData();
  fd.append('file', file);
  return axios.post(`${API}/import-turns`, fd);
};
export const deleteImport = (id:number) => axios.delete(`${API}/imports/turns/${id}`);
export const deleteImports = (ids:number[]) => axios.delete(`${API}/imports/turns`, { data: { ids }});

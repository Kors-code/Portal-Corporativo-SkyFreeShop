import axios from 'axios';
// const API = 'http://127.0.0.1:8000/api/v1';
const API = 'https://skyfreeshopdutyfree.com/api/v1';

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

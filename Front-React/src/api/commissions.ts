import axios from "axios";
import { API } from "./api";

//  const API = "http://localhost:8000/api"; 
export const getCategories = () => axios.get(`${API}/commission-categories`);
export const createCategory = (data: any) => axios.post(`${API}/commission-categories`, data);
export const updateCategory = (id: number, data: any) => axios.put(`${API}/commission-categories/${id}`, data);
export const deleteCategory = (id: number) => axios.delete(`${API}/commission-categories/${id}`);

export const getRanges = (categoryId: number) =>
    axios.get(`${API}/commission-ranges/${categoryId}`);
export const createRange = (categoryId: number, data: any) =>
    axios.post(`${API}/commission-ranges/${categoryId}`, data);
export const updateRange = (id: number, data: any) =>
    axios.put(`${API}/commission-ranges/${id}`, data);
export const deleteRange = (id: number) =>
    axios.delete(`${API}/commission-ranges/${id}`);

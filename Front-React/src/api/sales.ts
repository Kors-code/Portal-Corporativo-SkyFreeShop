import axios from 'axios';
import { API } from './api';

export const uploadSalesFile = (formData: FormData) => axios.post(`${API}/sales/import`, formData, {
    headers: { 'Content-Type': 'multipart/form-data' }
});

export const getSalesByVendor = (vendorCode: string, date?: string) =>
    axios.get(`${API}/reports/sales-by-vendor`, { params: { vendor_code: vendorCode, date } });

export const getVendorCommission = (month: string) =>
    axios.get(`${API}/reports/vendor-commission`, { params: { month } });

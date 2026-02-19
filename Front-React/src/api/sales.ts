import axios from 'axios';
//  const API = 'http://127.0.0.1:8000/api/v1';
 const API = 'https://skyfreeshopdutyfree.com/api/v1';

export const uploadSalesFile = (formData: FormData) => axios.post(`${API}/sales/import`, formData, {
    headers: { 'Content-Type': 'multipart/form-data' }
});

export const getSalesByVendor = (vendorCode: string, date?: string) =>
    axios.get(`${API}/reports/sales-by-vendor`, { params: { vendor_code: vendorCode, date } });

export const getVendorCommission = (month: string) =>
    axios.get(`${API}/reports/vendor-commission`, { params: { month } });

import api from '../api/axios';

export const getCommissionConfig = async () => {
    const { data } = await api.get('/commissions/config');
    return data;
};

export const upsertCategoryRule = async (payload: any) => {
    const { data } = await api.post('/commissions/config/category-rule', payload);
    return data;
};

export const upsertUserOverride = async (payload: any) => {
    const { data } = await api.post('/commissions/config/user-override', payload);
    return data;
};

export const deleteUserOverride = async (id: number) => {
    const { data } = await api.delete(`/commissions/config/user-override/${id}`);
    return data;
};
export const getUserCommissions = (
    userId: number,
    month: number,
    year: number
) => {
    return api.get(`/commissions/by-user/${userId}`, {
        params: { month, year }
    });
};

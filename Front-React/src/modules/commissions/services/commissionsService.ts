import api from '../../../api/axios';

export const getCommissionsSummary = async () => {
    const { data } = await api.get('/commissions/summary');
    return data;
};

export const getCommissionsByUser = async (userId: number) => {
    const { data } = await api.get(`/commissions/by-user/${userId}`);
    return data;
};

export const deleteCommission = async (id: number) => {
    const { data } = await api.delete(`/commissions/${id}`);
    return data;
};

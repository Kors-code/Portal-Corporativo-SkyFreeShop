export type CategoryRow = {
    category: string;
    sales_count: number;
    ventas: number;
    commission_usd: number;
    pct_of_total: number;
    pct_commission: number;
};

export type CommissionTotals = {
    total_sales: number;
    total_sales_usd: number;
    total_commissions: number;
    commission_pct_of_sales: number;
};

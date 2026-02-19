export interface CategoryWithCommission {
    category_id: number;
    code: string;
    name: string;
    description?: string;
    commission_id?: number | null;

    commission_percentage?: number | null;
    commission_percentage100?: number | null;
    commission_percentage120?: number | null;
    participation_pct?: number;
}

export type Role = { id: number; name: string; };

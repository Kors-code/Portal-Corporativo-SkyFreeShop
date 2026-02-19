export interface Commission {
    id?: number;
    from: number;        // Desde
    to: number;          // Hasta
    percentage: number;  // Comisión %
    created_at?: string;
}

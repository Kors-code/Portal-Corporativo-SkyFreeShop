export type Budget = {
  id?: number;
  name?: string;
  target_amount?: number;
  total_turns?: number | null;
  start_date?: string;
  end_date?: string;
  // compatibilidad
  month?: string;
  amount?: number;
  start?: string;
  end?: string;
};

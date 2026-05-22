type StatBoxProps = {
  label: string;
  value: string | number;
  sub?: string;
  variant?: 'dark' | 'light' | 'success' | 'warning' | 'danger';
};

export default function StatBox({ label, value, sub, variant = 'dark' }: StatBoxProps) {
  const variants: Record<string, string> = {
    dark: 'bg-gray-900 text-white',
    light: 'bg-white text-gray-900',
    success: 'bg-green-600 text-white',
    warning: 'bg-yellow-500 text-white',
    danger: 'bg-red-600 text-white',
  };

  const subColor = variant === 'light' ? 'text-gray-500' : 'text-gray-300';
  const labelColor = variant === 'light' ? 'text-gray-500' : 'text-gray-300';

  return (
    <div className={`min-w-[12rem] flex-shrink-0 rounded-lg p-3 shadow-md ${variants[variant]}`}>
      <div className={`text-xxs ${labelColor}`}>{label}</div>
      <div className="text-xl font-semibold">{value}</div>
      {sub && <div className={`text-xs ${subColor} mt-1`}>{sub}</div>}
    </div>
  );
}

import { Link } from "react-router-dom";

type Props = {
  title: string;
  description: string;
  to: string;
};

export default function DashboardCard({ title, description, to }: Props) {
  return (
    <div className="bg-white rounded-xl shadow-sm p-6 hover:shadow-md transition">
      <h3 className="text-lg font-semibold text-gray-800">
        {title}
      </h3>
      <p className="text-sm text-gray-600 mt-2">
        {description}
      </p>

      <Link
        to={to}
        className="inline-block mt-6 px-4 py-2 bg-[#7A001F] text-white rounded-md text-sm hover:bg-[#5E0018]"
      >
        Ingresar
      </Link>
    </div>
  );
}

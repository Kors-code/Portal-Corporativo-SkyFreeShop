import { Link } from "react-router-dom";

type Item = {
  label: string;
  to: string;
};

export default function PortalSection({
  title,
  items,
}: {
  title: string;
  items: Item[];
}) {
  return (
    <section>
      <h2 className="text-2xl font-semibold text-gray-900 mb-8">
        {title}
      </h2>

      <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
        {items.map((item) => (
          <Link
            key={item.to}
            to={item.to}
            className="
              group bg-white rounded-2xl p-8 border border-gray-200
              transition-all duration-300
              hover:-translate-y-1 hover:shadow-xl
            "
          >
            <div
              className="
                h-1 w-10 bg-[var(--primary)] mb-6
                transition-all duration-300
                group-hover:w-20
              "
            />

            <h3 className="text-lg font-medium text-gray-900">
              {item.label}
            </h3>

            <p className="mt-2 text-sm text-gray-600">
              Acceder al módulo →
            </p>
          </Link>
        ))}
      </div>
    </section>
  );
}

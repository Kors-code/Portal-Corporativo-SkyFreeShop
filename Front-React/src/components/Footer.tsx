export default function Footer() {
  return (
    <footer className="bg-white border-t border-gray-200 mt-12">
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 text-sm text-gray-600 text-center">
        © {new Date().getFullYear()} Sky Free Shop — Todos los derechos reservados
      </div>
    </footer>
  );
}

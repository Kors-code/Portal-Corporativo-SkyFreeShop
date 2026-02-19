export default function Hero() {
  return (
    <section className="relative overflow-hidden rounded-3xl bg-white border border-gray-200 p-16">
      <h1 className="text-4xl font-extrabold text-primary">
        Portal Corporativo
      </h1>

      <p className="mt-6 max-w-2xl text-lg text-gray-600">
        Gestiona ventas, comisiones, presupuestos y usuarios desde un
        entorno moderno, claro y eficiente.
      </p>

      {/* decoraciones */}
      <div className="absolute -top-20 -right-20 w-80 h-80 bg-primary/10 rounded-full blur-3xl" />
      <div className="absolute -bottom-20 -left-20 w-80 h-80 bg-primary/5 rounded-full blur-3xl" />
    </section>
  );
}

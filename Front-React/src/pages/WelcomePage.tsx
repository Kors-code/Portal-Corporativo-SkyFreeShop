import { Link } from "react-router-dom";

export default function WelcomePage() {
  return (
    <div className="pt-24 min-h-screen bg-gradient-to-br from-slate-50 via-white to-slate-100">
      <div className="max-w-6xl mx-auto px-6">

        {/* HERO */}
        <div className="bg-white rounded-3xl shadow-lg border border-gray-200 p-10 md:p-16 flex flex-col lg:flex-row items-center gap-12">
          
          {/* TEXTO */}
          <div className="flex-1">
            <h1 className="text-4xl md:text-5xl font-extrabold text-primary leading-tight">
              Seguimiento de Presupuesto y Comisiones
            </h1>

            <p className="mt-6 text-gray-600 text-lg max-w-xl">
              Bienvenido 👋  
              Desde este portal puedes consultar tu presupuesto asignado y revisar el
              estado de tus comisiones de forma clara y rápida.
            </p>

            <div className="mt-8">
              <Link
                to="/CommisionsUser"
                className="inline-flex items-center gap-3 px-8 py-4 bg-primary text-white text-lg font-semibold rounded-xl shadow-md hover:scale-105 hover:brightness-110 transition-all duration-200"
              >
                📊 Consultar mi presupuesto
              </Link>
            </div>
          </div>

          {/* TARJETA DECORATIVA DERECHA */}
          <div className="flex-1 w-full">
            <div className="bg-gradient-to-br from-primary/10 to-primary/5 border border-primary/20 rounded-2xl p-8 shadow-inner">
              <h3 className="text-xl font-semibold text-primary mb-4">
                ¿Qué podrás ver aquí?
              </h3>

              <ul className="space-y-3 text-gray-600">
                <li>✔️ Presupuesto mensual asignado</li>
                <li>✔️ Avance actual de ventas</li>
                <li>✔️ Porcentaje de cumplimiento</li>
                <li>✔️ Estimación de comisiones</li>
              </ul>

              <div className="mt-6 text-sm text-gray-500">
                Toda tu información actualizada automáticamente.
              </div>
            </div>
          </div>
        </div>

        {/* BLOQUE INFERIOR DECORATIVO */}
        <div className="mt-16 grid grid-cols-1 md:grid-cols-3 gap-6">
          <div className="bg-white p-6 rounded-2xl shadow-sm border border-gray-200 text-center">
            <div className="text-3xl mb-2">💰</div>
            <h4 className="font-semibold text-gray-800">Tus metas</h4>
            <p className="text-sm text-gray-500 mt-2">
              Visualiza tu objetivo de ventas y cuánto te falta para alcanzarlo.
            </p>
          </div>

          <div className="bg-white p-6 rounded-2xl shadow-sm border border-gray-200 text-center">
            <div className="text-3xl mb-2">📈</div>
            <h4 className="font-semibold text-gray-800">Tu progreso</h4>
            <p className="text-sm text-gray-500 mt-2">
              Revisa el avance de tu rendimiento en tiempo real.
            </p>
          </div>

          <div className="bg-white p-6 rounded-2xl shadow-sm border border-gray-200 text-center">
            <div className="text-3xl mb-2">🎯</div>
            <h4 className="font-semibold text-gray-800">Tus comisiones</h4>
            <p className="text-sm text-gray-500 mt-2">
              Consulta cómo tu desempeño impacta tus ganancias.
            </p>
          </div>
        </div>

        <div className="h-24" />
      </div>
    </div>
  );
}

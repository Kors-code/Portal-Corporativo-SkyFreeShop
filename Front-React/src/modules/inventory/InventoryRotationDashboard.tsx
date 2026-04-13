import { useMemo, useState } from "react";
import * as XLSX from "xlsx";
import InventoryRotationTable from "./components/InventoryRotationTable";
import type { InventoryReportRow } from "./types/types";
import {
  buildInventoryReport,
  exportToCSV,
  extractWorkbookRows,
} from "./types/utils";

const InventoryRotationDashboard = () => {
  const [file, setFile] = useState<File | null>(null);
  const [sheetName, setSheetName] = useState<string>("");
  const [sheetNames, setSheetNames] = useState<string[]>([]);
  const [rows, setRows] = useState<InventoryReportRow[]>([]);
  const [loading, setLoading] = useState(false);
  const [search, setSearch] = useState("");

  const filteredRows = useMemo(() => {
    const q = search.trim().toLowerCase();

    if (!q) return rows;

    return rows.filter((row) => {
      return (
        row.codigo.toLowerCase().includes(q) ||
        row.descripcion.toLowerCase().includes(q) ||
        row.clasificacionCompleta.toLowerCase().includes(q) ||
        row.proveedor.toLowerCase().includes(q) ||
        row.supplier.toLowerCase().includes(q) ||
        row.brand.toLowerCase().includes(q)
      );
    });
  }, [rows, search]);

  const handleFileChange = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const selected = e.target.files?.[0] ?? null;
    setFile(selected);
    setRows([]);
    setSheetNames([]);
    setSheetName("");

    if (!selected) return;

    const buffer = await selected.arrayBuffer();
    const workbook = XLSX.read(buffer, { type: "array" });

    setSheetNames(workbook.SheetNames);

    const preferred =
      workbook.SheetNames.find((name) => name === "IND ROT COL") ||
      workbook.SheetNames[0] ||
      "";

    setSheetName(preferred);
  };

  const handleProcess = async () => {
    if (!file) return;

    try {
      setLoading(true);

      const buffer = await file.arrayBuffer();
      const workbook = XLSX.read(buffer, { type: "array" });

      const rawRows = extractWorkbookRows(workbook, sheetName || undefined);
      const calculated = buildInventoryReport(rawRows);

      setRows(calculated);
    } catch (error) {
      console.error(error);
      alert("No se pudo procesar el archivo.");
    } finally {
      setLoading(false);
    }
  };

  return (
    <div style={{ padding: 20 }}>
      <h1 style={{ fontSize: 28, fontWeight: 800, marginBottom: 12 }}>
        Reporte de inventario y rotación
      </h1>

      <div
        style={{
          background: "#fff",
          border: "1px solid #e5e7eb",
          borderRadius: 12,
          padding: 16,
        }}
      >
        <div style={{ display: "flex", gap: 12, flexWrap: "wrap", alignItems: "center" }}>
          <input
            type="file"
            accept=".xlsx,.xls"
            onChange={handleFileChange}
            style={{
              padding: 8,
              border: "1px solid #d1d5db",
              borderRadius: 8,
            }}
          />

          <select
            value={sheetName}
            onChange={(e) => setSheetName(e.target.value)}
            style={{
              padding: 10,
              border: "1px solid #d1d5db",
              borderRadius: 8,
              minWidth: 220,
            }}
          >
            {sheetNames.length === 0 ? (
              <option value="">Selecciona un archivo</option>
            ) : (
              sheetNames.map((name) => (
                <option key={name} value={name}>
                  {name}
                </option>
              ))
            )}
          </select>

          <button
            onClick={handleProcess}
            disabled={!file || loading}
            style={{
              padding: "10px 16px",
              border: "none",
              borderRadius: 8,
              background: loading ? "#93c5fd" : "#2563eb",
              color: "#fff",
              cursor: loading ? "not-allowed" : "pointer",
              fontWeight: 700,
            }}
          >
            {loading ? "Procesando..." : "Procesar archivo"}
          </button>

          <button
            onClick={() => exportToCSV(filteredRows)}
            disabled={filteredRows.length === 0}
            style={{
              padding: "10px 16px",
              border: "1px solid #d1d5db",
              borderRadius: 8,
              background: "#fff",
              color: "#111827",
              cursor: filteredRows.length === 0 ? "not-allowed" : "pointer",
              fontWeight: 700,
            }}
          >
            Exportar CSV
          </button>
        </div>

        <div style={{ marginTop: 12 }}>
          <input
            type="text"
            placeholder="Buscar por código, descripción, proveedor, marca o clasificación..."
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            style={{
              width: "100%",
              maxWidth: 520,
              padding: "10px 12px",
              border: "1px solid #d1d5db",
              borderRadius: 8,
            }}
          />
        </div>
      </div>

      <div style={{ marginTop: 16 }}>
        <InventoryRotationTable rows={filteredRows} />
      </div>
    </div>
  );
};

export default InventoryRotationDashboard;
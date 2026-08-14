import { useEffect, useMemo, useState } from "react";
import { downloadInventory, getInventory } from "../services/inventoryService";
import type { InventoryItem, Store } from "../services/inventoryService";

interface InventoryTableProps {
  refreshKey?: number;
  stores: Store[];
}

const InventoryTable = ({ refreshKey = 0, stores }: InventoryTableProps) => {
  const [data, setData] = useState<InventoryItem[]>([]);
  const [loading, setLoading] = useState<boolean>(true);
  const [downloading, setDownloading] = useState<boolean>(false);
  const [search, setSearch] = useState<string>("");
  const [selectedStoreId, setSelectedStoreId] = useState<number | "">("");

  useEffect(() => {
    void loadData();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [refreshKey, selectedStoreId]);

  const loadData = async () => {
    try {
      setLoading(true);
      const res = await getInventory(
        selectedStoreId ? Number(selectedStoreId) : undefined,
        search
      );
      setData(res);
    } catch (error) {
      console.error("Error cargando inventario:", error);
    } finally {
      setLoading(false);
    }
  };

  const handleDownload = async () => {
    try {
      setDownloading(true);
      const blob = await downloadInventory(
        selectedStoreId ? Number(selectedStoreId) : undefined,
        search,
        undefined
      );
      const selectedStore = stores.find((store) => store.id === selectedStoreId);
      const storeName = selectedStore?.code || selectedStore?.name || "todas-tiendas";
      const filename = `inventario-${slugify(storeName)}-${new Date().toISOString().slice(0, 10)}.xlsx`;
      const url = window.URL.createObjectURL(blob);
      const link = document.createElement("a");
      link.href = url;
      link.download = filename;
      document.body.appendChild(link);
      link.click();
      link.remove();
      window.URL.revokeObjectURL(url);
    } catch (error) {
      console.error("Error descargando inventario:", error);
      alert("No se pudo descargar el inventario.");
    } finally {
      setDownloading(false);
    }
  };

  const filteredData = useMemo(() => {
    const q = search.trim().toLowerCase();

    if (!q) return data;

    return data.filter((item) => {
      const code = item.product_code?.toLowerCase() ?? "";
      const description = item.description?.toLowerCase() ?? "";
      const supplier = item.supplier?.toLowerCase() ?? "";
      const brand = item.brand?.toLowerCase() ?? "";
      const classification = item.classification_desc?.toLowerCase() ?? "";

      return (
        code.includes(q) ||
        description.includes(q) ||
        supplier.includes(q) ||
        brand.includes(q) ||
        classification.includes(q)
      );
    });
  }, [data, search]);

  return (
    <div style={{ marginTop: "20px" }}>
      <div style={{ display: "flex", gap: "12px", flexWrap: "wrap", marginBottom: "12px" }}>
        <select
          value={selectedStoreId}
          onChange={(e) => setSelectedStoreId(e.target.value ? Number(e.target.value) : "")}
          style={{
            padding: "10px 12px",
            border: "1px solid #d1d5db",
            borderRadius: "8px",
            background: "#fff",
            minWidth: "220px",
          }}
        >
          <option value="">Todas las tiendas</option>
          {stores.map((store) => (
            <option key={store.id} value={store.id}>
              {store.name}
            </option>
          ))}
        </select>

        <input
          type="text"
          placeholder="Buscar por SKU, descripción, marca o proveedor..."
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          style={{
            padding: "10px 12px",
            width: "100%",
            maxWidth: "520px",
            border: "1px solid #d1d5db",
            borderRadius: "8px",
            outline: "none",
          }}
        />

        <button
          onClick={loadData}
          style={{
            padding: "10px 16px",
            background: "#111827",
            color: "white",
            border: "none",
            borderRadius: "8px",
            cursor: "pointer",
            fontWeight: 600,
          }}
        >
          Filtrar
        </button>

        <button
          onClick={handleDownload}
          disabled={downloading}
          style={{
            padding: "10px 16px",
            background: downloading ? "#94a3b8" : "#047857",
            color: "white",
            border: "none",
            borderRadius: "8px",
            cursor: downloading ? "not-allowed" : "pointer",
            fontWeight: 600,
          }}
        >
          {downloading ? "Descargando..." : selectedStoreId ? "Descargar tienda" : "Descargar todas"}
        </button>
      </div>

      {loading ? (
        <div style={{ padding: "16px" }}>Cargando inventario...</div>
      ) : (
        <div style={{ overflowX: "auto", border: "1px solid #e5e7eb", borderRadius: "10px" }}>
          <table style={{ width: "100%", borderCollapse: "collapse", minWidth: "1800px" }}>
            <thead>
              <tr style={{ background: "#f3f4f6" }}>
                <th style={th}>CODIGO</th>
                <th style={th}>DESCRIPCION</th>
                <th style={th}>CLASIFICACION</th>
                <th style={th}>EXISTENCIA FINAL</th>
                <th style={th}>F/C</th>
                <th style={th}>COSTO UNITARIO</th>
                <th style={th}>TOTAL INV. FINAL</th>
                <th style={th}>COSTO USD</th>
                <th style={th}>VALOR FINAL USD</th>
                <th style={th}>COGS</th>
                <th style={th}>MAXIMO MES</th>
                <th style={th}>MAXIMO DIA</th>
                <th style={th}>IND ROT STOCK</th>
                <th style={th}>IND ROT PROMEDIO</th>
                <th style={th}>DIAS EN EXISTENCIA</th>
                <th style={th}>FECHA ULT. VENTA</th>
                <th style={th}>DIAS SIN VENTAS</th>
                <th style={th}>PROVEEDOR</th>
                <th style={th}>BRAND</th>
                <th style={th}>RETAIL</th>
                <th style={th}>% COSTO</th>
                <th style={th}>% MARGEN</th>
              </tr>
            </thead>

            <tbody>
              {filteredData.length === 0 ? (
                <tr>
                  <td style={td} colSpan={22}>
                    No hay resultados
                  </td>
                </tr>
              ) : (
                filteredData.map((item) => (
                  <tr key={item.product_id}>
                    <td style={td}>{item.product_code}</td>
                    <td style={td}>{item.description}</td>
                    <td style={td}>{item.classification_desc ?? "-"}</td>
                    <td style={td}>{formatNumber(item.existencia_final ?? item.stock_actual)}</td>
                    <td style={td}>{formatNumber(item.factor_caja)}</td>
                    <td style={td}>{formatMoney(item.cost_unitario)}</td>
                    <td style={td}>{formatMoney(item.total_inv_final)}</td>
                    <td style={td}>{formatMoney(item.cost_unitario_usd)}</td>
                    <td style={td}>{formatMoney(item.valor_final_usd)}</td>
                    <td style={td}>{formatMoney(item.cogs)}</td>
                    <td style={td}>{formatNumber(item.maximo_mes)}</td>
                    <td style={td}>{formatNumber(item.maximo_dia)}</td>
                    <td style={td}>{formatNumber(item.ind_rot_stock)}</td>
                    <td style={td}>{formatNumber(item.ind_rot_promedio)}</td>
                    <td style={td}>{formatNumber(item.days_in_stock ?? item.dias_en_existencia)}</td>
                    <td style={td}>{item.last_sale_date ?? item.fecha_ultima_venta ?? "-"}</td>
                    <td style={td}>{formatNumber(item.without_sales_days ?? item.dias_sin_ventas)}</td>
                    <td style={td}>{item.supplier ?? "-"}</td>
                    <td style={td}>{item.brand ?? "-"}</td>
                    <td style={td}>{formatMoney(item.retail)}</td>
                    <td style={td}>{formatNumber(item.pct_costo)}%</td>
                    <td style={td}>{formatNumber(item.pct_margen)}%</td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
};

const formatNumber = (value: number | null | undefined): string => {
  if (value === null || value === undefined || Number.isNaN(Number(value))) return "-";
  return new Intl.NumberFormat("es-CO", {
    maximumFractionDigits: 2,
  }).format(Number(value));
};

const formatMoney = (value: number | null | undefined): string => {
  if (value === null || value === undefined || Number.isNaN(Number(value))) return "-";
  return new Intl.NumberFormat("es-CO", {
    style: "currency",
    currency: "USD",
    maximumFractionDigits: 2,
  }).format(Number(value));
};

const slugify = (value: string): string =>
  value
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "") || "inventario";

const th: React.CSSProperties = {
  padding: "12px 10px",
  borderBottom: "1px solid #e5e7eb",
  textAlign: "left",
  fontWeight: 600,
  fontSize: "14px",
  whiteSpace: "nowrap",
};

const td: React.CSSProperties = {
  padding: "10px",
  borderBottom: "1px solid #f1f5f9",
  fontSize: "14px",
  whiteSpace: "nowrap",
};

export default InventoryTable;

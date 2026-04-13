import type { InventoryReportRow } from "../types/types";
import  { formatMoney, formatNumber } from "../types/utils";

interface Props {
  rows: InventoryReportRow[];
}

const InventoryRotationTable = ({ rows }: Props) => {
  return (
    <div style={{ overflowX: "auto", marginTop: 16, border: "1px solid #e5e7eb", borderRadius: 12 }}>
      <table style={{ width: "100%", minWidth: 2200, borderCollapse: "collapse" }}>
        <thead>
          <tr style={{ background: "#f3f4f6" }}>
            <Th>CODIGO</Th>
            <Th>DESCRIPCION</Th>
            <Th>CLASIFICACION</Th>
            <Th>EXIST. ANT.</Th>
            <Th>COMPRAS</Th>
            <Th>VENTAS</Th>
            <Th>ENTRADA</Th>
            <Th>SALIDA</Th>
            <Th>EXIST. FINAL</Th>
            <Th>F/C</Th>
            <Th>COSTO UNIT.</Th>
            <Th>TOTAL INV. FINAL</Th>
            <Th>COSTO USD</Th>
            <Th>VALOR FINAL USD</Th>
            <Th>T CAMBIO</Th>
            <Th>COGS</Th>
            <Th>PROVEEDOR</Th>
            <Th>SUPPLIER</Th>
            <Th>BRAND</Th>
            <Th>UPC1</Th>
            <Th>UPC2</Th>
            <Th>UPC3</Th>
            <Th>RETAIL</Th>
            <Th>% COSTO</Th>
            <Th>% MARGEN</Th>
            <Th>TOTAL GENERAL</Th>
            <Th>MAXIMO MES</Th>
            <Th>MAXIMO DIA</Th>
            <Th>IND ROT STOCK</Th>
            <Th>IND ROT PROMEDIO</Th>
          </tr>
        </thead>
        <tbody>
          {rows.length === 0 ? (
            <tr>
              <Td colSpan={30}>No hay datos para mostrar</Td>
            </tr>
          ) : (
            rows.map((row) => (
              <tr key={`${row.codigo}-${row.descripcion}`}>
                <Td>{row.codigo}</Td>
                <Td>{row.descripcion}</Td>
                <Td>{row.clasificacionCompleta}</Td>
                <Td>{formatNumber(row.existenciaAnterior)}</Td>
                <Td>{formatNumber(row.compras)}</Td>
                <Td>{formatNumber(row.ventas)}</Td>
                <Td>{formatNumber(row.entrada)}</Td>
                <Td>{formatNumber(row.salida)}</Td>
                <Td>{formatNumber(row.existenciaFinal)}</Td>
                <Td>{formatNumber(row.factorCaja)}</Td>
                <Td>{formatMoney(row.costoUnitario)}</Td>
                <Td>{formatMoney(row.totalInvFinal)}</Td>
                <Td>{formatMoney(row.costoUnitarioUsd)}</Td>
                <Td>{formatMoney(row.valorFinalUsd)}</Td>
                <Td>{formatNumber(row.tCambio)}</Td>
                <Td>{formatMoney(row.cogs)}</Td>
                <Td>{row.proveedor}</Td>
                <Td>{row.supplier}</Td>
                <Td>{row.brand}</Td>
                <Td>{row.upc1}</Td>
                <Td>{row.upc2}</Td>
                <Td>{row.upc3}</Td>
                <Td>{formatMoney(row.retail)}</Td>
                <Td>{row.pctCosto === null ? "-" : `${formatNumber(row.pctCosto)}%`}</Td>
                <Td>{row.pctMargen === null ? "-" : formatNumber(row.pctMargen)}</Td>
                <Td>{formatNumber(row.totalGeneral)}</Td>
                <Td>{formatNumber(row.maximoMes)}</Td>
                <Td>{formatNumber(row.maximoDia)}</Td>
                <Td>{formatNumber(row.indRotStock)}</Td>
                <Td>{formatNumber(row.indRotPromedio)}</Td>
              </tr>
            ))
          )}
        </tbody>
      </table>
    </div>
  );
};

const Th = ({ children }: { children: React.ReactNode }) => (
  <th
    style={{
      padding: "10px",
      fontSize: 12,
      fontWeight: 700,
      textAlign: "left",
      whiteSpace: "nowrap",
      borderBottom: "1px solid #e5e7eb",
    }}
  >
    {children}
  </th>
);

const Td = ({
  children,
  colSpan,
}: {
  children: React.ReactNode;
  colSpan?: number;
}) => (
  <td
    colSpan={colSpan}
    style={{
      padding: "10px",
      fontSize: 13,
      whiteSpace: "nowrap",
      borderBottom: "1px solid #f1f5f9",
    }}
  >
    {children}
  </td>
);

export default InventoryRotationTable;
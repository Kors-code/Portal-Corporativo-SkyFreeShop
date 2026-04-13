import { useEffect, useState } from "react";
import { getStores, importInventory, type Store } from "./services/inventoryService";
import InventoryTable from "./components/InventoryTable";

const InventoryDashboard = () => {
    const [file, setFile] = useState<File | null>(null);
    const [stores, setStores] = useState<Store[]>([]);
    const [selectedStoreId, setSelectedStoreId] = useState<number | "">("");
    const [loading, setLoading] = useState(false);
    const [message, setMessage] = useState("");
    const [messageType, setMessageType] = useState<"success" | "error" | "info" | "">("");
    const [refreshKey, setRefreshKey] = useState(0);

    useEffect(() => {
        const loadStores = async () => {
            try {
                const data = await getStores();
                setStores(data);
            } catch (error) {
                console.error("Error cargando stores:", error);
            }
        };

        loadStores();
    }, []);

    const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        if (e.target.files && e.target.files.length > 0) {
            setFile(e.target.files[0]);
            setMessage("");
            setMessageType("");
        }
    };

    const handleUpload = async () => {
        if (!file) {
            setMessage("⚠️ Selecciona un archivo primero");
            setMessageType("error");
            return;
        }

        if (!selectedStoreId) {
            setMessage("⚠️ Selecciona una tienda primero");
            setMessageType("error");
            return;
        }

        setLoading(true);
        setMessage("");
        setMessageType("");

        try {
            const response = await importInventory(file, Number(selectedStoreId));
            setMessage(response?.message ?? "✅ Inventario importado correctamente");
            setMessageType("success");
            setFile(null);
            setRefreshKey((prev) => prev + 1);

            const input = document.getElementById("inventory-file-input") as HTMLInputElement | null;
            if (input) input.value = "";
        } catch (error) {
            console.error("Error upload:", error);
            setMessage("❌ Error al importar");
            setMessageType("error");
        } finally {
            setLoading(false);
        }
    };

    return (
        <div style={{ padding: "20px" }}>
            <h1 style={{ fontSize: "28px", fontWeight: 700, marginBottom: "20px" }}>
                Inventario
            </h1>

            <div
                style={{
                    border: "1px solid #e5e7eb",
                    borderRadius: "12px",
                    padding: "20px",
                    marginBottom: "20px",
                    background: "#fff",
                }}
            >
                <h3 style={{ fontSize: "18px", fontWeight: 600, marginBottom: "12px" }}>
                    Subir archivo de inventario
                </h3>

                <div style={{ display: "flex", gap: "12px", flexWrap: "wrap", alignItems: "center" }}>
                    <select
                        value={selectedStoreId}
                        onChange={(e) => setSelectedStoreId(e.target.value ? Number(e.target.value) : "")}
                        style={{
                            padding: "8px",
                            border: "1px solid #d1d5db",
                            borderRadius: "8px",
                            background: "#fff",
                            minWidth: "220px",
                        }}
                    >
                        <option value="">Selecciona una tienda</option>
                        {stores.map((store) => (
                            <option key={store.id} value={store.id}>
                                {store.name}
                            </option>
                        ))}
                    </select>

                    <input
                        id="inventory-file-input"
                        type="file"
                        accept=".xlsx,.xls,.csv"
                        onChange={handleFileChange}
                        style={{
                            padding: "8px",
                            border: "1px solid #d1d5db",
                            borderRadius: "8px",
                            background: "#fff",
                        }}
                    />

                    <button
                        onClick={handleUpload}
                        disabled={loading}
                        style={{
                            padding: "10px 16px",
                            background: loading ? "#93c5fd" : "#2563eb",
                            color: "white",
                            border: "none",
                            borderRadius: "8px",
                            cursor: loading ? "not-allowed" : "pointer",
                            fontWeight: 600,
                        }}
                    >
                        {loading ? "Subiendo..." : "Subir archivo"}
                    </button>
                </div>

                {message && (
                    <p
                        style={{
                            marginTop: "12px",
                            color:
                                messageType === "success"
                                    ? "#166534"
                                    : messageType === "error"
                                    ? "#b91c1c"
                                    : "#1d4ed8",
                            background:
                                messageType === "success"
                                    ? "#dcfce7"
                                    : messageType === "error"
                                    ? "#fee2e2"
                                    : "#dbeafe",
                            padding: "10px 12px",
                            borderRadius: "8px",
                            display: "inline-block",
                        }}
                    >
                        {message}
                    </p>
                )}
            </div>

            <InventoryTable refreshKey={refreshKey} stores={stores} />
        </div>
    );
};

export default InventoryDashboard;
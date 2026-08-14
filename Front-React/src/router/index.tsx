import { BrowserRouter, Routes, Route, Navigate } from "react-router-dom";
import type { ReactNode } from "react";
import MainLayout from "../layout/MainLayout";
import HomePage from "../pages/HomePage";
import WelcomePage from "../pages/WelcomePage";
import DavibankConverterPage from "../pages/DavibankConverterPage";

/* TUS MÓDULOS */
import BudgetPage from "../modules/budgets/pages/BudgetPage";
import ImportsManagerPage from "../modules/imports/pages/ImportsManagerPage";
import CategoryCommissionsPage from "../modules/commissions/pages/CategoryCommissionsPage";
import CommissionCardsPage from "../modules/commissions/pages/CommissionCardsPage";
import CommisionCashier from "../modules/commissions/pages/CommisionCashier";
import CommisionCashierUsers from "../modules/commissions/pages/CommisionCashierUsers";
import CommisionsUser from "../modules/commissions/pages/CommisionsUser";
import UsersManager from "../modules/users/pages/UsersManager";
import AdminPermissionsPanel from "../modules/users/pages/AdminPermissionsPanel";
import ImportCatalog from "../modules/imports/pages/importCatalog";
import CatalogMatchPage from "../modules/WishList/pages/WishList";
import AdminWishList from "../modules/WishList/pages/AdminWishList";
import AdvisorSplitByCategory from "../modules/commissions/pages/AdvisorSplitByCategory";
import DualCommissionAdmin from "../modules/commissions/pages/DualCommissionAdmin";
import SpecialistCommissionsPanel from "../modules/commissions/pages/SpecialistCommissionsPanel";
import CommissionLeadersPage from "../modules/commissions/pages/CommissionLeadersPage";
import InventoryDashboard  from "../modules/inventory/InventoryDashboard";
import InventoryDashboardPro from "../modules/inventory/InventoryDashboardPro";
import CatalogImportCard from "../modules/inventory/CatalogImportCard";
import InventoryMetricsRunner from "../modules/inventory/InventoryMetricsRunner";
import InventoryCoveragePage from "../modules/inventory/pages/InventoryCoveragePage";
import InventoryAlertsPage from "../modules/inventory/pages/InventoryAlertsPage";
import InventoryImportsManagerPage from "../modules/imports/pages/InventoryImportsManagerPage";
import BankImportsManagerPage from "../modules/imports/pages/BankImportsManagerPage";
import CrearEntregaPage from "../modules/entregas/pages/CrearEntregaPage";
import DetalleEntregaPage from "../modules/entregas/pages/DetalleEntregaPage";
import EntregasDashboardPage from "../modules/entregas/pages/EntregasDashboardPage";
import ListadoEntregasPage from "../modules/entregas/pages/ListadoEntregasPage";
import VisualizacionesGuard from "../modules/visualizaciones/components/VisualizacionesGuard";
import VisualizacionesHub from "../modules/visualizaciones/pages/VisualizacionesHub";
import CashClosureDashboard from "../modules/visualizaciones/pages/CashClosureDashboard";
import StoreSalesDashboard from "../modules/visualizaciones/pages/StoreSalesDashboard";
import AdvisorSalesDashboard from "../modules/visualizaciones/pages/AdvisorSalesDashboard";
import AdvisorAnalyticsDashboard from "../modules/visualizaciones/pages/AdvisorAnalyticsDashboard";
import AdvisorInfoPage from "../modules/advisorInfo/pages/AdvisorInfoPage";
import { hasPermission } from "../auth/auth";


function PermissionGate({ permission, children }: { permission: string; children: ReactNode }) {
  if (!hasPermission(permission)) {
    return <Navigate to="/" replace />;
  }

  return <>{children}</>;
}


export default function AppRouter() {
  // Pass userId as a prop - replace with actual user ID from context or auth
  
  return (
    <BrowserRouter basename="/panel">

            

      <Routes>
          <Route path="/davibank-converter" element={<PermissionGate permission="accounting.bank-imports.create"><DavibankConverterPage /></PermissionGate>} />
          <Route path="/WelcomePage" element={
            
                    <WelcomePage />

        } />
          <Route path="/CommisionsUser"  element={<CommisionsUser />} />
          <Route path="/CashierAwardsUsers" element={<CommisionCashierUsers />} />
          <Route path="/CatalogMatchPage" element={<CatalogMatchPage />} />
          <Route path="/AdminWishList" element={<AdminWishList />} />
        {/* Todas las rutas usan el layout (navbar visible en todas) */}
          <Route path="/commissions/SpecialistCommissionsPanel" element={<SpecialistCommissionsPanel  />} />
        <Route element={<MainLayout />}>
          <Route path="/" element={<HomePage />} />

          <Route path="/users" element={<UsersManager />} />
          <Route path="/AdminPermissionsPanel" element={<AdminPermissionsPanel />} />
          <Route path="/ImportsManagerPage" element={<ImportsManagerPage />} />
          <Route path="/importCatalog" element={<ImportCatalog />} />

          <Route path="/budget" element={<PermissionGate permission="budget.admin.view"><BudgetPage /></PermissionGate>} />
          <Route path="/InventoryDashboard" element={<InventoryDashboard />} />
          <Route path="/InventoryDashboardPro" element={<InventoryDashboardPro />} />
          <Route path="/InventoryMetricsRunner" element={<InventoryMetricsRunner />} />
          <Route path="/inventarios/cobertura" element={<InventoryCoveragePage />} />
          <Route path="/inventarios/alertas" element={<InventoryAlertsPage />} />
          <Route path="/visualizaciones" element={<VisualizacionesGuard><VisualizacionesHub /></VisualizacionesGuard>} />
          <Route path="/visualizaciones/daily-sales" element={<VisualizacionesGuard><CashClosureDashboard /></VisualizacionesGuard>} />
          <Route path="/visualizaciones/cierre-caja" element={<VisualizacionesGuard><CashClosureDashboard /></VisualizacionesGuard>} />
          <Route path="/visualizaciones/ventas-tiendas" element={<VisualizacionesGuard><StoreSalesDashboard /></VisualizacionesGuard>} />
          <Route path="/visualizaciones/ventas-asesores" element={<VisualizacionesGuard><AdvisorSalesDashboard /></VisualizacionesGuard>} />
          <Route path="/visualizaciones/asesores-analytics" element={<VisualizacionesGuard><AdvisorAnalyticsDashboard /></VisualizacionesGuard>} />
          <Route path="/info-asesores" element={<PermissionGate permission="advisor-info.view"><AdvisorInfoPage /></PermissionGate>} />
          <Route path="/CatalogImportCard" element={<CatalogImportCard />} />

          <Route path="/entregas" element={<EntregasDashboardPage />} />
          <Route path="/entregas/nuevo" element={<CrearEntregaPage />} />
          <Route path="/entregas/:id/editar" element={<CrearEntregaPage />} />
          <Route path="/entregas/recibir" element={<ListadoEntregasPage tipoInicial="recepcion" titulo="Actas que me han enviado" />} />
          <Route path="/entregas/activas" element={<ListadoEntregasPage tipoInicial="activas" titulo="Actas activas y responsables" />} />
          <Route path="/entregas/listado" element={<ListadoEntregasPage />} />
          <Route path="/entregas/:id" element={<DetalleEntregaPage />} />
          <Route path="/CrearEntregaPage" element={<CrearEntregaPage/>} />
          <Route path="/DetalleEntregaPage" element={<DetalleEntregaPage />} />
          <Route path="/EntregasDashboardPage" element={<EntregasDashboardPage />} />
          <Route path="/ListadoEntregasPage" element={<ListadoEntregasPage />} />

          

          <Route path="/InventoryImportsManagerPage" element={<InventoryImportsManagerPage />} />
          <Route path="/BankImportsManagerPage" element={<PermissionGate permission="accounting.bank-imports.view"><BankImportsManagerPage /></PermissionGate>} />
          <Route path="/BankMovementsPage" element={<PermissionGate permission="accounting.bank-imports.view"><BankImportsManagerPage initialView="movements" /></PermissionGate>} />
          <Route path="/CommissionCardsPage" element={<CommissionCardsPage />} />
          <Route path="/CashierAwards" element={<CommisionCashier />} />
          <Route path="/commissions/categories" element={<PermissionGate permission="budget.commissions.manage"><CategoryCommissionsPage /></PermissionGate>} />
          <Route path="/commissions/AdvisorSplitByCategory" element={<AdvisorSplitByCategory  />} />
          <Route
            path="/commissions/CommissionLeadersPage"
            element={
              <PermissionGate permission="budget.leader.view">
                <CommissionLeadersPage />
              </PermissionGate>
            }
          />
          <Route
  path="/commissions/DualCommissionAdmin"
  element={
    <DualCommissionAdmin
      advisorAId={0}
      advisorBId={0}
      budgetIds={[]}
    />
  }
/>
        </Route>
      </Routes>
    </BrowserRouter>
  );
}

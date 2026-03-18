import { BrowserRouter, Routes, Route } from "react-router-dom";
import MainLayout from "../layout/MainLayout";
import HomePage from "../pages/HomePage";
import WelcomePage from "../pages/WelcomePage";

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




export default function AppRouter() {
  // Pass userId as a prop - replace with actual user ID from context or auth
  
  return (
    <BrowserRouter basename="/panel">

            

      <Routes>
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

          <Route path="/budget" element={<BudgetPage />} />

          <Route path="/CommissionCardsPage" element={<CommissionCardsPage />} />
          <Route path="/CashierAwards" element={<CommisionCashier />} />
          <Route path="/commissions/categories" element={<CategoryCommissionsPage />} />
          <Route path="/commissions/AdvisorSplitByCategory" element={<AdvisorSplitByCategory  />} />
          <Route path="/commissions/CommissionLeadersPage" element={<CommissionLeadersPage />} />
          <Route path="/commissions/DualCommissionAdmin" element={<DualCommissionAdmin advisorAId={0} advisorBId={0} budgetIds={[]} onClose={function (): void {
            throw new Error("Function not implemented.");
          } }  />} />
        </Route>
      </Routes>
    </BrowserRouter>
  );
}

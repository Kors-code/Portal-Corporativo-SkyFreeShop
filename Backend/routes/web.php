<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CandidatoController;
use App\Http\Controllers\VacanteController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\ToggleController;
use App\Http\Controllers\TwoFactorController;
use App\Http\Controllers\UserExportController;
use App\Http\Controllers\TwoFactorEmailController;
use App\Http\Controllers\PhotoController;
use App\Http\Controllers\ShowInicioController;
use Illuminate\Http\Request;
use App\Http\Controllers\PersonalController\FormController;
use App\Http\Controllers\PersonalController\FormatoController;
use App\Http\Controllers\PersonalController\ListController;
use App\Http\Controllers\PersonalController\ExcelController;
use App\Http\Controllers\PersonalController\EmpleadoController;
use App\Http\Controllers\Api\BudgetController;
use App\Http\Controllers\Api\CommissionReportController;
use App\Http\Controllers\ImportSalesController;
use App\Http\Controllers\Api\CommissionController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\CategoryCommissionController;
use App\Http\Controllers\Api\ImportBatchController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\UserController as ApiUserController;
use App\Http\Controllers\SalesByUserController;
use App\Http\Controllers\Api\TurnsImportController;
use App\Http\Controllers\Api\BudgetProgressController;
use App\Http\Controllers\Api\CommissionActionController;
use App\Http\Controllers\WishList\CatalogController;
use App\Http\Controllers\Api\WishItemController;
use App\Http\Controllers\Api\AdvisorController;
use App\Http\Controllers\Api\CommissionLideres;
use App\Http\Controllers\Api\PermissionController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Convención de permisos sugerida:
| - panel.view
| - users.view / users.manage
| - permissions.view / permissions.manage
| - candidates.view / candidates.manage / candidates.export
| - vacancies.view / vacancies.manage
| - disciplines.view / disciplines.manage / disciplines.export
| - imports.create / imports.manage
| - budget.view / budget.manage
| - budget.reports.view
| - budget.commissions.view / budget.commissions.manage
| - advisors.view / advisors.manage
| - wishlist.view / wishlist.manage
| - reports.view
| - commissions.view / commissions.manage
|
| Importante:
| - "budget" = módulo Presupuesto
| - "advisors" = módulo Asesores
| - "commissions" dentro de presupuesto = configuración / cálculo / administración
|   relacionada al presupuesto
*/

Route::get('/', function () {
    return view('home');
})->name('home');

/* --------------------------------------------------------------------------
| Rutas públicas / acceso general
| -------------------------------------------------------------------------- */

Route::get('/welcome', [ShowInicioController::class, 'showWelcome'])
    ->name('welcome')
    ->middleware(['auth', 'permission:panel.view']);

Route::get('/presupuesto', [ShowInicioController::class, 'showPortal'])
    ->name('presupuesto')
    ->defaults('type', 'presupuesto')
    ->middleware(['auth', 'permission:panel.view']);

Route::post('/usuarios', [UserController::class, 'store'])->name('usuarios.store');
Route::get('/verify-email/{id}/{token}', [UserController::class, 'verifyEmail'])->name('verify.email');
Route::post('/usuarios/{id}/enviar-verificacion', [UserController::class, 'enviarVerificacion'])->name('usuarios.enviarVerificacion');

Route::post('/login', [AuthenticatedSessionController::class, 'store'])->middleware(['throttle:5,1']);
Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');

/* Public candidate form */
Route::get('postular/{vacante}', [CandidatoController::class, 'formularioPostulacion'])->name('postular');
Route::post('postular/{slug}', [CandidatoController::class, 'store'])->middleware(['throttle:5,1'])->name('postular.store');
Route::get('/vervacantes/{localidad}', [VacanteController::class, 'vervacantes'])->name('vacantes.vacantes');
Route::post('/vacantes/{slug}/postulacion', [CandidatoController::class, 'store'])->name('vacante.postular');
Route::get('/vacantes/{slug}', [VacanteController::class, 'show'])->name('vacantes.show');

/* 2FA */
Route::get('/2fa/setup', [TwoFactorController::class, 'enable'])->name('2fa.setup');
Route::get('/2fa/verify', [TwoFactorController::class, 'showVerifyForm'])->name('2fa.verify');
Route::post('/2fa/verify', [TwoFactorController::class, 'verify'])->name('2fa.verify.post');
Route::post('/2fa/setup', [TwoFactorController::class, 'setup'])->name('2fa.setup.post');

Route::get('/2fa-email/setup', [TwoFactorEmailController::class, 'showSetupForm'])->name('2fa.email.setup');
Route::post('/email2fa/setup', [TwoFactorEmailController::class, 'setup'])->name('email2fa.setup.post');
Route::post('/email2fa/verify', [TwoFactorEmailController::class, 'verify'])->name('email2fa.verify.post');

/* Política */
Route::get('/politica-tratamiento', [VacanteController::class, 'politica-tratamiento'])->name('politica-tratamiento');

/* --------------------------------------------------------------------------
| Rutas protegidas por sesión
| -------------------------------------------------------------------------- */

Route::middleware('auth')->group(function () {

    /* ----------------------------- Importaciones ----------------------------- */

    Route::post('/masivo/subircv', [CandidatoController::class, 'storeMasivo'])
        ->name('storeMasivo.subir')
        ->middleware(['permission:imports.create,imports.manage']);

    Route::get('/carga-masiva', [CandidatoController::class, 'subirAllCv'])
        ->name('subirAllCv')
        ->middleware(['permission:imports.manage']);

    /* -------------------------------- Usuarios ------------------------------ */

    Route::get('/usuarios/crear', [UserController::class, 'create'])
        ->middleware(['permission:users.manage'])->name('usuarios.create');

    Route::post('users', [UserController::class, 'store'])
        ->middleware(['permission:users.manage'])->name('users.store');

    Route::get('/view-users', [UserController::class, 'index'])
        ->middleware(['permission:users.view'])->name('view-users');

    Route::get('/users/{user}/ver_user', [UserController::class, 'verusuario'])
        ->middleware(['permission:users.view'])->name('ver_user');

    Route::get('/users/ver_perfil', [UserController::class, 'verperfil'])
        ->middleware(['permission:users.view'])->name('ver_perfil');

    Route::get('users', [UserController::class, 'index'])
        ->middleware(['permission:users.view'])->name('users.index');

    Route::get('/users/export', [UserExportController::class, 'export'])
        ->middleware(['permission:users.view'])->name('users.export');

    Route::delete('/users/{user}', [UserController::class, 'destroy'])
        ->middleware(['permission:users.manage'])->name('users.destroy');

    Route::get('/users/{id}/edit', [UserController::class, 'edit'])
        ->middleware(['permission:users.manage'])->name('users.edit');

    Route::put('/users/{id}', [UserController::class, 'update'])
        ->middleware(['permission:users.manage'])->name('users.update');

    Route::get('/photo/{id}', [PhotoController::class, 'show'])
        ->middleware(['permission:users.view'])->name('photo.show');

    Route::post('photos', [PhotoController::class, 'store'])
        ->middleware(['can:admin'])->name('photos.store');

    /* ------------------------- Candidatos / Vacantes ------------------------ */

    Route::get('/candidatos', [CandidatoController::class, 'index'])
        ->middleware(['permission:candidates.view'])->name('candidatos.index');

    Route::get('/candidatos/{slug}/export', [CandidatoController::class, 'export'])
        ->middleware(['permission:candidates.export'])->name('candidatos.export');

    Route::get('/candidatos/{id}/cv', [CandidatoController::class, 'descargarCV'])
        ->middleware(['permission:candidates.view'])->name('candidatos.cv');

    Route::post('/candidatos/{id}/correo', [CandidatoController::class, 'enviarCorreo'])
        ->middleware(['permission:candidates.manage'])->name('candidatos.correo');

    Route::post('/candidatos/{candidato}/rechazar', [CandidatoController::class, 'rechazar'])
        ->middleware(['permission:candidates.manage'])->name('candidatos.rechazar');

    Route::post('/candidatos/{candidato}/aprobar', [CandidatoController::class, 'aprobar'])
        ->middleware(['permission:candidates.manage'])->name('candidatos.aprobar');

    Route::get('/panel/candidatos', [CandidatoController::class, 'mostrarCandidatos'])
        ->middleware(['permission:candidates.view'])->name('panel.candidatos');

    Route::get('/candidatos/{slug}/aprobados', [CandidatoController::class, 'showaprobados'])
        ->middleware(['permission:candidates.view'])->name('candidatos.aprobados.list');

    Route::get('/candidatos/{slug}/rechazados', [CandidatoController::class, 'showrechazados'])
        ->middleware(['permission:candidates.view'])->name('candidatos.rechazados.list');

    Route::get('/candidatos/{slug}', [CandidatoController::class, 'show'])
        ->middleware(['permission:candidates.view'])->name('candidatos.show');

    Route::resource('candidatos', CandidatoController::class)
        ->except(['index', 'show'])
        ->middleware(['permission:candidates.manage']);

    Route::get('/vacante/create', [VacanteController::class, 'create'])
        ->middleware(['permission:vacancies.manage'])->name('vacante.create');

    Route::get('/vacantes', [VacanteController::class, 'index'])
        ->middleware(['permission:vacancies.view'])->name('vacantes.index');

    Route::get('/inicio', [VacanteController::class, 'inicio'])
        ->middleware(['permission:vacancies.view'])->name('vacantes.inicio');

    Route::post('/vacantes', [VacanteController::class, 'store'])
        ->middleware(['permission:vacancies.manage'])->name('vacantes.store');

    Route::post('/vacantes/{slug}/habilitar', [VacanteController::class, 'habilitar'])
        ->middleware(['permission:vacancies.manage'])->name('vacantes.habilitar');

    Route::resource('vacantes', VacanteController::class)
        ->except(['index', 'show'])
        ->middleware(['permission:vacancies.manage']);

    Route::get('/enviar-email', [UserController::class, 'enviarVerificacion'])
        ->middleware(['permission:users.manage'])->name('enviarVerificacion');

    /* ------------------------- Disciplinas / Empleados ---------------------- */

    Route::get('/DisciplinaPositiva', [FormController::class, 'showForm'])
        ->name('Disciplina.show')
        ->middleware(['permission:disciplines.view']);

    Route::post('/form', [FormController::class, 'handleForm'])
        ->middleware(['permission:disciplines.manage'])
        ->name('form.submit');

    Route::post('/generar-pdf', [FormatoController::class, 'generarPDF'])
        ->middleware(['permission:disciplines.view'])
        ->name('formulario.pdf');

    Route::get('/import-excel', [ExcelController::class, 'showForm'])
        ->name('excel.form')
        ->middleware(['permission:imports.create']);

    Route::post('/upload-excel', [EmpleadoController::class, 'importExcel'])
        ->name('excel.import')
        ->middleware(['permission:imports.create']);

    Route::get('/buscar-empleado/{cedula}', [EmpleadoController::class, 'buscarPorCedula'])
        ->name('empleado.buscar')
        ->middleware(['permission:employees.view']);

    Route::get('/descargar-pdf', [FormatoController::class, 'descargarPDF'])
        ->name('descargar.pdf')
        ->middleware(['permission:disciplines.view']);

    Route::get('/empleados', [ListController::class, 'mostrarEmpleados'])
        ->name('empleados.list')
        ->middleware(['permission:employees.view']);

    Route::get('/Disciplinas', [ListController::class, 'mostrarDisciplinasPositivas'])
        ->name('Disciplinas.list')
        ->middleware(['permission:disciplines.view']);

    Route::get('/DisciplinasUsers', [ListController::class, 'mostrarDisciplinasPositivasUsers'])
        ->name('Disciplinas.listUsers')
        ->middleware(['permission:disciplines.view']);

    Route::get('/empleados/export', [ListController::class, 'exportarEmpleadosExcel'])
        ->name('exportar.empleados')
        ->middleware(['permission:employees.view']);

    Route::get('/disciplinas/export', [ListController::class, 'exportarDisciplinasExcel'])
        ->name('disciplinas.export')
        ->middleware(['permission:disciplines.view']);

    Route::get('/disciplinas/eliminadas', [ListController::class, 'MostrarEliminados'])
        ->name('disciplinas.eliminadas')
        ->middleware(['permission:disciplines.view']);

    Route::post('/disciplinas/delete', [ListController::class, 'eliminarDisciplina'])
        ->name('disciplinas.delete')
        ->middleware(['permission:disciplines.manage']);

    Route::post('/disciplinas/restore', [ListController::class, 'restaurarDisciplina'])
        ->name('disciplinas.restore')
        ->middleware(['permission:disciplines.manage']);

    /* ------------------------------- Dashboard ------------------------------ */

    Route::get('/dashboard', fn () => view('dashboard'))
        ->name('dashboard')
        ->middleware(['permission:panel.view']);

    /* --------------------------------------------------------------------------
    | API v1 (sesión + permisos por módulo)
    | -------------------------------------------------------------------------- */

    Route::prefix('api/v1')->middleware(['auth'])->group(function () {

        /* ------------------------ Permissions / Roles ------------------------ */

        Route::get('/permissions', [PermissionController::class, 'permissions'])
            ->middleware('permission:permissions.view');

        Route::get('admin/users-with-permissions', [PermissionController::class, 'usersWithPermissions'])
            ->middleware(['permission:permissions.view']);

        Route::get('/roles', [PermissionController::class, 'roles'])
            ->middleware('permission:permissions.view');

        Route::post('/roles/{id}/permissions', [PermissionController::class, 'updateRolePermissions'])
            ->middleware('permission:permissions.manage');

        Route::post('/users/{id}/permissions', [PermissionController::class, 'updateUserPermissions'])
            ->middleware('permission:permissions.manage');

        Route::get('/users/{id}/permissions', [PermissionController::class, 'userPermissions'])
            ->middleware('permission:permissions.view');

        /* ------------------------------- Users ------------------------------- */

        Route::middleware(['permission:users.manage'])->group(function () {
            Route::get('manage/users', [ApiUserController::class, 'indexForManagedRoles']);
            Route::post('manage/users', [ApiUserController::class, 'storeManagedUser']);
            Route::delete('manage/users/{id}', [ApiUserController::class, 'destroyManagedUser']);
            Route::put('manage/users/{id}', [ApiUserController::class, 'updateManagedUser']);
        });

        Route::get('users', [ApiUserController::class, 'index'])
            ->middleware(['permission:users.view']);

        Route::post('users/{id}/assign-role', [ApiUserController::class, 'assignRole'])
            ->middleware(['permission:users.manage']);

        /* ------------------------------ Reports ------------------------------ */

        Route::get('sales/users', [SalesByUserController::class, 'getUsersWithSales'])
            ->middleware('permission:reports.view');

        Route::get('sales/by-user', [SalesByUserController::class, 'getSalesByUser'])
            ->middleware('permission:reports.view');

        Route::get('sales', [SalesByUserController::class, 'getSalesByUser'])
            ->middleware('permission:reports.view');

        Route::get('reports/cashier/{userId}/categories', [ReportController::class, 'cashierCategories'])
            ->middleware('permission:reports.view');

        Route::get('reports/advisors-split', [CommissionReportController::class, 'advisorsSplit'])
            ->middleware('permission:reports.view');

        Route::post('/cashier-adjustments', [ReportController::class, 'storeCashierAdjustment'])
            ->middleware('permission:reports.view');

        /* ------------------------------ Budgets ------------------------------ */

        Route::middleware(['permission:budget.view'])->group(function () {
            Route::get('/budgets', [BudgetController::class, 'index']);
            Route::get('/budgets/active', [BudgetController::class, 'active']);
            Route::get('/commissions/by-seller/{userId}/export', [CommissionReportController::class, 'exportSellerDetail']);
            Route::get('/commissions/export', [CommissionReportController::class, 'exportExcel']);
            Route::get('reports/cashier-awards', [ReportController::class, 'cashierAwards']);
            Route::get('/reports/cashier-awards/export', [ReportController::class, 'cashierAwardsExport']);
        });

        Route::middleware(['permission:budget.manage'])->group(function () {
            Route::post('/budgets', [BudgetController::class, 'store']);
            Route::put('/budgets/{id}', [BudgetController::class, 'update']);
            Route::delete('/budgets/{id}', [BudgetController::class, 'destroy']);
            Route::patch('/budgets/{id}/cashier-prize', [BudgetController::class, 'updateCashierPrize']);
            Route::post('/budgets/{id}/close', [BudgetController::class, 'close']);
        });

        /* ------------------------ Budget / Commissions ------------------------ */

        Route::prefix('commissions')->group(function () {

            Route::get('/', [CommissionController::class, 'userSummary'])
                ->middleware('permission:budget.commissions.view');

            Route::get('/summary', [CommissionController::class, 'userSummary'])
                ->middleware('permission:budget.commissions.view');

            Route::post('/generate', [CommissionController::class, 'generate'])
                ->middleware('permission:budget.commissions.manage');

            Route::post('/finalize', [CommissionController::class, 'finalize'])
                ->middleware('permission:budget.commissions.manage');

            Route::get('/my', [CommissionReportController::class, 'myCommissions'])
                ->middleware('permission:commissions.view');

            Route::get('/my/export', [CommissionReportController::class, 'myExport'])
                ->middleware('permission:commissions.view');

            Route::get('/by-seller', [CommissionReportController::class, 'bySeller'])
                ->middleware('permission:budget.commissions.view');

            Route::get('/by-seller/{userId}', [CommissionReportController::class, 'bySellerDetail'])
                ->middleware('permission:budget.commissions.view');

            Route::put('/assign-turns/{userId}/{budget_id}', [CommissionReportController::class, 'assignTurns'])
                ->middleware('permission:budget.commissions.manage');

            Route::post('/assign-turns/{userId}/{budget_id}', [CommissionReportController::class, 'assignTurns'])
                ->middleware('permission:budget.commissions.manage');

            Route::get('/store-split/{budgetId}', [CommissionLideres::class, 'getStoreSplit'])
                ->middleware('permission:budget.commissions.view');

            Route::post('/save-store-split', [CommissionLideres::class, 'saveStoreSplit'])
                ->middleware('permission:budget.commissions.manage');

            Route::post('/recalc-sale/{id}', [CommissionController::class, 'recalcSale'])
                ->middleware('permission:budget.commissions.manage');

            Route::post('/recalc-user/{userId}/{month}', [CommissionController::class, 'recalcUserMonth'])
                ->middleware('permission:budget.commissions.manage');
        });

        Route::prefix('commissions/categories')->group(function () {
            Route::get('/', [CategoryCommissionController::class, 'index'])
                ->middleware('permission:budget.commissions.view');

            Route::post('/', [CategoryCommissionController::class, 'upsert'])
                ->middleware('permission:budget.commissions.manage');

            Route::delete('/{id}', [CategoryCommissionController::class, 'destroy'])
                ->middleware('permission:budget.commissions.manage');

            Route::post('/bulk', [CategoryCommissionController::class, 'bulkUpdate'])
                ->middleware('permission:budget.commissions.manage');
        });

        Route::prefix('commission-leaders')->group(function () {
            Route::get('/', [CommissionLideres::class, 'index'])
                ->middleware('permission:budget.commissions.view');

            Route::post('/', [CommissionLideres::class, 'storeLeader'])
                ->middleware('permission:budget.commissions.manage');

            Route::put('/{id}', [CommissionLideres::class, 'updateLeader'])
                ->middleware('permission:budget.commissions.manage');

            Route::delete('/{id}', [CommissionLideres::class, 'destroyLeader'])
                ->middleware('permission:budget.commissions.manage');

            Route::get('/{id}/absences', [CommissionLideres::class, 'listAbsences'])
                ->middleware('permission:budget.commissions.view');

            Route::post('/{id}/absences', [CommissionLideres::class, 'addAbsence'])
                ->middleware('permission:budget.commissions.manage');

            Route::delete('/{id}/absences/{aid}', [CommissionLideres::class, 'deleteAbsence'])
                ->middleware('permission:budget.commissions.manage');

            Route::get('/config', [CommissionLideres::class, 'getConfig'])
                ->middleware('permission:budget.commissions.view');

            Route::post('/config', [CommissionLideres::class, 'saveConfig'])
                ->middleware('permission:budget.commissions.manage');

            Route::post('/calculate', [CommissionLideres::class, 'calculateCommissions'])
                ->middleware('permission:budget.commissions.manage');
        });

        /* ------------------------------ Advisors ----------------------------- */

        Route::prefix('advisors')->group(function () {
            Route::get('cashier-awards', [AdvisorController::class, 'cashierAwards'])
                ->middleware('permission:reports.view');

            Route::get('cashier/{userId}/categories', [AdvisorController::class, 'cashierCategories'])
                ->middleware('permission:reports.view');

            Route::get('specialistCheck', [AdvisorController::class, 'specialistCheck'])
                ->middleware('permission:advisors.view');

            Route::get('budget-sellers', [AdvisorController::class, 'budgetSellers'])
                ->middleware('permission:advisors.view');

            Route::get('active-sales', [AdvisorController::class, 'activeSpecialistsSales'])
                ->middleware('permission:commissions.view');

            Route::get('split-pool', [AdvisorController::class, 'splitAdvisorPool'])
                ->middleware('permission:advisors.view');

            Route::get('get-split', [AdvisorController::class, 'getAdvisorSplit'])
                ->middleware('permission:advisors.view');

            Route::post('save-split', [AdvisorController::class, 'saveAdvisorSplit'])
                ->middleware('permission:advisors.manage');

            Route::get('category-budgets', [AdvisorController::class, 'indexCategoryBudgets'])
                ->middleware('permission:advisors.view');

            Route::post('category-budgets', [AdvisorController::class, 'upsertCategoryBudget'])
                ->middleware('permission:advisors.manage');

            Route::post('category-budgets/bulk', [AdvisorController::class, 'bulkUpsert'])
                ->middleware('permission:advisors.manage');

            Route::delete('category-budgets/{id}', [AdvisorController::class, 'deleteCategoryBudget'])
                ->middleware('permission:advisors.manage');

            Route::post('specialists', [AdvisorController::class, 'assignSpecialist'])
                ->middleware('permission:advisors.manage');

            Route::get('specialists', [AdvisorController::class, 'getSpecialistsForBudget'])
                ->middleware('permission:advisors.view');
        });

        /* ------------------------------ Wishlist ----------------------------- */

        Route::prefix('wishlist')->group(function () {
            Route::post('/catalog/import', [CatalogController::class, 'import'])
                ->middleware(['permission:wishlist.manage']);

            Route::get('categories', [WishItemController::class, 'categories'])
                ->middleware('permission:wishlist.view');

            Route::get('catalog-products', [WishItemController::class, 'searchCatalog'])
                ->middleware('permission:wishlist.view');

            Route::get('items', [WishItemController::class, 'listWishItems'])
                ->middleware('permission:wishlist.view');

            Route::post('items', [WishItemController::class, 'create'])
                ->middleware('permission:wishlist.manage');

            Route::post('items/select', [WishItemController::class, 'select'])
                ->middleware('permission:wishlist.view');

            Route::get('users/sellers', [WishItemController::class, 'sellers'])
                ->middleware('permission:wishlist.view');

            Route::get('me', [WishItemController::class, 'me'])
                ->middleware('permission:panel.view');

            Route::patch('items/{id}', [WishItemController::class, 'update'])
                ->middleware('permission:wishlist.manage');

            Route::get('items/stats', [WishItemController::class, 'stats'])
                ->middleware('permission:wishlist.view');

            Route::get('items/selections', [WishItemController::class, 'selectionsList'])
                ->middleware('permission:wishlist.view');
        });

        /* -------------------------- Imports / batches ------------------------- */

        Route::middleware(['permission:imports.create'])->group(function () {
            Route::post('import-turns', [TurnsImportController::class, 'import']);
            Route::post('import-sales', [ImportSalesController::class, 'import']);
        });

        Route::middleware(['permission:imports.manage'])->group(function () {
            Route::get('imports/turns', [TurnsImportController::class, 'index']);
            Route::get('imports/turns/{id}', [TurnsImportController::class, 'show']);
            Route::delete('imports/turns/{id}', [TurnsImportController::class, 'deleteBatch']);
            Route::delete('imports/turns', [TurnsImportController::class, 'bulkDelete']);

            Route::get('imports', [ImportBatchController::class, 'index']);
            Route::get('imports/{id}', [ImportBatchController::class, 'show']);
            Route::delete('imports/{id}', [ImportBatchController::class, 'destroy']);
            Route::post('imports/bulk-delete', [ImportBatchController::class, 'bulkDestroy']);
        });
    });

    /* --------------------------------------------------------------------------
    | Panel SPA routes
    | -------------------------------------------------------------------------- */

    Route::middleware(['permission:panel.view'])->group(function () {

        Route::get('/panel', fn () => view('panel'))
            ->middleware('permission:panel.view');

        Route::get('/panel/users', fn () => view('panel'))
            ->middleware('permission:users.view');

        Route::get('/panel/ImportsManagerPage', fn () => view('panel'))
            ->middleware('permission:imports.manage');

        Route::get('/panel/budget', fn () => view('panel'))
            ->middleware('permission:budget.view');

        Route::get('/panel/CommissionCardsPage', fn () => view('panel'))
            ->middleware('permission:commissions.view');

        Route::get('/panel/CashierAwards', fn () => view('panel'))
            ->middleware('permission:budget.cashier.view');

        Route::get('/panel/CashierAwardsUsers', fn () => view('panel'))
            ->middleware('permission:budget.cashier.view');

        Route::get('/panel/commissions', fn () => view('panel'))
            ->middleware('permission:budget.commissions.view');

        Route::get('/panel/commissions/categories', fn () => view('panel'))
            ->middleware('permission:budget.commissions.manage');

        Route::get('/panel/commissions/DualCommissionAdmin', fn () => view('panel'))
            ->middleware('permission:budget.commissions.manage');

        Route::get('/panel/CommisionsUser', fn () => view('panel'))
            ->middleware('permission:commissions.view');

        Route::get('/panel/AdminWishList', fn () => view('panel'))
            ->middleware('permission:wishlist.manage');

        Route::get('/panel/{any?}', fn () => view('panel'))
            ->where('any', '.*');
    });

    /* --------------------------------------------------------------------------
    | Otros endpoints web
    | -------------------------------------------------------------------------- */

    Route::post('/llamados/importar', [FormatoController::class, 'importarExcel'])
        ->name('llamados.importar')
        ->middleware(['permission:imports.create']);

    Route::get('/api/firmas', function () {
        return response()->json([
            'empleado' => session('firma_empleado'),
            'jefe' => session('firma_jefe'),
            'proceso' => session('Proceso'),
        ]);
    })->middleware(['permission:panel.view']);
});
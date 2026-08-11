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
use App\Http\Controllers\Api\BankImportBatchController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\UserController as ApiUserController;
use App\Http\Controllers\SalesByUserController;
use App\Http\Controllers\Api\TurnsImportController;
use App\Http\Controllers\WishList\CatalogController;
use App\Http\Controllers\Api\WishItemController;
use App\Http\Controllers\Api\AdvisorController;
use App\Http\Controllers\Api\CommissionLideres;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\ApiInventarios\InventoryImportController;
use App\Http\Controllers\ApiInventarios\InventoryController;
use App\Http\Controllers\ApiInventarios\InventoryMetricsController;
use App\Http\Controllers\ApiInventarios\InventoryAlertController;
use App\Http\Controllers\ProductCatalogImportController;
use App\Http\Controllers\importAutomation;
use App\Http\Controllers\Api\AdvisorBudgetController;
use App\Http\Controllers\Api\VisualizationController;
use App\Http\Controllers\EntregaController;
use App\Http\Controllers\PublicDavibankConverterController;


/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

/* -------------------------------------------------------------------------- */
/* Public / Auth                                                               */
/* -------------------------------------------------------------------------- */

Route::get('/', fn () => view('home'))->name('home');

Route::get('/welcome', [ShowInicioController::class, 'showWelcome'])
    ->name('welcome')
    ->middleware(['auth', 'permission:portal.view']);

Route::get('/presupuesto', [ShowInicioController::class, 'showPortal'])
    ->name('presupuesto')
    ->defaults('type', 'presupuesto')
    ->middleware(['auth', 'permission:portal.view']);

Route::post('/usuarios', [UserController::class, 'store'])
    ->middleware(['auth', 'permission:users.manage', 'throttle:10,1'])
    ->name('usuarios.store');
Route::get('/verify-email/{id}/{token}', [UserController::class, 'verifyEmail'])
    ->middleware(['throttle:6,1'])
    ->name('verify.email');
Route::post('/usuarios/{id}/enviar-verificacion', [UserController::class, 'enviarVerificacion'])
    ->middleware(['auth', 'permission:users.manage', 'throttle:6,1'])
    ->name('usuarios.enviarVerificacion');

Route::post('/login', [AuthenticatedSessionController::class, 'store'])->middleware(['throttle:5,1']);
Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->middleware('auth')->name('logout');
Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
Route::get('/panel/davibank-converter', fn () => view('panel'))
    ->middleware(['auth', 'permission:accounting.bank-imports.create,imports.create', 'throttle:30,1'])
    ->name('davibank.converter');

/* Rutas públicas de inventarios */
Route::prefix('api/v1')->middleware(['auth', 'throttle:api'])->group(function () {
    Route::get('/stores', [InventoryImportController::class, 'stores'])
        ->middleware('permission:inventarios.importes');
    Route::post('/inventory/import', [InventoryImportController::class, 'import'])
        ->middleware('permission:inventarios.importes');
    Route::get('/inventory', [InventoryController::class, 'index'])
        ->middleware('permission:inventarios.cobertura');
    Route::post('/import-sales/start', [ImportSalesController::class, 'startChunked'])
        ->middleware('permission:imports.create');
    Route::post('/import-sales/chunk', [ImportSalesController::class, 'chunk'])
        ->middleware('permission:imports.create');
    Route::post('/catalog/import', [ProductCatalogImportController::class, 'import'])
        ->middleware('permission:imports.create');
    Route::post('/catalog/import/start', [ProductCatalogImportController::class, 'start'])
        ->middleware('permission:imports.create');
    Route::post('/catalog/import/chunk', [ProductCatalogImportController::class, 'chunk'])
        ->middleware('permission:imports.create');
    Route::post('/inventory/metrics/run', [InventoryMetricsController::class, 'run'])
        ->middleware('permission:inventarios.importes');
    Route::get('/inventory/latest', [InventoryMetricsController::class, 'latestInventory'])
        ->middleware('permission:inventarios.cobertura');
    Route::get('/inventory/metrics', [InventoryMetricsController::class, 'index'])
        ->middleware('permission:inventarios.cobertura');
    Route::post('/davibank/convert', [PublicDavibankConverterController::class, 'convert']);
});

/* Public candidate form */
Route::get('postular/{vacante}', [CandidatoController::class, 'formularioPostulacion'])
    ->where('vacante', '[A-Za-z0-9-]+')
    ->name('postular');
Route::post('postular/{slug}', [CandidatoController::class, 'store'])
    ->where('slug', '[A-Za-z0-9-]+')
    ->middleware(['throttle:5,1'])
    ->name('postular.store');
Route::get('/vervacantes/{localidad}', [VacanteController::class, 'vervacantes'])->name('vacantes.vacantes');
Route::post('/vacantes/{slug}/postulacion', [CandidatoController::class, 'store'])
    ->where('slug', '[A-Za-z0-9-]+')
    ->middleware(['throttle:5,1'])
    ->name('vacante.postular');
Route::get('/vacantes/{slug}', [VacanteController::class, 'show'])->name('vacantes.show');

/* 2FA */
Route::get('/2fa/setup', [TwoFactorController::class, 'enable'])
    ->middleware(['auth', 'throttle:3,1'])
    ->name('2fa.setup');
Route::get('/2fa/verify', [TwoFactorController::class, 'showVerifyForm'])
    ->middleware(['throttle:10,1'])
    ->name('2fa.verify');
Route::post('/2fa/verify', [TwoFactorController::class, 'verify'])
    ->middleware(['throttle:5,1'])
    ->name('2fa.verify.post');
Route::post('/2fa/setup', [TwoFactorController::class, 'setup'])
    ->middleware(['auth', 'throttle:5,1'])
    ->name('2fa.setup.post');

Route::get('/2fa-email/setup', [TwoFactorEmailController::class, 'showSetupForm'])
    ->middleware(['throttle:10,1'])
    ->name('2fa.email.setup');
Route::post('/email2fa/setup', [TwoFactorEmailController::class, 'setup'])
    ->middleware(['throttle:3,1'])
    ->name('email2fa.setup.post');
Route::post('/email2fa/verify', [TwoFactorEmailController::class, 'verify'])
    ->middleware(['throttle:5,1'])
    ->name('email2fa.verify.post');

/* Política */
Route::get('/politica-tratamiento', [VacanteController::class, 'politica-tratamiento'])->name('politica-tratamiento');

/* Conexion Segura para Node.js Importacion de ventas */



/* -------------------------------------------------------------------------- */
/* Protected web routes                                                        */
/* -------------------------------------------------------------------------- */

Route::middleware('auth')->group(function () {

    Route::post('/toggle', [ToggleController::class, 'store'])->name('toggle.store');

    /* ----------------------------- Importaciones --------------------------- */
    Route::post('/masivo/subircv', [CandidatoController::class, 'storeMasivo'])
        ->name('storeMasivo.subir')
        ->middleware(['permission:candidates.manage']);

    Route::get('/carga-masiva', [CandidatoController::class, 'subirAllCv'])
        ->name('subirAllCv')
        ->middleware(['permission:candidates.manage']);

    /* -------------------------------- Usuarios ---------------------------- */
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

    /* ------------------------- Candidatos / Vacantes ---------------------- */
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

    /* ------------------------- Disciplinas / Empleados -------------------- */
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
        ->middleware(['permission:disciplines.view']);

    Route::get('/descargar-pdf', [FormatoController::class, 'descargarPDF'])
        ->name('descargar.pdf')
        ->middleware(['permission:disciplines.view']);

    Route::get('/empleados', [ListController::class, 'mostrarEmpleados'])
        ->name('empleados.list')
        ->middleware(['permission:disciplines.view']);

    Route::get('/Disciplinas', [ListController::class, 'mostrarDisciplinasPositivas'])
        ->name('Disciplinas.list')
        ->middleware(['permission:disciplines.view']);

    Route::get('/DisciplinasUsers', [ListController::class, 'mostrarDisciplinasPositivasUsers'])
        ->name('Disciplinas.listUsers')
        ->middleware(['permission:disciplines.view']);

    Route::get('/empleados/export', [ListController::class, 'exportarEmpleadosExcel'])
        ->name('exportar.empleados')
        ->middleware(['permission:disciplines.view']);

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

    /* -------------------------------- Dashboard -------------------------- */
    Route::get('/dashboard', fn () => view('dashboard'))
        ->name('dashboard')
        ->middleware(['permission:panel.view']);

    /* ---------------------------------------------------------------------- */
    /* API v1                                                                  */
    /* ---------------------------------------------------------------------- */

    Route::prefix('api/v1')->middleware(['auth'])->group(function () {

    // Presupuesto Asesores Especializados

    Route::get('/advisor-budgets', [AdvisorBudgetController::class, 'show']);
    Route::post('/advisor-budgets', [AdvisorBudgetController::class, 'store']);

        /* ---------------------- Permissions / Roles ------------------------ */
        Route::get('/permissions', [PermissionController::class, 'permissions'])
            ->middleware('permission:permissions.view');

        Route::get('admin/users-with-permissions', [PermissionController::class, 'usersWithPermissions'])
            ->middleware('permission:permissions.view');

        // Lista de roles usada por el frontend
        Route::get('/roles', [RoleController::class, 'index'])
            ->middleware('permission:budget.manage');

        // Si necesitas exponer el catálogo de permisos, no lo mezcles con /roles
        Route::get('/permissions/roles', [PermissionController::class, 'roles'])
            ->middleware('permission:permissions.view');

        Route::post('/roles/{id}/permissions', [PermissionController::class, 'updateRolePermissions'])
            ->middleware('permission:permissions.manage');

        Route::post('/users/{id}/permissions', [PermissionController::class, 'updateUserPermissions'])
            ->middleware('permission:permissions.manage');

        Route::get('/users/{id}/permissions', [PermissionController::class, 'userPermissions'])
            ->middleware('permission:permissions.view');

        /* ------------------------------- Users ----------------------------- */
        Route::get('users', [ApiUserController::class, 'index'])
            ->middleware('permission:users.view');

        Route::get('manage/users', [ApiUserController::class, 'indexForManagedRoles'])
            ->middleware('permission:users.view');

        Route::post('manage/users', [ApiUserController::class, 'storeManagedUser'])
            ->middleware('permission:users.manage');

        Route::put('manage/users/{id}', [ApiUserController::class, 'updateManagedUser'])
            ->middleware('permission:users.manage');

        Route::delete('manage/users/{id}', [ApiUserController::class, 'destroyManagedUser'])
            ->middleware('permission:users.manage');

        Route::post('users/{id}/assign-role', [ApiUserController::class, 'assignRole'])
            ->middleware('permission:users.manage');

        /* ------------------------------ Reports ---------------------------- */
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

        Route::get('visualizaciones/cierre-caja', [VisualizationController::class, 'cashRegisterClosure'])
            ->middleware('permission:visualizations.view');

        Route::get('visualizaciones/ventas-tiendas', [VisualizationController::class, 'storeSalesSummary'])
            ->middleware('permission:visualizations.view');

        Route::get('visualizaciones/ventas-tiendas/whatsapp/preview', [VisualizationController::class, 'storeSalesWhatsappPreview'])
            ->middleware('permission:visualizations.view');

        Route::post('visualizaciones/ventas-tiendas/whatsapp/send', [VisualizationController::class, 'sendStoreSalesWhatsappReport'])
            ->middleware('permission:visualizations.view');

        Route::post('visualizaciones/ventas-tiendas/whatsapp/queue', [VisualizationController::class, 'queueStoreSalesWhatsappReport'])
            ->middleware('permission:visualizations.view');

        Route::get('visualizaciones/ventas-asesores', [VisualizationController::class, 'advisorSalesSummary'])
            ->middleware('permission:visualizations.view');

        Route::get('visualizaciones/ventas-asesores/whatsapp/preview', [VisualizationController::class, 'advisorSalesWhatsappPreview'])
            ->middleware('permission:visualizations.view');

        Route::post('visualizaciones/ventas-asesores/whatsapp/send', [VisualizationController::class, 'sendAdvisorSalesWhatsappReport'])
            ->middleware('permission:visualizations.view');

        Route::post('visualizaciones/ventas-asesores/whatsapp/queue', [VisualizationController::class, 'queueAdvisorSalesWhatsappReport'])
            ->middleware('permission:visualizations.view');

        Route::get('visualizaciones/daily-whatsapp/preview', [VisualizationController::class, 'whatsappDailyReportPreview'])
            ->middleware('permission:visualizations.view');

        Route::post('visualizaciones/daily-whatsapp/send', [VisualizationController::class, 'sendWhatsappDailyReport'])
            ->middleware('permission:visualizations.view');

        Route::post('visualizaciones/daily-whatsapp/queue', [VisualizationController::class, 'queueWhatsappDailyReport'])
            ->middleware('permission:visualizations.view');

        Route::post('visualizaciones/daily-whatsapp/send-to-recipients', [VisualizationController::class, 'sendWhatsappDailyNumberReport'])
            ->middleware('permission:visualizations.view');

        /* ------------------------------ Entregas --------------------------- */
        Route::middleware(['permission:entregas.view'])->group(function () {
            Route::get('entregas/categorias', [EntregaController::class, 'categorias']);
            Route::get('entregas/lideres', [EntregaController::class, 'lideres']);
            Route::get('entregas/empleados', [EntregaController::class, 'empleados']);
            Route::get('entregas/dashboard', [EntregaController::class, 'dashboard']);
            Route::get('entregas/me', [EntregaController::class, 'empleadoActual']);
            Route::get('entregas/export/resumen', [EntregaController::class, 'exportarResumen']);
            Route::get('entregas', [EntregaController::class, 'index']);
            Route::get('entregas/{id}', [EntregaController::class, 'show']);
            Route::get('entregas/{id}/pdf', [EntregaController::class, 'descargarPdf']);
            Route::get('entregas/{id}/pdf-view', [EntregaController::class, 'verPdf']);
            Route::get('empleados/{id}/firma', [EntregaController::class, 'obtenerFirmaEmpleado']);
        });

        Route::middleware(['permission:entregas.manage'])->group(function () {
            Route::post('entregas', [EntregaController::class, 'store']);
            Route::put('entregas/{id}', [EntregaController::class, 'update']);
            Route::delete('entregas/{id}', [EntregaController::class, 'destroy']);
            Route::post('entregas/{id}/firmar', [EntregaController::class, 'firmar']);
            Route::post('entregas/{id}/cerrar', [EntregaController::class, 'cerrarActa']);
            Route::post('entregas/{id}/rechazar', [EntregaController::class, 'rechazar']);
            Route::patch('entregas/{id}/novedades/{novedadId}', [EntregaController::class, 'actualizarNovedad']);
            Route::post('entregas/{id}/novedades/{novedadId}/observacion', [EntregaController::class, 'agregarObservacionNovedad']);
            Route::patch('entregas/{id}/novedades/{novedadId}/resuelto', [EntregaController::class, 'actualizarEstadoNovedad']);
            Route::post('empleados/{id}/firma', [EntregaController::class, 'guardarFirmaEmpleado']);
        });

        Route::post('/cashier-adjustments', [ReportController::class, 'storeCashierAdjustment'])
            ->middleware('permission:budget.cashier.manage');

        /* ------------------------------ Budgets ---------------------------- */
        Route::get('/budgets', [BudgetController::class, 'index'])
            ->middleware('permission:budget.view');

        Route::get('/budgets/active', [BudgetController::class, 'active'])
            ->middleware('permission:budget.view');

        Route::get('/commissions/by-seller/{userId}/export', [CommissionReportController::class, 'exportSellerDetail'])
            ->middleware('permission:budget.commissions.view');

        Route::get('/commissions/export', [CommissionReportController::class, 'exportExcel'])
            ->middleware('permission:budget.commissions.view');

        Route::get('reports/cashier-awards', [ReportController::class, 'cashierAwards'])
            ->middleware('permission:budget.cashier.view');

        Route::get('/reports/cashier-awards/export', [ReportController::class, 'cashierAwardsExport'])
            ->middleware('permission:budget.cashier.view');

        Route::post('/budgets', [BudgetController::class, 'store'])
            ->middleware('permission:budget.manage');

        Route::put('/budgets/{id}', [BudgetController::class, 'update'])
            ->middleware('permission:budget.manage');

        Route::delete('/budgets/{id}', [BudgetController::class, 'destroy'])
            ->middleware('permission:budget.manage');

        Route::patch('/budgets/{id}/cashier-prize', [BudgetController::class, 'updateCashierPrize'])
            ->middleware('permission:budget.cashier.manage');

        Route::post('/budgets/{id}/close', [BudgetController::class, 'close'])
            ->middleware('permission:budget.manage');

        /* ------------------------ Budget / Commissions --------------------- */
        Route::prefix('commissions')->group(function () {
            Route::get('/', [CommissionController::class, 'userSummary'])
                ->middleware('permission:budget.commissions.view');

            Route::get('/summary', [CommissionController::class, 'userSummary'])
                ->middleware('permission:budget.commissions.view');

            Route::post('/generate', [CommissionController::class, 'generate'])
                ->middleware('permission:budget.commissions.manage');

            Route::post('/rectify-sales-roles', [CommissionController::class, 'rectifySalesRoles'])
                ->middleware('permission:budget.commissions.manage');

            Route::post('/finalize', [CommissionController::class, 'finalize'])
                ->middleware('permission:budget.commissions.manage');

            Route::get('/my', [CommissionReportController::class, 'myCommissions'])
                ->middleware('permission:commissions.user.view');

            Route::get('/my/export', [CommissionReportController::class, 'myExport'])
                ->middleware('permission:commissions.user.view');

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
                ->middleware('permission:budget.leader.view');

            Route::post('/', [CommissionLideres::class, 'storeLeader'])
                ->middleware('permission:budget.leader.manage');

            Route::put('/{id}', [CommissionLideres::class, 'updateLeader'])
                ->middleware('permission:budget.leader.manage');

            Route::delete('/{id}', [CommissionLideres::class, 'destroyLeader'])
                ->middleware('permission:budget.leader.manage');

            Route::get('/{id}/absences', [CommissionLideres::class, 'listAbsences'])
                ->middleware('permission:budget.leader.view');

            Route::post('/{id}/absences', [CommissionLideres::class, 'addAbsence'])
                ->middleware('permission:budget.leader.manage');

            Route::delete('/{id}/absences/{aid}', [CommissionLideres::class, 'deleteAbsence'])
                ->middleware('permission:budget.leader.manage');

            Route::get('/config', [CommissionLideres::class, 'getConfig'])
                ->middleware('permission:budget.leader.view');

            Route::post('/config', [CommissionLideres::class, 'saveConfig'])
                ->middleware('permission:budget.leader.manage');

            Route::post('/calculate', [CommissionLideres::class, 'calculateCommissions'])
                ->middleware('permission:budget.leader.manage');

            Route::post('/save-store-split', [CommissionLideres::class, 'saveStoreSplit'])
                ->middleware('permission:budget.leader.manage');
        });

        /* ------------------------------ Advisors --------------------------- */
        Route::prefix('advisors')->group(function () {
            Route::get('cashier-awards', [AdvisorController::class, 'cashierAwards'])
                ->middleware('permission:budget.cashier.view');

            Route::get('cashier/{userId}/categories', [AdvisorController::class, 'cashierCategories'])
                ->middleware('permission:budget.cashier.view');

            Route::get('specialistCheck', [AdvisorController::class, 'specialistCheck'])
                ->middleware(['permission:commissions.asesorSpecialist.view']);

            Route::get('budget-sellers', [AdvisorController::class, 'budgetSellers'])
                ->middleware('permission:budget.advisors.view');

            Route::get('active-sales', [AdvisorController::class, 'activeSpecialistsSales'])
                ->middleware('permission:budget.specialists.view');

            Route::get('split-pool', [AdvisorController::class, 'splitAdvisorPool'])
                ->middleware('permission:budget.advisors.view');

            Route::get('get-split', [AdvisorController::class, 'getAdvisorSplit'])
                ->middleware('permission:budget.advisors.view');

            Route::post('save-split', [AdvisorController::class, 'saveAdvisorSplit'])
                ->middleware('permission:budget.advisors.manage');

            Route::get('category-budgets', [AdvisorController::class, 'indexCategoryBudgets'])
                ->middleware('permission:budget.advisors.view');

            Route::post('category-budgets', [AdvisorController::class, 'upsertCategoryBudget'])
                ->middleware('permission:budget.advisors.manage');

            Route::post('category-budgets/bulk', [AdvisorController::class, 'bulkUpsert'])
                ->middleware('permission:budget.advisors.manage');

            Route::delete('category-budgets/{id}', [AdvisorController::class, 'deleteCategoryBudget'])
                ->middleware('permission:budget.advisors.manage');

            Route::post('specialists', [AdvisorController::class, 'assignSpecialist'])
                ->middleware('permission:budget.specialists.manage');

            Route::get('specialists', [AdvisorController::class, 'getSpecialistsForBudget'])
                ->middleware('permission:budget.specialists.view');
        });

        /* ------------------------------ Wishlist ------------------------------ */
        Route::get('catalog/categories', [WishItemController::class, 'categories'])
            ->middleware('permission:wishlist.view');

        Route::get('catalog-products', [WishItemController::class, 'searchCatalog'])
            ->middleware('permission:wishlist.view');

        Route::get('wish-items', [WishItemController::class, 'listWishItems'])
            ->middleware('permission:wishlist.view');

        Route::get('wish-items/stats', [WishItemController::class, 'stats'])
            ->middleware('permission:wishlist.view');

        Route::get('wish-items/selections', [WishItemController::class, 'selectionsList'])
            ->middleware('permission:wishlist.view');

        Route::get('users/sellers', [WishItemController::class, 'sellers'])
            ->middleware('permission:wishlist.view');

        Route::post('wish-items', [WishItemController::class, 'create'])
            ->middleware('permission:wishlist.view');

        Route::post('wish-items/select', [WishItemController::class, 'select'])
            ->middleware('permission:wishlist.view');

        Route::patch('wish-items/{id}', [WishItemController::class, 'update'])
            ->middleware('permission:wishlist.view');

        Route::get('me', [WishItemController::class, 'me']);

        /* ------------------------ Inventory alerts ------------------------ */
        Route::middleware(['permission:inventarios.alertas'])->prefix('inventory-alerts')->group(function () {
            Route::get('/', [InventoryAlertController::class, 'index']);
            Route::get('/history', [InventoryAlertController::class, 'history']);
            Route::get('/filter-options', [InventoryAlertController::class, 'filterOptions']);
            Route::get('/products', [InventoryAlertController::class, 'products']);
            Route::post('/top', [InventoryAlertController::class, 'top']);
            Route::get('/{id}/current-alerts', [InventoryAlertController::class, 'current']);
            Route::get('/{id}', [InventoryAlertController::class, 'show']);
        });

        Route::middleware(['permission:inventarios.alertas'])->prefix('inventory-alerts')->group(function () {
            Route::post('/', [InventoryAlertController::class, 'store']);
            Route::put('/{id}', [InventoryAlertController::class, 'update']);
            Route::delete('/{id}', [InventoryAlertController::class, 'destroy']);
            Route::post('/{id}/top', [InventoryAlertController::class, 'addTop']);
            Route::post('/{id}/products', [InventoryAlertController::class, 'addProduct']);
            Route::delete('/{id}/products/{productId}', [InventoryAlertController::class, 'removeProduct']);
            Route::post('/{id}/send', [InventoryAlertController::class, 'send']);
            Route::post('/{id}/test', [InventoryAlertController::class, 'test']);
        });

        /* -------------------------- Imports / batches ---------------------- */
        Route::middleware(['permission:imports.create'])->group(function () {
            Route::post('import-turns', [TurnsImportController::class, 'import']);
            Route::post('import-sales', [ImportSalesController::class, 'import']);
            Route::get('imports/turns', [TurnsImportController::class, 'index']);
            Route::get('imports', [ImportBatchController::class, 'index']);
        });

        Route::get('bank-imports', [BankImportBatchController::class, 'index'])
            ->middleware('permission:accounting.bank-imports.view,imports.create');
        Route::post('bank-imports/import', [BankImportBatchController::class, 'import'])
            ->middleware('permission:accounting.bank-imports.create,imports.create');
        Route::get('bank-imports/movements/audit', [BankImportBatchController::class, 'movementsAudit'])
            ->middleware('permission:accounting.bank-imports.view,imports.create');
        Route::get('bank-imports/movements/export', [BankImportBatchController::class, 'exportMovements'])
            ->middleware('permission:accounting.bank-imports.export,imports.manage');
        Route::get('bank-imports/movements', [BankImportBatchController::class, 'movements'])
            ->middleware('permission:accounting.bank-imports.view,imports.create');
        Route::get('bank-imports/{id}', [BankImportBatchController::class, 'show'])
            ->middleware('permission:accounting.bank-imports.view,imports.manage');
        Route::get('bank-imports/{id}/export', [BankImportBatchController::class, 'export'])
            ->middleware('permission:accounting.bank-imports.export,imports.manage');
        Route::get('bank-imports/{id}/export-davibank', [BankImportBatchController::class, 'exportDavibank'])
            ->middleware('permission:accounting.bank-imports.export,imports.manage');
        Route::delete('bank-imports/{id}', [BankImportBatchController::class, 'destroy'])
            ->middleware('permission:accounting.bank-imports.manage,imports.manage');
        Route::post('bank-imports/bulk-delete', [BankImportBatchController::class, 'bulkDestroy'])
            ->middleware('permission:accounting.bank-imports.manage,imports.manage');

        Route::middleware(['permission:imports.manage'])->group(function () {
            Route::get('imports/turns/{id}', [TurnsImportController::class, 'show']);
            Route::delete('imports/turns', [TurnsImportController::class, 'bulkDelete']);
            Route::delete('imports/turns/{id}', [TurnsImportController::class, 'deleteBatch']);

            Route::delete('imports/{id}', [ImportBatchController::class, 'destroy']);
            Route::get('imports/{id}', [ImportBatchController::class, 'show']);
            Route::post('imports/bulk-delete', [ImportBatchController::class, 'bulkDestroy']);
        });
    });

    /* ---------------------------------------------------------------------- */
    /* Panel SPA routes                                                        */
    /* ---------------------------------------------------------------------- */
    Route::get('/panel', fn () => view('panel'))
        ->middleware(['permission:panel.view']);

    Route::get('/panel/users', fn () => view('panel'))
        ->middleware(['permission:users.view']);

    Route::get('/panel/CatalogMatchPage', fn () => view('panel'))
        ->middleware(['permission:wishlist.view']);

    Route::get('/panel/ImportsManagerPage', fn () => view('panel'))
        ->middleware(['permission:imports.create']);

    Route::get('/panel/BankImportsManagerPage', fn () => view('panel'))
        ->middleware(['permission:accounting.bank-imports.view,imports.create']);
    Route::get('/panel/BankMovementsPage', fn () => view('panel'))
        ->middleware(['permission:accounting.bank-imports.view,imports.create']);

    Route::get('/panel/budget', fn () => view('panel'))
        ->middleware(['permission:budget.admin.view']);

    Route::get('/panel/CommissionCardsPage', fn () => view('panel'))
        ->middleware(['permission:budget.commissions.view']);

    Route::get('/panel/CashierAwards', fn () => view('panel'))
        ->middleware(['permission:budget.cashier.view']);

    Route::get('/panel/CashierAwardsUsers', fn () => view('panel'))
        ->middleware(['permission:budget.cashier.view']);

    Route::get('/panel/commissions/categories', fn () => view('panel'))
        ->middleware(['permission:budget.commissions.manage']);

    Route::get('/panel/commissions/SpecialistCommissionsPanel', fn () => view('panel'))
        ->middleware(['permission:commissions.asesorSpecialist.view']);

    Route::get('/panel/commissions/DualCommissionAdmin', fn () => view('panel'))
        ->middleware(['permission:budget.commissions.manage']);

    Route::get('/panel/inventarios/cobertura', fn () => view('panel'))
        ->middleware(['permission:inventarios.cobertura']);

    Route::get('/panel/inventarios/alertas', fn () => view('panel'))
        ->middleware(['permission:inventarios.alertas']);

    Route::get('/panel/InventoryImportsManagerPage', fn () => view('panel'))
        ->middleware(['permission:inventarios.importes']);

    Route::get('/panel/inventarios/rotacion', fn () => abort(404));

    Route::get('/panel/visualizaciones', fn () => view('panel'))
        ->middleware(['permission:visualizations.view']);

    Route::get('/panel/visualizaciones/cierre-caja', fn () => view('panel'))
        ->middleware(['permission:visualizations.view']);

    Route::get('/panel/visualizaciones/daily-sales', fn () => view('panel'))
        ->middleware(['permission:visualizations.view']);

    Route::get('/panel/visualizaciones/ventas-tiendas', fn () => view('panel'))
        ->middleware(['permission:visualizations.view']);

    Route::get('/panel/visualizaciones/ventas-asesores', fn () => view('panel'))
        ->middleware(['permission:visualizations.view']);

    Route::get('/panel/entregas/{any?}', fn () => view('panel'))
        ->where('any', '.*')
        ->middleware(['permission:entregas.view']);

    Route::get('/panel/EntregasDashboardPage', fn () => view('panel'))
        ->middleware(['permission:entregas.view']);

    Route::get('/panel/CrearEntregaPage', fn () => view('panel'))
        ->middleware(['permission:entregas.manage']);

    Route::get('/panel/DetalleEntregaPage', fn () => view('panel'))
        ->middleware(['permission:entregas.view']);

    Route::get('/panel/ListadoEntregasPage', fn () => view('panel'))
        ->middleware(['permission:entregas.view']);

    Route::get('/panel/CommisionsUser', fn () => view('panel'))
        ->middleware(['permission:commissions.user.view']);

    Route::get('/panel/AdminWishList', fn () => view('panel'))
        ->middleware(['permission:wishlist.manage']);

    Route::get('/panel/advisors', fn () => view('panel'))
        ->middleware(['permission:budget.advisors.view']);

    Route::get('/panel/specialists', fn () => view('panel'))
        ->middleware(['permission:budget.specialists.view']);

    Route::get('/panel/{any?}', fn () => view('panel'))
        ->where('any', '.*')
        ->middleware(['permission:panel.view']);





    /* ---------------------------------------------------------------------- */
    /* Otros endpoints web                                                    */
    /* ---------------------------------------------------------------------- */

    Route::post('/llamados/importar', [FormatoController::class, 'importarExcel'])
        ->name('llamados.importar')
        ->middleware(['permission:imports.create']);

    Route::get('/api/firmas', function () {
        return response()->json([
            'empleado' => session('firma_empleado'),
            'jefe'     => session('firma_jefe'),
            'proceso'  => session('Proceso'),
        ]);
    })->middleware(['permission:panel.view']);
});

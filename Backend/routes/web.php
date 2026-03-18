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
use App\Http\Controllers\ShowPresupuesto;
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
| Aquí van las rutas que usan sesión/cookies (login Laravel normal).
| Ahora las rutas usan middleware 'auth' + 'permission:...' para controlar
| accesos mediante permisos en DB (ej: users.view, users.manage, panel.view).
|
*/

/*
  Nota sobre mapeo de permisos (ajusta según tu tabla `permissions`):
  - panel.view        : permiso para ver el panel (acceso frontend SPA)
  - users.view        : ver lista/usuarios
  - users.manage      : crear/editar/borrar usuarios
  - permissions.view  : ver permisos/roles
  - permissions.manage: editar roles/permissions
  - candidates.*      : candidatos.view / candidates.manage / candidates.export
  - vacancies.*       : vacancies.view / vacancies.manage
  - disciplines.*     : disciplines.view / disciplines.manage
  - imports.*         : imports.create / imports.manage
  - budgets.*         : budgets.view / budgets.manage
  - commissions.*     : commissions.view / commissions.manage
  - reports.view      : ver reportes
  - advisors.*        : advisors.view / advisors.manage
  - wishlist.*        : wishlist.view / wishlist.manage
*/

Route::get('/', function () { return view('home'); })->name('home');

// welcome - requiere sesión y permiso para ver portal/panel (panel.view)
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

// Login / Logout (Breeze / Fortify style)
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


/* ---------- Protected web routes (session) ---------- */
Route::middleware('auth')->group(function () {

    // Carga masiva CVs (necesita permiso imports.create o imports.manage)
    Route::post('/masivo/subircv', [CandidatoController::class, 'storeMasivo'])
        ->name('storeMasivo.subir')
        ->middleware(['permission:imports.create,imports.manage']); // admite quien tenga cualquiera de esos permisos

    Route::get('/carga-masiva', [CandidatoController::class, 'subirAllCv'])
        ->name('subirAllCv')
        ->middleware(['permission:imports.manage']);

    // Usuarios (web UI)
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
         ->middleware(['can:admin'])->name('photos.store'); // este usa gate 'can:admin' existente


    // Candidatos / Vacantes protected views (mapear a permisos candidates.* y vacancies.*)
    Route::get('/candidatos/{slug}/export', [CandidatoController::class, 'export'])
        ->middleware(['permission:candidates.export'])->name('candidatos.export');

    Route::get('/candidatos/{id}/cv', [CandidatoController::class, 'descargarCV'])
        ->middleware(['permission:candidates.view'])->name('candidatos.cv');

    Route::post('/candidatos/{id}/correo', [CandidatoController::class, 'enviarCorreo'])
        ->middleware(['permission:candidates.manage'])->name('candidatos.correo');

    Route::resource('candidatos', CandidatoController::class)
        ->middleware(['permission:candidates.manage']);

    Route::post('/candidatos/{candidato}/rechazar', [CandidatoController::class, 'rechazar'])
        ->middleware(['permission:candidates.manage'])->name('candidatos.rechazar');

    Route::post('/candidatos/{candidato}/aprobar', [CandidatoController::class, 'aprobar'])
        ->middleware(['permission:candidates.manage'])->name('candidatos.aprobar');

    Route::post('/toggle-state', [ToggleController::class, 'store'])
        ->middleware(['permission:panel.view'])->name('toggle.store');

    Route::get('/panel/candidatos', [CandidatoController::class, 'mostrarCandidatos'])
        ->middleware(['permission:candidates.view'])->name('panel.candidatos');

    Route::get('/candidatos/{slug}/aprobados', [CandidatoController::class, 'showaprobados'])
        ->middleware(['permission:candidates.view'])->name('candidatos.aprobados.list');

    Route::get('/candidatos/{slug}', [CandidatoController::class, 'show'])
        ->middleware(['permission:candidates.view'])->name('candidatos.show');

    Route::get('/candidatos/{slug}/rechazados', [CandidatoController::class, 'showrechazados'])
        ->middleware(['permission:candidates.view'])->name('candidatos.rechazados.list');

    // Vacantes routes (protected)
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
        ->middleware(['permission:vacancies.manage']);

    Route::get('/enviar-email', [UserController::class, 'enviarVerificacion'])
        ->middleware(['permission:users.manage'])->name('enviarVerificacion');


    /* ---------- Disciplinas / Empleados / PDFs ---------- */
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

    Route::get('/dashboard', fn() => view('dashboard'))->name('dashboard')
        ->middleware(['permission:panel.view']);

    /*
     | Permissions & Roles API
     | - lectura de permisos: permissions.view
     | - gestión de permisos/roles: permissions.manage
     */
    Route::prefix('api/v1')->middleware(['auth'])->group(function () {

        // leer permisos: cualquiera con permission:permissions.view
        Route::get('/permissions', [PermissionController::class, 'permissions'])
    ->middleware('permission:permissions.view');


    Route::get('admin/users-with-permissions',
        [PermissionController::class, 'usersWithPermissions']
    )->middleware(['auth:web', 'permission:permissions.view']);

        // roles info: permisos de roles (lectura)
        Route::get('/roles', [PermissionController::class, 'roles'])
            ->middleware('permission:permissions.view');

        // actualizar permisos en rol -> gestionar permisos
        Route::post('/roles/{id}/permissions', [PermissionController::class, 'updateRolePermissions'])
            ->middleware('permission:permissions.manage');

        // actualizar permisos por usuario -> gestionar permisos
        Route::post('/users/{id}/permissions', [PermissionController::class, 'updateUserPermissions'])
            ->middleware('permission:permissions.manage');

        // obtener permisos de un usuario (frontend)
        Route::get('/users/{id}/permissions', [PermissionController::class, 'userPermissions'])
            ->middleware('permission:permissions.view');

    });

    // RUTAS PARA WHISH LIST (catalog import) - permiso wishlist.manage
    Route::prefix('api/v1')->group(function () {
        Route::post('/catalog/import', [CatalogController::class, 'import'])
            ->middleware(['auth','permission:wishlist.manage']); // si es desde frontend sesión
    });

    /*
     | Grupo de rutas "operacionales" - acceso controlado por permisos relacionados
     | (ventas / cajeros / vendedores / lideres / admin)
     */
    Route::prefix('api/v1')
        ->middleware(['auth'])
        ->group(function () {

        // accesos generales de vendedores / cajeros / admins: se controlan por permisos concretos
        Route::get('advisors/cashier-awards', [AdvisorController::class, 'cashierAwards'])
            ->middleware('permission:reports.view');

        Route::get('advisors/cashier/{userId}/categories', [AdvisorController::class, 'cashierCategories'])
            ->middleware('permission:reports.view');

        Route::get('advisors/specialistCheck', [AdvisorController::class, 'specialistCheck'])
            ->middleware('permission:advisors.view');

        Route::get('commissions/my', [AdvisorController::class, 'myCommissions'])
            ->middleware('permission:commissions.view');

        Route::get('commissions/my/export', [AdvisorController::class, 'exportMyCommissions'])
            ->middleware('permission:commissions.view');

        Route::get('commissions/category-commissions/overrides', [AdvisorController::class, 'getCommissionOverrides'])
            ->middleware('permission:commissions.manage');

        Route::get('advisors/budget-sellers', [AdvisorController::class, 'budgetSellers'])
            ->middleware('permission:advisors.view');

        Route::post('commissions/category-commissions/overrides', [AdvisorController::class, 'saveCommissionOverrides'])
            ->middleware('permission:commissions.manage');

        Route::get('advisors/active-sales', [AdvisorController::class, 'activeSpecialistsSales'])
            ->middleware('permission:commissions.view');

        Route::get('advisors/split-pool', [AdvisorController::class, 'splitAdvisorPool'])
            ->middleware('permission:advisors.view');

        Route::get('advisors/get-split', [AdvisorController::class, 'getAdvisorSplit'])
            ->middleware('permission:advisors.view');

        Route::post('advisors/save-split', [AdvisorController::class, 'saveAdvisorSplit'])
            ->middleware('permission:advisors.manage');

        Route::get('sales', [SalesByUserController::class, 'getSalesByUser'])
            ->middleware('permission:reports.view');

        Route::get('reports/cashier/{userId}/categories', [ReportController::class, 'cashierCategories'])
            ->middleware('permission:reports.view');

        // catálogo
        Route::get('catalog/categories', [WishItemController::class, 'categories'])
            ->middleware('permission:wishlist.view');

        Route::get('catalog-products', [WishItemController::class, 'searchCatalog'])
            ->middleware('permission:wishlist.view');

        // wish items
        Route::get('wish-items', [WishItemController::class, 'listWishItems'])
            ->middleware('permission:wishlist.view');

        Route::post('wish-items', [WishItemController::class, 'create'])
            ->middleware('permission:wishlist.manage');

        Route::post('wish-items/select', [WishItemController::class, 'select'])
            ->middleware('permission:wishlist.view');

        Route::get('users/sellers', [WishItemController::class, 'sellers'])
            ->middleware('permission:wishlist.view');

        Route::get('me', [WishItemController::class, 'me'])
            ->middleware('permission:panel.view');
    });

    // Rutas reservadas a gestores (admins / superadmin) - mapear a permisos de administración
    Route::prefix('api/v1')
        ->middleware(['auth','permission:admin.view']) // admin.view es comodín; puede mapearse a varios permisos
        ->group(function () {

        Route::patch('wish-items/{id}', [WishItemController::class, 'update'])
            ->middleware('permission:wishlist.manage');

        Route::get('wish-items/stats', [WishItemController::class, 'stats'])
            ->middleware('permission:wishlist.view');

        Route::get('wish-items/selections', [\App\Http\Controllers\Api\WishItemController::class, 'selectionsList'])
            ->middleware('permission:wishlist.view');

    });

    /*
     | Panel routes (front-end SPA) — controlados por permission:panel.view
     | Puedes afinar por sub-ruta (ej: budgets.view) si quieres controles más granulares.
     */
    Route::middleware(['permission:panel.view'])->group(function () {

    Route::get('/panel', fn() => view('panel'))
        ->middleware('permission:budget.view');


    Route::get('/panel/users', fn() => view('panel'))
        ->middleware('permission:users.view');

    Route::get('/panel/ImportsManagerPage', fn() => view('panel'))
        ->middleware('permission:imports.manage');

    Route::get('/panel/budget', fn() => view('panel'))
        ->middleware('permission:budget.view');

    Route::get('/panel/CommissionCardsPage', fn() => view('panel'))
        ->middleware('permission:commissions.view');

    Route::get('/panel/CashierAwards', fn() => view('panel'))
        ->middleware('permission:budget.cashier.view');

    Route::get('/panel/commissions/categories', fn() => view('panel'))
        ->middleware('permission:commissions.manage');

    Route::get('/panel/CommisionsUser', fn() => view('panel'))
        ->middleware('permission:commissions.view');

    Route::get('/panel/CashierAwardsUsers', fn() => view('panel'))
        ->middleware('permission:budget.cashier.view');

    Route::get('/panel/AdminWishList', fn() => view('panel'))
        ->middleware('permission:wishlist.manage');

    Route::get('/panel/{any?}', fn() => view('panel'))
        ->where('any', '.*');
});

    /*
     | Session-backed "API" endpoints (used by React)
     | Estas rutas necesitan sesión (auth) y luego permisos según la acción.
     */
    Route::prefix('api/v1')->group(function () {

        // Rutas para gestionar usuarios limitadas a administradores/gestores
        Route::middleware(['permission:users.manage'])->group(function () {
            // Listar usuarios filtrados por los roles permitidos
            Route::get('manage/users', [ApiUserController::class, 'indexForManagedRoles']);
            // Crear usuario (solo roles permitidos)
            Route::post('manage/users', [ApiUserController::class, 'storeManagedUser']);
            Route::delete('/manage/users/{id}', [ApiUserController::class, 'destroyManagedUser']);
            // Actualizar usuario
            Route::put('manage/users/{id}', [ApiUserController::class, 'updateManagedUser']);
            Route::get('reports/advisors-split', [CommissionReportController::class, 'advisorsSplit'])
                ->middleware('permission:reports.view');
            Route::post('/budgets/{id}/close', [BudgetController::class, 'close'])
                ->middleware('permission:budgets.manage');
        });

        // IMPORTS (permitir que lider importe sin tener todos los permisos admin)
        Route::middleware(['permission:imports.create'])->group(function () {
            Route::post('import-turns', [TurnsImportController::class, 'import']);
            Route::post('import-sales', [ImportSalesController::class, 'import']);
        });

        // Seller-only: obtener mis comisiones
        Route::middleware(['permission:commissions.view'])->group(function () {
            Route::get('/commissions/my', [CommissionReportController::class, 'myCommissions']);
            Route::get('/commissions/my/export', [CommissionReportController::class, 'myExport']);
        });

        // Budgets: lectura para roles con permiso budgets.view
        Route::middleware(['permission:budgets.view'])->group(function () {
            Route::get('/commissions/by-seller/{userId}/export', [CommissionReportController::class, 'exportSellerDetail']);
            Route::get('/commissions/export', [CommissionReportController::class, 'exportExcel']);
        });

        // Budgets: lectura para quien vea budgets / reports
        Route::middleware(['permission:budgets.view'])->group(function () {
            Route::get('/budgets', [BudgetController::class, 'index']);
            Route::get('/budgets/active', [BudgetController::class, 'active']);
            Route::get('reports/cashier-awards', [ReportController::class, 'cashierAwards']);
            Route::get('/reports/cashier-awards/export', [ReportController::class, 'cashierAwardsExport']);
        });

        // Rutas administrativas más pesadas (mapeadas a permisos específicos)
        Route::middleware(['permission:commissions.manage'])->group(function () {
            Route::get('/commissions/by-seller', [CommissionReportController::class, 'bySeller']);
            Route::get('/commissions/by-seller/{userId}', [CommissionReportController::class, 'bySellerDetail']);
            Route::put('commissions/assign-turns/{userId}/{budget_id}', [CommissionReportController::class, 'assignTurns']);
            Route::post('commissions/assign-turns/{userId}/{budget_id}', [CommissionReportController::class, 'assignTurns']);
        });

        // IMPORT / MANAGEMENT (admin)
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

        // Admin-only endpoints (manage budgets, users, turnos listing, imports management, etc.)
        Route::middleware(['permission:admin.view'])->group(function () {

            // USERS & ROLES (admin UI)
            Route::get('users', [ApiUserController::class, 'index']);
            Route::post('users/{id}/assign-role', [ApiUserController::class, 'assignRole'])->middleware('permission:users.manage');
            Route::get('roles', [RoleController::class, 'index'])->middleware('permission:permissions.view');

            // SALES
            Route::get('sales/users', [SalesByUserController::class, 'getUsersWithSales'])->middleware('permission:reports.view');
            Route::get('sales/by-user', [SalesByUserController::class, 'getSalesByUser'])->middleware('permission:reports.view');

            // COMMISSIONS – LOGIC (admin)
            Route::post('commissions/recalc-sale/{id}', [CommissionController::class,'recalcSale'])->middleware('permission:commissions.manage');
            Route::post('commissions/recalc-user/{userId}/{month}', [CommissionController::class,'recalcUserMonth'])->middleware('permission:commissions.manage');
            Route::get('commissions/summary', [CommissionController::class,'userSummary'])->middleware('permission:commissions.view');
            Route::post('commissions/finalize', [CommissionController::class,'finalize'])->middleware('permission:commissions.manage');

            // cashier-awards
            Route::post('/cashier-adjustments', [ReportController::class,'storeCashierAdjustment'])->middleware('permission:reports.view');

            // Commission config (admin)
            Route::get('commissions/categories', [CategoryCommissionController::class, 'index'])->middleware('permission:commissions.view');
            Route::post('commissions/categories', [CategoryCommissionController::class, 'upsert'])->middleware('permission:commissions.manage');
            Route::delete('commissions/categories/{id}', [CategoryCommissionController::class, 'destroy'])->middleware('permission:commissions.manage');
            Route::post('commissions/categories/bulk', [CategoryCommissionController::class, 'bulkUpdate'])->middleware('permission:commissions.manage');
            Route::post('/commissions/generate', [CommissionController::class, 'generate'])->middleware('permission:commissions.manage');

            // Budgets management (admin)
            Route::post('/budgets', [BudgetController::class, 'store'])->middleware('permission:budgets.manage');
            Route::put('/budgets/{id}', [BudgetController::class, 'update'])->middleware('permission:budgets.manage');
            Route::delete('/budgets/{id}', [BudgetController::class, 'destroy'])->middleware('permission:budgets.manage');
            Route::patch('/budgets/{id}/cashier-prize', [BudgetController::class, 'updateCashierPrize'])->middleware('permission:budgets.manage');

            // Commission leaders endpoints
            Route::get('/commission-leaders', [CommissionLideres::class, 'index'])->middleware('permission:commissions.view');
            Route::post('/commission-leaders', [CommissionLideres::class, 'storeLeader'])->middleware('permission:commissions.manage');
            Route::put('/commission-leaders/{id}', [CommissionLideres::class, 'updateLeader'])->middleware('permission:commissions.manage');
            Route::delete('/commission-leaders/{id}', [CommissionLideres::class, 'destroyLeader'])->middleware('permission:commissions.manage');

            Route::get('/commission-leaders/{id}/absences', [CommissionLideres::class, 'listAbsences'])->middleware('permission:commissions.view');
            Route::post('/commission-leaders/{id}/absences', [CommissionLideres::class, 'addAbsence'])->middleware('permission:commissions.manage');
            Route::delete('/commission-leaders/{id}/absences/{aid}', [CommissionLideres::class, 'deleteAbsence'])->middleware('permission:commissions.manage');

            Route::get('/commission-leaders/config', [CommissionLideres::class, 'getConfig'])->middleware('permission:commissions.view');
            Route::post('/commission-leaders/config', [CommissionLideres::class, 'saveConfig'])->middleware('permission:commissions.manage');

            Route::post('/commission-leaders/calculate', [CommissionLideres::class, 'calculateCommissions'])->middleware('permission:commissions.manage');
            Route::post('/commissions/save-store-split', [CommissionLideres::class, 'saveStoreSplit'])->middleware('permission:commissions.manage');
            Route::get('/commissions/store-split/{budgetId}', [CommissionLideres::class, 'getStoreSplit'])->middleware('permission:commissions.view');

            // Advisors
            Route::prefix('advisors')->group(function () {
                Route::get('category-budgets', [AdvisorController::class, 'indexCategoryBudgets'])->middleware('permission:advisors.view');
                Route::post('category-budgets', [AdvisorController::class, 'upsertCategoryBudget'])->middleware('permission:advisors.manage');
                Route::post('category-budgets/bulk', [AdvisorController::class, 'bulkUpsert'])->middleware('permission:advisors.manage');
                Route::delete('category-budgets/{id}', [AdvisorController::class, 'deleteCategoryBudget'])->middleware('permission:advisors.manage');

                Route::post('specialists', [AdvisorController::class, 'assignSpecialist'])->middleware('permission:advisors.manage');
                Route::get('specialists', [AdvisorController::class, 'getSpecialistsForBudget'])->middleware('permission:advisors.view');
            });

        }); // end admin.view group

    }); // end api/v1 / session-backed endpoints

    // Otros endpoints web que requieren auth...
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

}); // end auth group
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

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Aquí van las rutas que usan sesión/cookies (login Laravel normal).
| He añadido el prefijo /api/v1 pero usando middleware 'auth' (session-based).
|
*/

/* ---------- Public / Auth ---------- */
Route::get('/', function () { return view('home'); })->name('home');

//Route::get('/welcome', [ShowInicioController::class, 'showWelcome'])
//    ->name('welcome')
//    ->middleware(['auth', 'ensure.role:user_disciplina,super_admin,admin,user,user_portal,seller']);
Route::get('/welcome', [ShowInicioController::class, 'showWelcome'])
    ->name('welcome')
    ->middleware(['auth', 'ensure.role:user_disciplina,super_admin,admin,user,user_portal,seller,adminpresupuesto,cashier,lider']);

Route::get('/presupuesto', [ShowInicioController::class, 'showPortal'])
    ->name('presupuesto')
    ->defaults('type', 'presupuesto')
    ->middleware(['auth', 'ensure.role:user_disciplina,super_admin,admin,user,user_portal,seller,adminpresupuesto,cashier,lider']);

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

    // Carga masiva CVs (roles específicos)
    Route::post('/masivo/subircv', [CandidatoController::class, 'storeMasivo'])
        ->name('storeMasivo.subir')->middleware(['ensure.role:user_portal,super_admin,admin']);
    Route::get('/carga-masiva', [CandidatoController::class, 'subirAllCv'])->name('subirAllCv');

    // Usuarios (web UI)
    Route::get('/usuarios/crear', [UserController::class, 'create'])
        ->middleware('ensure.role:super_admin')->name('usuarios.create');

    Route::post('users', [UserController::class, 'store'])
        ->middleware('ensure.role:super_admin')->name('users.store');

    Route::get('/view-users', [UserController::class, 'index'])
        ->middleware('ensure.role:super_admin')->name('view-users');

    Route::get('/users/{user}/ver_user', [UserController::class, 'verusuario'])
        ->middleware('ensure.role:super_admin')->name('ver_user');

    Route::get('/users/ver_perfil', [UserController::class, 'verperfil'])->name('ver_perfil');

    Route::get('users', [UserController::class, 'index'])
        ->middleware('ensure.role:super_admin')->name('users.index');

    Route::get('/users/export', [UserExportController::class, 'export'])
        ->middleware('ensure.role:super_admin')->name('users.export');

    Route::delete('/users/{user}', [UserController::class, 'destroy'])
        ->middleware('ensure.role:super_admin')->name('users.destroy');

    Route::get('/users/{id}/edit', [UserController::class, 'edit'])
        ->middleware('ensure.role:super_admin')->name('users.edit');
    Route::put('/users/{id}', [UserController::class, 'update'])
        ->middleware('ensure.role:super_admin')->name('users.update');

    Route::get('/photo/{id}', [PhotoController::class, 'show'])
        ->middleware('ensure.role:super_admin')->name('photo.show');

    Route::post('photos', [PhotoController::class, 'store'])
         ->middleware('can:admin')->name('photos.store');


    // Candidatos / Vacantes protected views
    Route::get('/candidatos/{slug}/export', [CandidatoController::class, 'export'])
        ->middleware('ensure.role:user_portal,super_admin,admin')->name('candidatos.export');

    Route::get('/candidatos/{id}/cv', [CandidatoController::class, 'descargarCV'])
        ->middleware('ensure.role:user_portal,super_admin,admin')->name('candidatos.cv');

    Route::post('/candidatos/{id}/correo', [CandidatoController::class, 'enviarCorreo'])
        ->middleware('ensure.role:user_portal,super_admin,admin')->name('candidatos.correo');

    Route::resource('candidatos', CandidatoController::class)
        ->middleware('ensure.role:user_portal,super_admin,admin');

    Route::post('/candidatos/{candidato}/rechazar', [CandidatoController::class, 'rechazar'])
        ->middleware('ensure.role:user_portal,super_admin,admin')->name('candidatos.rechazar');

    Route::post('/candidatos/{candidato}/aprobar', [CandidatoController::class, 'aprobar'])
        ->middleware('ensure.role:user_portal,super_admin,admin')->name('candidatos.aprobar');

    Route::post('/toggle-state', [ToggleController::class, 'store'])->name('toggle.store');

    Route::get('/panel/candidatos', [CandidatoController::class, 'mostrarCandidatos'])
        ->middleware('ensure.role:user_portal,super_admin,admin')->name('panel.candidatos');

    Route::get('/candidatos/{slug}/aprobados', [CandidatoController::class, 'showaprobados'])
        ->middleware('ensure.role:user_portal,super_admin,admin')->name('candidatos.aprobados.list');

    Route::get('/candidatos/{slug}', [CandidatoController::class, 'show'])
        ->middleware('ensure.role:user_portal,super_admin,admin')->name('candidatos.show');

    Route::get('/candidatos/{slug}/rechazados', [CandidatoController::class, 'showrechazados'])
        ->middleware('ensure.role:user_portal,super_admin,admin')->name('candidatos.rechazados.list');

    // Vacantes routes (protected)
    Route::get('/vacante/create', [VacanteController::class, 'create'])
        ->middleware('ensure.role:user_portal,super_admin,admin')->name('vacante.create');

    Route::get('/vacantes', [VacanteController::class, 'index'])
        ->middleware('ensure.role:user_portal,super_admin,admin')->name('vacantes.index');

    Route::get('/inicio', [VacanteController::class, 'inicio'])
        ->middleware('ensure.role:user_portal,super_admin,admin')->name('vacantes.inicio');

    Route::post('/vacantes', [VacanteController::class, 'store'])
        ->middleware('ensure.role:user_portal,super_admin,admin')->name('vacantes.store');

    Route::post('/vacantes/{slug}/habilitar', [VacanteController::class, 'habilitar'])
        ->middleware('ensure.role:user_portal,super_admin,admin')->name('vacantes.habilitar');

    Route::resource('vacantes', VacanteController::class)
        ->middleware('ensure.role:user_portal,super_admin,admin');

    Route::get('/enviar-email', [UserController::class, 'enviarVerificacion'])
        ->middleware('ensure.role:user_portal,super_admin,admin')->name('enviarVerificacion');


    /* ---------- Disciplinas / Empleados / PDFs ---------- */
    Route::get('/DisciplinaPositiva', [FormController::class, 'showForm'])
        ->name('Disciplina.show')->middleware('ensure.role:user,user_disciplina,lider,super_admin,admin,adminpresupuesto');

    Route::post('/form', [FormController::class, 'handleForm'])->name('form.submit');
    Route::post('/generar-pdf', [FormatoController::class, 'generarPDF'])->name('formulario.pdf');

    Route::get('/import-excel', [ExcelController::class, 'showForm'])
        ->name('excel.form')->middleware('ensure.role:user_disciplina,lider,super_admin,admin,adminpresupuesto');

    Route::post('/upload-excel', [EmpleadoController::class, 'importExcel'])
        ->name('excel.import')->middleware('ensure.role:user_disciplina,lider,super_admin,admin,adminpresupuesto');

    Route::get('/buscar-empleado/{cedula}', [EmpleadoController::class, 'buscarPorCedula'])
        ->name('empleado.buscar')->middleware('ensure.role:user_disciplina,lider,super_admin,admin,user,adminpresupuesto');

    Route::get('/descargar-pdf', [FormatoController::class, 'descargarPDF'])
        ->name('descargar.pdf')->middleware('ensure.role:user_disciplina,lider,super_admin,admin,user,adminpresupuesto');

    Route::get('/empleados', [ListController::class, 'mostrarEmpleados'])
        ->name('empleados.list')->middleware('ensure.role:lider,super_admin,admin');

    Route::get('/Disciplinas', [ListController::class, 'mostrarDisciplinasPositivas'])
        ->name('Disciplinas.list')->middleware('ensure.role:super_admin,admin');

    Route::get('/DisciplinasUsers', [ListController::class, 'mostrarDisciplinasPositivasUsers'])
        ->name('Disciplinas.listUsers')->middleware('ensure.role:user_disciplina,lider,super_admin,admin,user,adminpresupuesto');

    Route::get('/empleados/export', [ListController::class, 'exportarEmpleadosExcel'])
        ->name('exportar.empleados')->middleware('ensure.role:user_disciplina,super_admin,admin,adminpresupuesto');

    Route::get('/disciplinas/export', [ListController::class, 'exportarDisciplinasExcel'])
        ->name('disciplinas.export')->middleware('ensure.role:user_disciplina,super_admin,admin,adminpresupuesto');

    Route::get('/disciplinas/eliminadas', [ListController::class, 'MostrarEliminados'])
        ->name('disciplinas.eliminadas')->middleware('ensure.role:super_admin');

    Route::post('/disciplinas/delete', [ListController::class, 'eliminarDisciplina'])
        ->name('disciplinas.delete')->middleware('ensure.role:super_admin');

    Route::post('/disciplinas/restore', [ListController::class, 'restaurarDisciplina'])
        ->name('disciplinas.restore')->middleware('ensure.role:super_admin');

    Route::get('/dashboard', fn() => view('dashboard'))->name('dashboard');
    
    
        // RUTAS PARA WHISH LIST 
    Route::prefix('api/v1')->group(function () {
        //EXCEL IMPORT CATALOG
        Route::post('/catalog/import', [CatalogController::class, 'import']);
    
    });
Route::prefix('api/v1')
    ->middleware('ensure.role:seller,cashier,admin,super_admin,adminpresupuesto,lider')
    ->group(function () {

    // cashiers / reports (frontend CommisionCashierUsers)
    Route::get('advisors/cashier-awards', [AdvisorController::class, 'cashierAwards']);
    Route::get('advisors/cashier/{userId}/categories', [AdvisorController::class, 'cashierCategories']);

    // detect specialist for current user (optional - frontend can call to decide behavior)
    Route::get('advisors/specialistCheck', [AdvisorController::class, 'specialistCheck']);

            Route::get('commissions/my', [AdvisorController::class, 'myCommissions']);
    Route::get('commissions/my/export', [AdvisorController::class, 'exportMyCommissions']);
        // Lectura de overrides (GET)
Route::get('commissions/category-commissions/overrides', [AdvisorController::class, 'getCommissionOverrides']);
    Route::get('advisors/budget-sellers', [AdvisorController::class, 'budgetSellers']);
// Guardar overrides (POST)
Route::post('commissions/category-commissions/overrides', [AdvisorController::class, 'saveCommissionOverrides']);
        // Lectura de ventas de asesores especializados activos
Route::get('advisors/active-sales', [AdvisorController::class, 'activeSpecialistsSales'])
    ->middleware('ensure.role:seller,admin,super_admin,adminpresupuesto,lider');
    
    Route::get('advisors/split-pool', [AdvisorController::class, 'splitAdvisorPool']);
    Route::get('advisors/get-split', [AdvisorController::class, 'getAdvisorSplit']);
Route::post('advisors/save-split', [AdvisorController::class, 'saveAdvisorSplit']);
Route::get('sales', [SalesByUserController::class, 'getSalesByUser']);
        
    Route::get('reports/cashier/{userId}/categories', [ReportController::class, 'cashierCategories']);

    // catálogo
    Route::get('catalog/categories', [WishItemController::class, 'categories']);
    Route::get('catalog-products', [WishItemController::class, 'searchCatalog']);

    // wish items
    Route::get('wish-items', [WishItemController::class, 'listWishItems']);
    Route::post('wish-items', [WishItemController::class, 'create']);
    Route::post('wish-items/select', [WishItemController::class, 'select']);
    Route::get('users/sellers', [WishItemController::class, 'sellers']);

    Route::get('me', [WishItemController::class, 'me']);

});

Route::prefix('api/v1')
    ->middleware('ensure.role:admin,super_admin,adminpresupuesto')
    ->group(function () {

    Route::patch('wish-items/{id}', [WishItemController::class, 'update']);
    Route::get('wish-items/stats', [WishItemController::class, 'stats']);
        Route::get('wish-items/selections', [\App\Http\Controllers\Api\WishItemController::class, 'selectionsList']);


});



// 🔒 RUTAS DEL PANEL SOLO PARA ADMIN Y SUPER ADMIN
Route::middleware('ensure.role:admin,super_admin,adminpresupuesto,lider')->group(function () {

    Route::get('/panel', fn() => view('panel')); // HomePage

    Route::get('/panel/users', fn() => view('panel'));
    Route::get('/panel/ImportsManagerPage', fn() => view('panel'));
    Route::get('/panel/budget', fn() => view('panel'));
    Route::get('/panel/CommissionCardsPage', fn() => view('panel'));
    Route::get('/panel/CashierAwards', fn() => view('panel'));
    Route::get('/panel/commissions/categories', fn() => view('panel'));


});

Route::middleware('ensure.role:seller')->group(function () {
    Route::get('/panel/CommisionsUser', fn() => view('panel'));
});

Route::middleware('ensure.role:cashier,lider')->group(function () {
    Route::get('/panel/CashierAwardsUsers', fn() => view('panel'));
});

Route::get('/panel/AdminWishList', fn() => view('panel'))
    ->middleware('ensure.role:admin,super_admin,adminpresupuesto');


    /* ---------- React panel (single entry) ----------
       Permite el acceso sólo a sellers, admin y super_admin.
    */
    Route::get('/panel/{any?}', fn() => view('panel'))
        ->where('any', '.*')
        ->middleware('ensure.role:seller,admin,super_admin,adminpresupuesto,cashier,lider');


    /* ---------- Session-backed "API" endpoints (used by React) ----------
       IMPORTANTE: los endpoints que consume React vía fetch/axios desde el mismo dominio
       deben quedar aquí (web.php) para conservar la sesión del usuario.
    */
    Route::prefix('api/v1')->group(function () {
        
        // Rutas para gestionar usuarios limitadas a administradores
        Route::middleware('ensure.role:super_admin,admin,adminpresupuesto')->group(function () {
            // Listar usuarios filtrados por los roles permitidos
            Route::get('manage/users', [ApiUserController::class, 'indexForManagedRoles']);
            // Crear usuario (solo roles permitidos)
            Route::post('manage/users', [ApiUserController::class, 'storeManagedUser']);
            Route::delete('/manage/users/{id}', [ApiUserController::class, 'destroyManagedUser']);
            // Actualizar usuario
            Route::put('manage/users/{id}', [ApiUserController::class, 'updateManagedUser']);
            Route::get('reports/advisors-split', [CommissionReportController::class, 'advisorsSplit']);
            Route::post('/budgets/{id}/close', [BudgetController::class, 'close']);


        });

        // IMPORTS (permitir que lider importe sin tener todos los permisos admin)
        Route::middleware('ensure.role:super_admin,admin,adminpresupuesto,lider')->group(function () {
            // Permitir solo la acción de import (no listar/borrar/imports management)
            Route::post('import-turns', [TurnsImportController::class, 'import']);
            Route::post('import-sales', [ImportSalesController::class, 'import']);
        });
        

        // Seller-only: obtener mis comisiones
        Route::middleware('ensure.role:seller')->group(function () {
            Route::get('/commissions/my', [CommissionReportController::class, 'myCommissions']);
            Route::get('/commissions/my/export', [CommissionReportController::class, 'myExport']);
        });


        // Budgets: lectura para seller + admin roles (añadido 'lider' para ver comisiones)
        Route::middleware('ensure.role:seller,admin,super_admin,adminpresupuesto,lider')->group(function () {
            Route::get('/commissions/by-seller/{userId}/export', [CommissionReportController::class, 'exportSellerDetail']);
            // Exports
            Route::get('/commissions/export', [CommissionReportController::class, 'exportExcel']);
        });

        // Budgets: lectura para cashier + admin roles (añadido 'lider' para ver budgets y cashier-awards)
        Route::middleware('ensure.role:seller,cashier,admin,super_admin,adminpresupuesto,lider')->group(function () {
            Route::get('/budgets', [BudgetController::class, 'index']);
            Route::get('/budgets/active', [BudgetController::class, 'active']);
            Route::get('reports/cashier-awards', [ReportController::class, 'cashierAwards']);
            Route::get('/reports/cashier-awards/export', [ReportController::class, 'cashierAwardsExport']);
        });
        Route::middleware('ensure.role:super_admin,admin,adminpresupuesto,lider')->group(function () {
                        // REPORTS / EXports
            Route::get('/commissions/by-seller', [CommissionReportController::class, 'bySeller']);
            Route::get('/commissions/by-seller/{userId}', [CommissionReportController::class, 'bySellerDetail']);
            Route::put('commissions/assign-turns/{userId}/{budget_id}', [CommissionReportController::class, 'assignTurns']);
            Route::post('commissions/assign-turns/{userId}/{budget_id}', [CommissionReportController::class, 'assignTurns']);
            
            // IMPORT TURNS
            Route::get('imports/turns', [TurnsImportController::class, 'index']);
            Route::get('imports/turns/{id}', [TurnsImportController::class, 'show']);
            Route::delete('imports/turns/{id}', [TurnsImportController::class, 'deleteBatch']);
            Route::delete('imports/turns', [TurnsImportController::class, 'bulkDelete']);
            
                        // IMPORTS (Excel) - management endpoints (index/show/destroy) reserved to admin
            Route::get('imports', [ImportBatchController::class, 'index']);
            Route::get('imports/{id}', [ImportBatchController::class, 'show']);
            Route::delete('imports/{id}', [ImportBatchController::class, 'destroy']);
            Route::post('imports/bulk-delete', [ImportBatchController::class, 'bulkDestroy']);
        });

        // Admin-only endpoints (manage budgets, users, turnos listing, imports management, etc.)
        Route::middleware('ensure.role:super_admin,admin,adminpresupuesto')->group(function () {

            // TURNOS & IMPORTS (mantuve listado/management sólo para admins)


            // USERS & ROLES (admin UI)
            Route::get('users', [ApiUserController::class, 'index']);
            Route::post('users/{id}/assign-role', [ApiUserController::class, 'assignRole']);
            Route::get('roles', [RoleController::class, 'index']);



            // SALES
            Route::get('sales/users', [SalesByUserController::class, 'getUsersWithSales']);
            Route::get('sales/by-user', [SalesByUserController::class, 'getSalesByUser']);

            // COMMISSIONS – LOGIC (admin)
            Route::post('commissions/recalc-sale/{id}', [CommissionController::class,'recalcSale']);
            Route::post('commissions/recalc-user/{userId}/{month}', [CommissionController::class,'recalcUserMonth']);
            Route::get('commissions/summary', [CommissionController::class,'userSummary']);
            Route::post('commissions/finalize', [CommissionController::class,'finalize']);



            //cashier-awards

            
            Route::post('/cashier-adjustments', [ReportController::class,'storeCashierAdjustment']);

            // Commission config (admin)
            Route::get('commissions/categories', [CategoryCommissionController::class, 'index']);
            Route::post('commissions/categories', [CategoryCommissionController::class, 'upsert']);
            Route::delete('commissions/categories/{id}', [CategoryCommissionController::class, 'destroy']);
            Route::post('commissions/categories/bulk', [CategoryCommissionController::class, 'bulkUpdate']);
            Route::post('/commissions/generate', [CommissionController::class, 'generate']);

            // Budgets management (admin)
            Route::post('/budgets', [BudgetController::class, 'store']);
            Route::put('/budgets/{id}', [BudgetController::class, 'update']);
            Route::delete('/budgets/{id}', [BudgetController::class, 'destroy']);
            Route::patch('/budgets/{id}/cashier-prize', [BudgetController::class, 'updateCashierPrize']);




// dentro del grupo Route::prefix('api/v1')->middleware(...)->group(...)
Route::get('/commission-leaders', [CommissionLideres::class, 'index']);
Route::post('/commission-leaders', [CommissionLideres::class, 'storeLeader']);
Route::put('/commission-leaders/{id}', [CommissionLideres::class, 'updateLeader']);
Route::delete('/commission-leaders/{id}', [CommissionLideres::class, 'destroyLeader']);

Route::get('/commission-leaders/{id}/absences', [CommissionLideres::class, 'listAbsences']);
Route::post('/commission-leaders/{id}/absences', [CommissionLideres::class, 'addAbsence']);
Route::delete('/commission-leaders/{id}/absences/{aid}', [CommissionLideres::class, 'deleteAbsence']);

Route::get('/commission-leaders/config', [CommissionLideres::class, 'getConfig']);
Route::post('/commission-leaders/config', [CommissionLideres::class, 'saveConfig']);

Route::post('/commission-leaders/calculate', [CommissionLideres::class, 'calculateCommissions']);
Route::post('/commissions/save-store-split', [CommissionLideres::class, 'saveStoreSplit']);
Route::get('/commissions/store-split/{budgetId}', [CommissionLideres::class, 'getStoreSplit']);
Route::prefix('advisors')->group(function () {

    Route::get('category-budgets', [AdvisorController::class, 'indexCategoryBudgets']);
    Route::post('category-budgets', [AdvisorController::class, 'upsertCategoryBudget']);
    Route::post('category-budgets/bulk', [AdvisorController::class, 'bulkUpsert']); // opcional si lo usas
    Route::delete('category-budgets/{id}', [AdvisorController::class, 'deleteCategoryBudget']);

    Route::post('specialists', [AdvisorController::class, 'assignSpecialist']);
    Route::get('specialists', [AdvisorController::class, 'getSpecialistsForBudget']);

});

            
        });

    }); // end prefix api/v1


    // Otros endpoints web que requieren auth...
    Route::post('/llamados/importar', [FormatoController::class, 'importarExcel'])
        ->name('llamados.importar')->middleware('ensure.role:user_disciplina,super_admin,admin,adminpresupuesto');

    Route::get('/api/firmas', function () {
        return response()->json([
            'empleado' => session('firma_empleado'),
            'jefe' => session('firma_jefe'),
            'proceso' => session('Proceso'),
        ]);
    });

}); // end auth group
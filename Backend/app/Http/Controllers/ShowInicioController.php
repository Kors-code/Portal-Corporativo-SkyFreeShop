<?php

namespace App\Http\Controllers;

class ShowInicioController extends Controller
{
    public function showWelcome()
    {
        return $this->showPortal('main');
    }

    public function showPortal($type = 'main')
    {
        return view('portal', $this->getPortalConfig($type));
    }

    private function getPortalConfig($type)
    {
        $portals = [
            'main' => [
                'title' => 'Portal Corporativo Sky Free Shop',
                'subtitle' => 'Centro de acceso para operaciones, ventas, contabilidad, inventario, talento, analitica y administracion.',
                'eyebrow' => 'Suite corporativa',
                'area_order' => ['Comisiones', 'Presupuesto', 'Personal', 'Inventario', 'Analitica', 'Contabilidad', 'Administracion'],
                'buttons' => [],
                'showcase_groups' => [
                    [
                        'title' => 'Comisiones',
                        'icon' => 'fa-solid fa-chart-line',
                        'items' => [
                            [
                                'title' => 'Asesores',
                                'route' => '/panel/CommisionsUser',
                                'icon' => 'fa-solid fa-user-tie',
                                'permissions' => ['commissions.user.view'],
                            ],
                            [
                                'title' => 'Especializados',
                                'route' => '/panel/commissions/SpecialistCommissionsPanel',
                                'icon' => 'fa-solid fa-chart-line',
                                'permissions' => ['commissions.asesorSpecialist.view'],
                            ],
                            [
                                'title' => 'Perfiles',
                                'route' => '/panel/commissions/my-profiles',
                                'icon' => 'fa-solid fa-badge-dollar',
                                'permissions' => ['commission_profiles.my.view'],
                            ],
                            [
                                'title' => 'Wish List',
                                'route' => '/panel/CatalogMatchPage',
                                'icon' => 'fa-solid fa-star',
                                'permissions' => ['wishlist.view'],
                            ],
                        ],
                    ],
                    [
                        'title' => 'Personal',
                        'icon' => 'fa-solid fa-users-gear',
                        'items' => [
                            [
                                'title' => 'Portal de empleo',
                                'route' => 'vacantes.inicio',
                                'icon' => 'fa-solid fa-briefcase',
                            ],
                            [
                                'title' => 'Disciplinas positivas',
                                'route' => 'Disciplina.show',
                                'icon' => 'fa-solid fa-user-check',
                            ],
                            [
                                'title' => 'Minuta entrega',
                                'route' => '/panel/EntregasDashboardPage',
                                'icon' => 'fa-solid fa-clipboard-check',
                                'permissions' => ['entregas.view'],
                            ],
                            [
                                'title' => 'Recibir minuta',
                                'route' => '/panel/entregas/recibir',
                                'icon' => 'fa-solid fa-inbox',
                                'permissions' => ['entregas.view'],
                            ],
                        ],
                    ],
                    [
                        'title' => 'Contabilidad',
                        'icon' => 'fa-solid fa-scale-balanced',
                        'items' => [
                            [
                                'title' => 'Importar bancos',
                                'route' => '/panel/davibank-converter',
                                'icon' => 'fa-solid fa-file-import',
                                'permissions' => ['accounting.bank-imports.create', 'imports.create'],
                            ],
                            [
                                'title' => 'Bancos',
                                'route' => '/panel/BankImportsManagerPage',
                                'icon' => 'fa-solid fa-building-columns',
                                'permissions' => ['accounting.bank-imports.view', 'imports.create'],
                            ],
                            [
                                'title' => 'Movimientos bancarios',
                                'route' => '/panel/BankMovementsPage',
                                'icon' => 'fa-solid fa-receipt',
                                'permissions' => ['accounting.bank-imports.view', 'imports.create'],
                            ],
                        ],
                    ],
                    [
                        'title' => 'Presupuesto',
                        'icon' => 'fa-solid fa-wallet',
                        'items' => [
                            [
                                'title' => 'Perfiles de comisión',
                                'route' => '/panel/commissions/profiles',
                                'icon' => 'fa-solid fa-badge-dollar',
                                'permissions' => ['budget.commissions.manage'],
                            ],
                            [
                                'title' => 'Presupuesto',
                                'route' => '/panel/budget',
                                'icon' => 'fa-solid fa-wallet',
                                'permissions' => ['budget.admin.view'],
                            ],
                            [
                                'title' => 'Asesores',
                                'route' => '/panel/CommissionCardsPage',
                                'icon' => 'fa-solid fa-chart-pie',
                                'permissions' => ['budget.commissions.view'],
                            ],
                            [
                                'title' => 'Importes',
                                'route' => '/panel/ImportsManagerPage',
                                'icon' => 'fa-solid fa-file-import',
                                'permissions' => ['imports.create'],
                            ],
                        ],
                    ],
                    [
                        'title' => 'Inventario',
                        'icon' => 'fa-solid fa-boxes-stacked',
                        'items' => [
                            [
                                'title' => 'Cobertura',
                                'route' => '/panel/inventarios/cobertura',
                                'icon' => 'fa-solid fa-boxes-stacked',
                                'permissions' => ['inventarios.cobertura'],
                            ],
                            [
                                'title' => 'Alertas',
                                'route' => '/panel/inventarios/alertas',
                                'icon' => 'fa-solid fa-bell',
                                'permissions' => ['inventarios.alertas'],
                            ],
                            [
                                'title' => 'Importes',
                                'route' => '/panel/InventoryImportsManagerPage',
                                'icon' => 'fa-solid fa-warehouse',
                                'permissions' => ['inventarios.importes'],
                            ],
                        ],
                    ],
                ],
                'cards' => [
                    [
                        'icon' => 'fa-solid fa-user-tie',
                        'title' => 'Asesores',
                        'text' => 'Consulta cumplimiento, ventas y comisiones personales.',
                        'area' => 'Comisiones',
                        'route' => '/panel/CommisionsUser',
                        'permissions' => ['commissions.user.view'],
                        'featured' => true,
                    ],
                    [
                        'icon' => 'fa-solid fa-chart-line',
                        'title' => 'Asesor Especializado',
                        'text' => 'Vista personal de comisiones para asesores especializados.',
                        'area' => 'Comisiones',
                        'route' => '/panel/commissions/SpecialistCommissionsPanel',
                        'permissions' => ['commissions.asesorSpecialist.view'],
                    ],
                    [
                        'icon' => 'fa-solid fa-users-viewfinder',
                        'title' => 'Perfiles',
                        'text' => 'Consulta tus perfiles, metas y comisiones por presupuesto.',
                        'area' => 'Comisiones',
                        'route' => '/panel/commissions/my-profiles',
                        'permissions' => ['commission_profiles.my.view'],
                    ],
                    [
                        'icon' => 'fa-solid fa-star',
                        'title' => 'Wish List',
                        'text' => 'Solicitudes, catalogo y coincidencias de productos.',
                        'area' => 'Comisiones',
                        'route' => '/panel/CatalogMatchPage',
                        'permissions' => ['wishlist.view'],
                    ],
                    [
                        'icon' => 'fa-solid fa-badge-dollar',
                        'title' => 'Perfiles de Comisión',
                        'text' => 'Configura comisiones flexibles por proveedor, categoria y personas asignadas.',
                        'area' => 'Presupuesto',
                        'route' => '/panel/commissions/profiles',
                        'permissions' => ['budget.commissions.manage'],
                    ],
                    [
                        'icon' => 'fa-solid fa-wallet',
                        'title' => 'Presupuesto',
                        'text' => 'Crea, configura y administra presupuestos corporativos.',
                        'area' => 'Presupuesto',
                        'route' => '/panel/budget',
                        'permissions' => ['budget.admin.view'],
                        'featured' => true,
                    ],
                    [
                        'icon' => 'fa-solid fa-chart-pie',
                        'title' => 'Asesores',
                        'text' => 'Resumen de ventas, KPI y comisiones por asesor.',
                        'area' => 'Presupuesto',
                        'route' => '/panel/CommissionCardsPage',
                        'permissions' => ['budget.commissions.view'],
                    ],
                    [
                        'icon' => 'fa-solid fa-file-import',
                        'title' => 'Importes',
                        'text' => 'Importa ventas, consulta lotes y revisa procesos cargados.',
                        'area' => 'Presupuesto',
                        'route' => '/panel/ImportsManagerPage',
                        'permissions' => ['imports.create'],
                    ],
                    [
                        'icon' => 'fa-solid fa-chart-line',
                        'title' => 'Hub de Visualizaciones',
                        'text' => 'Tableros ejecutivos de ventas, caja e indicadores diarios.',
                        'area' => 'Analitica',
                        'route' => '/panel/visualizaciones',
                        'roles' => ['super_admin', 'lider'],
                        'featured' => true,
                    ],
                    [
                        'icon' => 'fa-solid fa-plane-departure',
                        'title' => 'Passenger Intelligence',
                        'text' => 'Flujo internacional MDE y composicion colombiano/extranjero trazable.',
                        'area' => 'Analitica',
                        'route' => '/panel/passenger-intelligence',
                        'permissions' => ['passenger-intelligence.view'],
                        'featured' => true,
                    ],
                    [
                        'icon' => 'fa-solid fa-store',
                        'title' => 'Ventas por Tienda',
                        'text' => 'Dashboard de desempeno por sede y periodo.',
                        'area' => 'Analitica',
                        'route' => '/panel/visualizaciones/ventas-tiendas',
                        'permissions' => ['visualizations.view'],
                    ],
                    [
                        'icon' => 'fa-solid fa-receipt',
                        'title' => 'Cierre de Caja',
                        'text' => 'Revision ejecutiva de cierres y movimiento diario.',
                        'area' => 'Analitica',
                        'route' => '/panel/visualizaciones/cierre-caja',
                        'permissions' => ['visualizations.view'],
                    ],
                    [
                        'icon' => 'fa-solid fa-boxes-stacked',
                        'title' => 'Vista de Inventario',
                        'text' => 'Monitoreo ejecutivo de stock, dias disponibles, proveedor, marca y estado.',
                        'area' => 'Analitica',
                        'route' => '/panel/visualizaciones/inventario',
                        'permissions' => ['visualizations.view'],
                    ],
                    [
                        'icon' => 'fa-solid fa-boxes-stacked',
                        'title' => 'Cobertura de Inventario',
                        'text' => 'Consulta dias disponibles, riesgo y sugeridos por SKU y tienda.',
                        'area' => 'Inventario',
                        'route' => '/panel/inventarios/cobertura',
                        'permissions' => ['inventarios.cobertura'],
                        'featured' => true,
                    ],
                    [
                        'icon' => 'fa-solid fa-bell',
                        'title' => 'Alertas de Inventario',
                        'text' => 'Listas de seguimiento y notificaciones para productos criticos.',
                        'area' => 'Inventario',
                        'route' => '/panel/inventarios/alertas',
                        'permissions' => ['inventarios.alertas'],
                    ],
                    [
                        'icon' => 'fa-solid fa-warehouse',
                        'title' => 'Importar Inventario',
                        'text' => 'Historial y carga de movimientos de inventario por tienda.',
                        'area' => 'Inventario',
                        'route' => '/panel/InventoryImportsManagerPage',
                        'permissions' => ['inventarios.importes'],
                    ],
                    [
                        'icon' => 'fa-solid fa-file-import',
                        'title' => 'Importar Bancos',
                        'text' => 'Carga extractos bancarios y conviertelos al formato operativo.',
                        'area' => 'Contabilidad',
                        'route' => '/panel/davibank-converter',
                        'permissions' => ['accounting.bank-imports.create', 'imports.create'],
                    ],
                    [
                        'icon' => 'fa-solid fa-building-columns',
                        'title' => 'Bancos',
                        'text' => 'Historial de importaciones bancarias, lotes y archivos procesados.',
                        'area' => 'Contabilidad',
                        'route' => '/panel/BankImportsManagerPage',
                        'permissions' => ['accounting.bank-imports.view', 'imports.create'],
                    ],
                    [
                        'icon' => 'fa-solid fa-receipt',
                        'title' => 'Movimientos Bancarios',
                        'text' => 'Consulta movimientos normalizados, filtros por banco y exportes contables.',
                        'area' => 'Contabilidad',
                        'route' => '/panel/BankMovementsPage',
                        'permissions' => ['accounting.bank-imports.view', 'imports.create'],
                    ],
                    [
                        'icon' => 'fa-solid fa-user-check',
                        'title' => 'Disciplinas Positivas',
                        'text' => 'Gestion de seguimiento interno y bienestar laboral.',
                        'area' => 'Personal',
                        'route' => 'Disciplina.show',
                    ],
                    [
                        'icon' => 'fa-solid fa-briefcase',
                        'title' => 'Portal de Empleo',
                        'text' => 'Vacantes, candidatos y procesos de seleccion.',
                        'area' => 'Personal',
                        'route' => 'vacantes.inicio',
                    ],
                    [
                        'icon' => 'fa-solid fa-clipboard-check',
                        'title' => 'Minuta de Entrega',
                        'text' => 'Panel de minutas de entrega para seguimiento de lideres.',
                        'area' => 'Personal',
                        'route' => '/panel/EntregasDashboardPage',
                        'permissions' => ['entregas.view'],
                    ],
                    [
                        'icon' => 'fa-solid fa-inbox',
                        'title' => 'Recibir Minuta',
                        'text' => 'Actas enviadas a lideres para revision y recepcion.',
                        'area' => 'Personal',
                        'route' => '/panel/entregas/recibir',
                        'permissions' => ['entregas.view'],
                    ],
                    [
                        'icon' => 'fa-solid fa-users-gear',
                        'title' => 'Gestion de Usuarios',
                        'text' => 'Administra usuarios del sistema.',
                        'area' => 'Administracion',
                        'route' => '/panel/users',
                        'permissions' => ['users.view'],
                    ],
                    [
                        'icon' => 'fa-solid fa-shield-halved',
                        'title' => 'Permisos',
                        'text' => 'Roles, permisos y accesos especiales del portal.',
                        'area' => 'Administracion',
                        'route' => '/panel/AdminPermissionsPanel',
                        'roles' => ['super_admin'],
                    ],
                ],
            ],

            'presupuesto' => [
                'title' => 'Modulo de Presupuesto',
                'subtitle' => 'Gestiona, consulta y crea presupuestos corporativos desde accesos organizados por perfil.',
                'eyebrow' => 'Gestion comercial',
                'area_order' => ['Comisiones', 'Presupuesto'],
                'buttons' => [
                    [
                        'route' => '/panel/commissions/SpecialistCommissionsPanel',
                        'class' => 'btn btn-primary',
                        'icon' => 'fa-solid fa-chart-line',
                        'text' => 'Especializados',
                        'permissions' => ['commissions.asesorSpecialist.view'],
                    ],
                    [
                        'route' => '/panel/CommisionsUser',
                        'class' => 'btn btn-primary',
                        'icon' => 'fa-solid fa-user-tie',
                        'text' => 'Asesores',
                        'permissions' => ['commissions.user.view'],
                    ],
                    [
                        'route' => 'welcome',
                        'class' => 'btn btn-outline',
                        'icon' => 'fa-solid fa-arrow-left',
                        'text' => 'Volver',
                    ],
                ],
                'cards' => [
                    [
                        'icon' => 'fa-solid fa-user-tie',
                        'title' => 'Asesores',
                        'text' => 'Consulta cumplimiento, ventas y comisiones personales.',
                        'area' => 'Comisiones',
                        'route' => '/panel/CommisionsUser',
                        'permissions' => ['commissions.user.view'],
                    ],
                    [
                        'icon' => 'fa-solid fa-chart-pie',
                        'title' => 'Asesores',
                        'text' => 'Analisis de cumplimiento y desempeno por area.',
                        'area' => 'Presupuesto',
                        'route' => '/panel/CommissionCardsPage',
                        'permissions' => ['budget.commissions.view'],
                    ],
                    [
                        'icon' => 'fa-solid fa-badge-dollar',
                        'title' => 'Perfiles de Comisión',
                        'text' => 'Crea reglas por proveedor o categoria y asigna quienes comisionan.',
                        'area' => 'Presupuesto',
                        'route' => '/panel/commissions/profiles',
                        'permissions' => ['budget.commissions.manage'],
                    ],
                    [
                        'icon' => 'fa-solid fa-users-viewfinder',
                        'title' => 'Perfiles',
                        'text' => 'Consulta tus perfiles, metas y comisiones por presupuesto.',
                        'area' => 'Comisiones',
                        'route' => '/panel/commissions/my-profiles',
                        'permissions' => ['commission_profiles.my.view'],
                    ],
                    [
                        'icon' => 'fa-solid fa-screwdriver-wrench',
                        'title' => 'Presupuesto',
                        'text' => 'Configura presupuestos, metas y parametros.',
                        'area' => 'Presupuesto',
                        'route' => '/panel/budget',
                        'permissions' => ['budget.admin.view'],
                    ],
                ],
            ],

        ];

        $config = $portals[$type] ?? $portals['main'];
        $user = auth()->user();
        $userRole = $user?->role;

        $isAllowed = function ($item) use ($user, $userRole) {
            $roleAllowed = empty($item['roles']) || in_array($userRole, $item['roles'], true);
            $permissionAllowed = empty($item['permissions'])
                || collect($item['permissions'])->contains(fn ($permission) => $user?->hasPermission($permission));

            return $roleAllowed && $permissionAllowed;
        };

        foreach (['buttons', 'cards'] as $section) {
            $config[$section] = array_values(array_filter($config[$section], $isAllowed));
        }

        $config['showcase_groups'] = array_values(array_filter(array_map(function ($group) use ($isAllowed) {
            $group['items'] = array_values(array_filter($group['items'] ?? [], $isAllowed));

            return $group;
        }, $config['showcase_groups'] ?? []), fn ($group) => count($group['items']) > 0));

        $areas = collect($config['cards'])
            ->pluck('area')
            ->unique()
            ->sortBy(fn ($area) => array_search($area, $config['area_order'] ?? [], true) === false ? 99 : array_search($area, $config['area_order'], true))
            ->values()
            ->all();

        $config['areas'] = $areas;
        $config['featuredCards'] = collect($config['cards'])->where('featured', true)->take(4)->values()->all();
        $config['moduleCount'] = count($config['cards']);

        return $config;
    }
}

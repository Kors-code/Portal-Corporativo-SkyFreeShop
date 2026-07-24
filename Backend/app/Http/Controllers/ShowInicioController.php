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
                'subtitle' => 'Centro de acceso para operaciones, ventas, inventario, talento, analitica y administracion.',
                'eyebrow' => 'Suite corporativa',
                'area_order' => ['Comercial', 'Operaciones', 'Inventario', 'Analitica', 'Talento', 'Administracion'],
                'buttons' => [],
                'showcase_groups' => [
                    [
                        'title' => 'Usuarios',
                        'icon' => 'fa-solid fa-users',
                        'items' => [
                            [
                                'title' => 'Asesores',
                                'route' => '/panel/CommisionsUser',
                                'icon' => 'fa-solid fa-user-tie',
                                'permissions' => ['commissions.user.view'],
                            ],
                            [
                                'title' => 'Cajeros',
                                'route' => '/panel/CashierAwardsUsers',
                                'icon' => 'fa-solid fa-cash-register',
                                'permissions' => ['budget.cashier.view'],
                            ],
                            [
                                'title' => 'Especializados',
                                'route' => '/panel/commissions/SpecialistCommissionsPanel',
                                'icon' => 'fa-solid fa-chart-line',
                                'permissions' => ['commissions.asesorSpecialist.view'],
                            ],
                        ],
                    ],
                    [
                        'title' => 'Administrativo',
                        'icon' => 'fa-solid fa-building-user',
                        'items' => [
                            [
                                'title' => 'Minuta entrega',
                                'route' => '/panel/EntregasDashboardPage',
                                'icon' => 'fa-solid fa-clipboard-check',
                                'permissions' => ['entregas.view'],
                            ],
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
                                'title' => 'Actas recibidas',
                                'route' => '/panel/entregas/recibir',
                                'icon' => 'fa-solid fa-inbox',
                                'permissions' => ['entregas.view'],
                            ],
                            [
                                'title' => 'Crear entrega',
                                'route' => '/panel/CrearEntregaPage',
                                'icon' => 'fa-solid fa-file-signature',
                                'permissions' => ['entregas.manage'],
                            ],
                        ],
                    ],
                    [
                        'title' => 'Presupuesto',
                        'icon' => 'fa-solid fa-wallet',
                        'items' => [
                            [
                                'title' => 'Administrar',
                                'route' => '/panel/budget',
                                'icon' => 'fa-solid fa-screwdriver-wrench',
                                'permissions' => ['budget.view'],
                            ],
                            [
                                'title' => 'Visualizaciones',
                                'route' => '/panel/visualizaciones',
                                'icon' => 'fa-solid fa-chart-simple',
                                'roles' => ['super_admin', 'lider'],
                            ],
                            [
                                'title' => 'Reportes',
                                'route' => '/panel/CommissionCardsPage',
                                'icon' => 'fa-solid fa-chart-pie',
                                'permissions' => ['budget.commissions.view'],
                            ],
                            [
                                'title' => 'Categorías',
                                'route' => '/panel/commissions/categories',
                                'icon' => 'fa-solid fa-layer-group',
                                'permissions' => ['budget.commissions.manage'],
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
                                'permissions' => ['panel.view'],
                            ],
                            [
                                'title' => 'Alertas',
                                'route' => '/panel/inventarios/alertas',
                                'icon' => 'fa-solid fa-bell',
                                'permissions' => ['inventory-alerts.view'],
                            ],
                            [
                                'title' => 'Importes',
                                'route' => '/panel/InventoryImportsManagerPage',
                                'icon' => 'fa-solid fa-warehouse',
                                'permissions' => ['imports.create'],
                            ],
                            [
                                'title' => 'Rotación',
                                'route' => '/panel/inventarios/rotacion',
                                'icon' => 'fa-solid fa-rotate',
                                'permissions' => ['panel.view'],
                            ],
                        ],
                    ],
                ],
                'cards' => [
                    [
                        'icon' => 'fa-solid fa-wallet',
                        'title' => 'Presupuesto',
                        'text' => 'Acceso principal a metas, ventas, cumplimiento y comisiones.',
                        'area' => 'Comercial',
                        'route' => '/panel/budget',
                        'featured' => true,
                    ],
                    [
                        'icon' => 'fa-solid fa-users-line',
                        'title' => 'Seguimiento Asesores',
                        'text' => 'Resumen de ventas, KPI y comisiones por asesor.',
                        'area' => 'Comercial',
                        'route' => '/panel/CommissionCardsPage',
                        'permissions' => ['budget.commissions.view'],
                        'featured' => true,
                    ],
                    [
                        'icon' => 'fa-solid fa-cash-register',
                        'title' => 'Seguimiento Cajeros',
                        'text' => 'Comisiones, premios y desempeno de cajeros.',
                        'area' => 'Comercial',
                        'route' => '/panel/CashierAwards',
                        'permissions' => ['budget.cashier.view'],
                    ],
                    [
                        'icon' => 'fa-solid fa-user-tie',
                        'title' => 'Asesores Especializados',
                        'text' => 'Administracion y seguimiento de comisiones especializadas.',
                        'area' => 'Comercial',
                        'route' => '/panel/commissions/DualCommissionAdmin',
                        'permissions' => ['budget.commissions.manage'],
                    ],
                    [
                        'icon' => 'fa-solid fa-ranking-star',
                        'title' => 'Comisiones Lideres',
                        'text' => 'Seguimiento de comisiones y desempeno de lideres.',
                        'area' => 'Comercial',
                        'route' => '/panel/commissions/CommissionLeadersPage',
                        'permissions' => ['budget.leader.view'],
                    ],
                    [
                        'icon' => 'fa-solid fa-layer-group',
                        'title' => 'Porcentaje Categorias',
                        'text' => 'Configuracion de porcentajes y categorias de comision.',
                        'area' => 'Comercial',
                        'route' => '/panel/commissions/categories',
                        'permissions' => ['budget.commissions.manage'],
                    ],
                    [
                        'icon' => 'fa-solid fa-star',
                        'title' => 'Wish List',
                        'text' => 'Solicitudes, catalogo y coincidencias de productos.',
                        'area' => 'Comercial',
                        'route' => '/panel/CatalogMatchPage',
                    ],
                    [
                        'icon' => 'fa-solid fa-clipboard-check',
                        'title' => 'Minuta Entrega',
                        'text' => 'Actas de entrega, recepcion y responsabilidades activas.',
                        'area' => 'Operaciones',
                        'route' => '/panel/EntregasDashboardPage',
                        'permissions' => ['entregas.view'],
                        'featured' => true,
                    ],
                    [
                        'icon' => 'fa-solid fa-file-signature',
                        'title' => 'Crear Entrega',
                        'text' => 'Crear nuevas actas y novedades del turno.',
                        'area' => 'Operaciones',
                        'route' => '/panel/CrearEntregaPage',
                        'permissions' => ['entregas.manage'],
                    ],
                    [
                        'icon' => 'fa-solid fa-inbox',
                        'title' => 'Actas Recibidas',
                        'text' => 'Consulta entregas pendientes de recepcion o revision.',
                        'area' => 'Operaciones',
                        'route' => '/panel/entregas/recibir',
                        'permissions' => ['entregas.view'],
                    ],
                    [
                        'icon' => 'fa-solid fa-file-import',
                        'title' => 'Historial de Importes',
                        'text' => 'Importa ventas, consulta lotes y revisa procesos cargados.',
                        'area' => 'Operaciones',
                        'route' => '/panel/ImportsManagerPage',
                        'permissions' => ['imports.create'],
                    ],
                    [
                        'icon' => 'fa-solid fa-boxes-stacked',
                        'title' => 'Cobertura de Inventario',
                        'text' => 'Dias disponibles, riesgo por SKU y cobertura por tienda.',
                        'area' => 'Inventario',
                        'route' => '/panel/inventarios/cobertura',
                        'permissions' => ['panel.view'],
                        'featured' => true,
                    ],
                    [
                        'icon' => 'fa-solid fa-bell',
                        'title' => 'Alertas de Inventario',
                        'text' => 'Listas, top de ventas y notificaciones por correo.',
                        'area' => 'Inventario',
                        'route' => '/panel/inventarios/alertas',
                        'permissions' => ['inventory-alerts.view'],
                    ],
                    [
                        'icon' => 'fa-solid fa-warehouse',
                        'title' => 'Importes de Inventario',
                        'text' => 'Gestiona cargas y control de inventario.',
                        'area' => 'Inventario',
                        'route' => '/panel/InventoryImportsManagerPage',
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
                        'icon' => 'fa-solid fa-user-check',
                        'title' => 'Disciplinas Positivas',
                        'text' => 'Gestion de seguimiento interno y bienestar laboral.',
                        'area' => 'Talento',
                        'route' => 'Disciplina.show',
                    ],
                    [
                        'icon' => 'fa-solid fa-briefcase',
                        'title' => 'Portal de Empleo',
                        'text' => 'Vacantes, candidatos y procesos de seleccion.',
                        'area' => 'Talento',
                        'route' => 'vacantes.inicio',
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
                    [
                        'icon' => 'fa-solid fa-screwdriver-wrench',
                        'title' => 'Configurar Presupuestos',
                        'text' => 'Crea, configura y edita presupuestos corporativos.',
                        'area' => 'Administracion',
                        'route' => '/panel/budget',
                        'permissions' => ['budget.view'],
                    ],
                ],
            ],

            'presupuesto' => [
                'title' => 'Modulo de Presupuesto',
                'subtitle' => 'Gestiona, consulta y crea presupuestos corporativos desde accesos organizados por perfil.',
                'eyebrow' => 'Gestion comercial',
                'area_order' => ['Comisiones', 'Reportes', 'Administracion'],
                'buttons' => [
                    [
                        'route' => '/panel/commissions/SpecialistCommissionsPanel',
                        'class' => 'btn btn-primary',
                        'icon' => 'fa-solid fa-chart-line',
                        'text' => 'Especializados',
                    ],
                    [
                        'route' => '/panel/CashierAwardsUsers',
                        'class' => 'btn btn-primary',
                        'icon' => 'fa-solid fa-cash-register',
                        'text' => 'Cajeros',
                    ],
                    [
                        'route' => '/panel/CommisionsUser',
                        'class' => 'btn btn-primary',
                        'icon' => 'fa-solid fa-user-tie',
                        'text' => 'Asesores',
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
                    ],
                    [
                        'icon' => 'fa-solid fa-cash-register',
                        'title' => 'Cajeros',
                        'text' => 'Revisa ventas, premios y comisiones de caja.',
                        'area' => 'Comisiones',
                        'route' => '/panel/CashierAwardsUsers',
                    ],
                    [
                        'icon' => 'fa-solid fa-chart-pie',
                        'title' => 'Reportes Comerciales',
                        'text' => 'Analisis de cumplimiento y desempeno por area.',
                        'area' => 'Reportes',
                        'route' => '/panel/CommissionCardsPage',
                    ],
                    [
                        'icon' => 'fa-solid fa-screwdriver-wrench',
                        'title' => 'Administracion',
                        'text' => 'Configura presupuestos, metas y parametros.',
                        'area' => 'Administracion',
                        'route' => '/panel/budget',
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

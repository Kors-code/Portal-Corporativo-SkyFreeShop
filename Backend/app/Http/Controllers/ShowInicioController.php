<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ShowInicioController extends Controller
{
    /**
     * Mantener showWelcome para tu ruta original y middleware.
     * Llama al motor showPortal() para devolver la vista unificada.
     */
    public function showWelcome()
    {
        // Si quieres, puedes hacer lógica específica aquí antes de renderizar.
        return $this->showPortal('main');
    }

    /**
     * Motor único que devuelve la misma vista 'portal' con configuración dinámica.
     * $type puede ser 'main', 'presupuesto', u otros que agregues.
     */
    public function showPortal($type = 'main')
    {
        $config = $this->getPortalConfig($type);
        return view('portal', $config);
    }

    /**
     * Configuración por tipo de portal.
     * Agrega/acota más 'types' según necesites.
     */
    private function getPortalConfig($type)
    {
        $portals = [

            'main' => [
                'title' => 'Bienvenido al Portal Corporativo',
                'subtitle' => 'Accede a los recursos internos y sistemas de Sky Free Shop desde un solo lugar.',
                'buttons' => [
                    [
                        'route' => '/panel/CatalogMatchPage',
                        'class' => 'btn btn-primary',
                        'icon'  => 'fa-solid fa-user-check',
                        'text'  => 'Wish List'
                    ],
                    [
                        'route' => 'Disciplina.show',
                        'class' => 'btn btn-primary',
                        'icon'  => 'fa-solid fa-user-check',
                        'text'  => 'Disciplinas Positivas'
                    ],
                    [
                        'route' => 'vacantes.inicio',
                        'class' => 'btn btn-outline',
                        'icon'  => 'fa-solid fa-briefcase',
                        'text'  => 'Portal de Empleo'
                    ],
                    [
                        'route' => 'presupuesto',
                        'class' => 'btn btn-outline',
                        'icon'  => 'fa-solid fa-briefcase',
                        'text'  => 'Presupuesto'
                    ],
                ],
                'cards' => [
                    [
                        'icon' => 'fa-solid fa-users',
                        'title' => 'Gestión de Talento',
                        'text' => 'Programas y herramientas para el bienestar laboral.',
                        'route' => 'Disciplina.show'
                    ],
                    [
                        'icon' => 'fa-solid fa-briefcase',
                        'title' => 'Oportunidades Laborales',
                        'text' => 'Explora y postúlate a vacantes dentro de la organización.',
                        'route' => 'home'
                    ],
                    [
                        'icon' => 'fa-solid fa-circle-info',
                        'title' => 'Centro de Información',
                        'text' => 'Consulta comunicados y documentos institucionales.',
                        'route' => '#'
                    ],
                ],
            ],

            'presupuesto' => [
                'title' => 'Módulo de Presupuesto',
                'subtitle' => 'Gestiona, consulta y crea presupuestos corporativos.',
                'buttons' => [
                    [
                        'route' => '/panel/CashierAwardsUsers',
                        'class' => 'btn btn-primary',
                        'icon'  => 'fa-solid fa-chart-line',
                        'text'  => 'Cajeros'
                    ],
                    [
                        'route' => '/panel/CommisionsUser',
                        'class' => 'btn btn-primary',
                        'icon'  => 'fa-solid fa-chart-line',
                        'text'  => 'Asesores'
                    ],
                    [
                        'route' => '/panel/',
                        'class' => 'btn btn-outline',
                        'icon'  => 'fa-solid fa-plus',
                        'text'  => 'Administrador'
                    ],
                    
                    [
                        'route' => 'welcome',
                        'class' => 'btn btn-outline',
                        'icon'  => 'fa-solid fa-arrow-left',
                        'text'  => 'Volver al Portal'
                    ],
                ],
                'cards' => [
                    [
                        'icon' => 'fa-solid fa-file-invoice-dollar',
                        'title' => 'Acceso Cajeros',
                        'text' => 'Revisa tus ventas y comisiones',
                        'route' => '/panel/CashierAwardsUsers'
                    ],
                    [
                        'icon' => 'fa-solid fa-chart-pie',
                        'title' => 'Reportes',
                        'text' => 'Análisis y estado financiero por áreas.',
                        'route' => '#'
                    ],
                    [
                        'icon' => 'fa-solid fa-circle-info',
                        'title' => 'Ayuda & Guías',
                        'text' => 'Documentación para la gestión presupuestal.',
                        'route' => '#'
                    ],
                ],
            ],

        ];

        return $portals[$type] ?? $portals['main'];
    }
}

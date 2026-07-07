<?php

namespace App\Console\Commands;

use App\Services\SalesRoleRectificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RectifySalesRoles extends Command
{
    protected $signature = 'commissions:rectify-sales-roles
        {--budget_id= : Presupuesto a usar como rango}
        {--start_date= : Fecha inicial YYYY-MM-DD}
        {--end_date= : Fecha final YYYY-MM-DD}
        {--user_id=* : Usuario puntual a rectificar; se puede repetir}
        {--chunk-size=0 : Procesa usuarios en grupos para evitar locks}
        {--dry-run : Calcula sin escribir en user_roles}
        {--show-ranges : Muestra los rangos calculados}';

    protected $description = 'Rectifica roles Vendedor/cajero desde ventas para el calculo de comisiones.';

    public function handle(SalesRoleRectificationService $service): int
    {
        $budgetId = $this->option('budget_id') ? (int) $this->option('budget_id') : null;
        $startDate = $this->option('start_date');
        $endDate = $this->option('end_date');
        $dryRun = (bool) $this->option('dry-run');
        $showRanges = (bool) $this->option('show-ranges');
        $chunkSize = max(0, (int) $this->option('chunk-size'));
        $userIds = array_values(array_filter(array_map('intval', (array) $this->option('user_id'))));

        if ($budgetId) {
            $budget = DB::connection('budget')->table('budgets')->where('id', $budgetId)->first();

            if (!$budget) {
                $this->error("No existe el presupuesto {$budgetId}.");
                return self::FAILURE;
            }

            $startDate = Carbon::parse($budget->start_date)->toDateString();
            $endDate = Carbon::parse($budget->end_date)->toDateString();
        }

        if (!$startDate || !$endDate) {
            $this->error('Debes pasar --budget_id o el par --start_date/--end_date.');
            return self::FAILURE;
        }

        $startDate = Carbon::parse($startDate)->toDateString();
        $endDate = Carbon::parse($endDate)->toDateString();

        if (!$dryRun && $chunkSize > 0) {
            $preview = $service->rectifyRange($startDate, $endDate, $userIds ?: null, true);
            $targetUserIds = array_values(array_unique(array_map(
                fn ($range) => (int) $range['user_id'],
                $preview['ranges'] ?? []
            )));

            if (empty($targetUserIds)) {
                $this->info('No hay usuarios para rectificar.');
                return self::SUCCESS;
            }

            $result = $preview;
            $result['dry_run'] = false;
            $result['trimmed_rows'] = 0;
            $result['deleted_rows'] = 0;
            $result['inserted_rows'] = 0;
            $result['merged_rows'] = 0;
            $result['backup_rows'] = 0;
            $result['backup_keys'] = [];

            foreach (array_chunk($targetUserIds, $chunkSize) as $index => $chunk) {
                $this->line('Procesando lote ' . ($index + 1) . ' de ' . ceil(count($targetUserIds) / $chunkSize) . ' (' . count($chunk) . ' usuarios)...');
                $chunkResult = $service->rectifyRange($startDate, $endDate, $chunk, false);

                foreach (['trimmed_rows', 'deleted_rows', 'inserted_rows', 'merged_rows', 'backup_rows'] as $key) {
                    $result[$key] += (int) ($chunkResult[$key] ?? 0);
                }

                if (!empty($chunkResult['backup_key'])) {
                    $result['backup_keys'][] = $chunkResult['backup_key'];
                }
            }
        } else {
            $result = $service->rectifyRange(
                $startDate,
                $endDate,
                $userIds ?: null,
                $dryRun
            );
        }

        $this->info($dryRun ? 'Rectificacion calculada (dry-run).' : 'Rectificacion aplicada.');
        $this->line('Rango: ' . $result['start_date'] . ' -> ' . $result['end_date']);
        $this->line('Usuarios afectados: ' . $result['users_count']);
        $this->line('Rangos calculados: ' . $result['ranges_count']);

        if ($dryRun) {
            $this->line('Cambios en BD: no aplicados.');
        } else {
            $this->line('Filas recortadas: ' . ($result['trimmed_rows'] ?? 0));
            $this->line('Filas eliminadas: ' . ($result['deleted_rows'] ?? 0));
            $this->line('Filas insertadas: ' . ($result['inserted_rows'] ?? 0));
            $this->line('Filas fusionadas: ' . ($result['merged_rows'] ?? 0));
            if (!empty($result['backup_keys'])) {
                $this->line('Backups: ' . implode(', ', $result['backup_keys']));
            } elseif (!empty($result['backup_key'])) {
                $this->line('Backup: ' . $result['backup_key']);
            }
        }

        if ($showRanges && !empty($result['ranges'])) {
            $this->table(
                ['user_id', 'role_id', 'start_date', 'end_date'],
                array_slice($result['ranges'], 0, 200)
            );

            if (count($result['ranges']) > 200) {
                $this->line('Mostrando 200 rangos de ' . count($result['ranges']) . '.');
            }
        }

        return self::SUCCESS;
    }
}

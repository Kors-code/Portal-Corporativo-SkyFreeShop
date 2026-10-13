<?php

namespace App\Console\Commands;

use App\Services\BudgetClosureService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class CloseMonthlyBudgets extends Command
{
    protected $signature = 'budgets:close-monthly
        {--budget_id= : Cierra un presupuesto puntual}
        {--date= : Fecha de referencia YYYY-MM-DD, por defecto hoy en America/Bogota}
        {--force : Rehacer snapshots aunque el presupuesto ya este cerrado}';

    protected $description = 'Cierra presupuestos que ya pasaron su ventana de revision y guarda snapshots historicos.';

    public function handle(BudgetClosureService $service): int
    {
        $date = $this->option('date')
            ? Carbon::parse((string) $this->option('date'), 'America/Bogota')
            : now('America/Bogota');

        $budgetId = $this->option('budget_id') ? (int) $this->option('budget_id') : null;
        $force = (bool) $this->option('force');
        $budgetIds = $budgetId ? [$budgetId] : $service->eligibleBudgetsForAutomaticClosure($date);

        if (empty($budgetIds)) {
            $this->info('No hay presupuestos elegibles para cierre.');
            return self::SUCCESS;
        }

        foreach ($budgetIds as $id) {
            try {
                $result = $service->closeBudget((int) $id, 'automatic', null, $force);

                if (($result['status'] ?? null) === 'already_closed') {
                    $this->line("Presupuesto {$id} ya estaba cerrado.");
                    continue;
                }

                $this->info(sprintf(
                    'Presupuesto %d cerrado. Snapshots: %d. Revision hasta: %s',
                    $id,
                    (int) ($result['snapshots_created'] ?? 0),
                    $result['review_until'] ?? 'N/A'
                ));
            } catch (\Throwable $e) {
                $this->error("Error cerrando presupuesto {$id}: {$e->getMessage()}");
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}

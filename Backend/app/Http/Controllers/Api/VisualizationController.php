<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DailyWhatsappReportImageService;
use App\Services\WhatsappReportSender;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VisualizationController extends Controller
{
    protected function budgetDB()
    {
        return DB::connection('budget');
    }

    public function cashRegisterClosure(Request $request)
    {
        $pdvsFilter = $this->normalizePdvs($request);
        $budgetId = $request->query('budget_id');
        $date = $request->query('date');
        $rangeStartInput = $request->query('start_date');
        $rangeEndInput = $request->query('end_date');
        $availableRange = $this->availableDateRange();

        $budget = null;
        if ($budgetId) {
            $budget = $this->budgetDB()->table('budgets')->where('id', $budgetId)->first();
        }

        if (!$budget) {
            $date = $date ?: $this->defaultVisualizationDate();
            $dateObj = new \DateTimeImmutable($date);
            $monthStart = $dateObj->modify('first day of this month')->format('Y-m-d');
            $monthEnd = $dateObj->modify('last day of this month')->format('Y-m-d');

            $budget = $this->budgetDB()->table('budgets')
                ->where('start_date', '<=', $date)
                ->where('end_date', '>=', $date)
                ->orderByDesc('start_date')
                ->first();

            if (!$budget) {
                $budget = $this->budgetDB()->table('budgets')
                    ->where('start_date', '<=', $monthEnd)
                    ->where('end_date', '>=', $monthStart)
                    ->orderByDesc('start_date')
                    ->first();
            }
        }

        if ($budget && !$rangeStartInput && !$rangeEndInput) {
            $rangeStart = (new \DateTimeImmutable((string) $budget->start_date))->format('Y-m-d');
            $rangeEnd = (new \DateTimeImmutable((string) $budget->end_date))->format('Y-m-d');
        } else {
            $date = $date ?: $this->defaultVisualizationDate();
            $dateObj = new \DateTimeImmutable($date);
            $rangeStart = $rangeStartInput ?: $dateObj->modify('first day of this month')->format('Y-m-d');
            $rangeEnd = $rangeEndInput ?: $dateObj->modify('last day of this month')->format('Y-m-d');
        }

        if ($rangeStart < $availableRange['start']) {
            $rangeStart = $availableRange['start'];
        }
        if ($rangeEnd > $availableRange['end']) {
            $rangeEnd = $availableRange['end'];
        }

        if ($rangeStart > $rangeEnd) {
            [$rangeStart, $rangeEnd] = [$rangeEnd, $rangeStart];
        }

        $rangeStartObj = new \DateTimeImmutable($rangeStart);
        $rangeEndObj = new \DateTimeImmutable($rangeEnd);
        $daysInRange = $rangeStartObj->diff($rangeEndObj)->days + 1;

        $budgetByDate = $this->budgetDailyByDate($rangeStart, $rangeEnd);
        $rangeBudget = round(array_sum($budgetByDate), 2);
        $budgetDaily = $daysInRange > 0 ? round($rangeBudget / $daysInRange, 2) : 0;
        $monthlyBudget = $budget ? (float) $budget->target_amount : $rangeBudget;
        $date = $rangeEnd;

        $dayBase = $this->budgetDB()->table('sales as s')
            ->whereDate('s.sale_date', $date)
            ->when(!empty($pdvsFilter), fn ($q) => $q->whereIn('s.pdv', $pdvsFilter));
        $this->excludeGpwCategory($dayBase);

        $periodBase = $this->budgetDB()->table('sales as s')
            ->whereBetween('s.sale_date', [$rangeStart, $rangeEnd])
            ->when(!empty($pdvsFilter), fn ($q) => $q->whereIn('s.pdv', $pdvsFilter));
        $this->excludeGpwCategory($periodBase);

        $summary = (clone $dayBase)
            ->selectRaw("
                COALESCE(SUM(s.amount_cop), 0) as total_cop,
                COALESCE(SUM(s.value_usd), 0) as total_usd,
                COALESCE(SUM(s.quantity), 0) as units,
                COUNT(*) as rows_count,
                COUNT(DISTINCT COALESCE(NULLIF(s.folio, ''), CONCAT('row-', s.id))) as tickets,
                COUNT(DISTINCT NULLIF(TRIM(s.cashier), '')) as cashiers_count,
                AVG(NULLIF(s.exchange_rate, 0)) as avg_exchange_rate
            ")
            ->first();

        $totalUsd = (float) ($summary->total_usd ?? 0);
        $tickets = (int) ($summary->tickets ?? 0);
        $periodSalesUsd = (float) (clone $periodBase)->sum(DB::raw('COALESCE(s.value_usd, 0)'));

        $hourly = (clone $dayBase)
            ->selectRaw("
                LPAD(HOUR(COALESCE(s.sale_datetime, CONCAT(s.sale_date, ' ', COALESCE(s.hora, '00:00:00')))), 2, '0') as hour,
                COALESCE(SUM(s.amount_cop), 0) as sales_cop,
                COALESCE(SUM(s.value_usd), 0) as sales_usd,
                COUNT(DISTINCT COALESCE(NULLIF(s.folio, ''), CONCAT('row-', s.id))) as tickets
            ")
            ->groupBy('hour')
            ->orderBy('hour')
            ->get()
            ->map(fn ($row) => [
                'hour' => $row->hour . ':00',
                'sales_cop' => (int) $row->sales_cop,
                'sales_usd' => round((float) $row->sales_usd, 2),
                'tickets' => (int) $row->tickets,
            ]);

        $byPdv = (clone $dayBase)
            ->selectRaw("
                COALESCE(NULLIF(TRIM(s.pdv), ''), 'Sin PDV') as pdv,
                COALESCE(SUM(s.amount_cop), 0) as sales_cop,
                COALESCE(SUM(s.value_usd), 0) as sales_usd,
                COUNT(DISTINCT COALESCE(NULLIF(s.folio, ''), CONCAT('row-', s.id))) as tickets
            ")
            ->groupBy('pdv')
            ->orderByDesc('sales_usd')
            ->get()
            ->map(fn ($row) => [
                'pdv' => $row->pdv,
                'sales_cop' => (int) $row->sales_cop,
                'sales_usd' => round((float) $row->sales_usd, 2),
                'tickets' => (int) $row->tickets,
                'pct' => $totalUsd > 0 ? round(((float) $row->sales_usd / $totalUsd) * 100, 2) : 0,
            ]);

        $cashiers = (clone $dayBase)
            ->selectRaw("
                COALESCE(NULLIF(TRIM(s.cashier), ''), 'Sin cajero') as cashier,
                COALESCE(SUM(s.amount_cop), 0) as sales_cop,
                COALESCE(SUM(s.value_usd), 0) as sales_usd,
                COUNT(DISTINCT COALESCE(NULLIF(s.folio, ''), CONCAT('row-', s.id))) as tickets
            ")
            ->groupBy('cashier')
            ->orderByDesc('sales_usd')
            ->limit(12)
            ->get()
            ->map(fn ($row) => [
                'cashier' => $row->cashier,
                'sales_cop' => (int) $row->sales_cop,
                'sales_usd' => round((float) $row->sales_usd, 2),
                'tickets' => (int) $row->tickets,
                'avg_ticket_usd' => (int) $row->tickets > 0 ? round((float) $row->sales_usd / (int) $row->tickets, 2) : 0,
            ]);

        $categories = (clone $periodBase)
            ->leftJoin('products as p', 'p.id', '=', 's.product_id')
            ->selectRaw("
                COALESCE(NULLIF(TRIM(p.classification_desc), ''), NULLIF(TRIM(p.classification), ''), 'Sin categoria') as category,
                COALESCE(SUM(s.value_usd), 0) as sales_usd
            ")
            ->groupBy('category')
            ->orderByDesc('sales_usd')
            ->limit(8)
            ->get()
            ->map(fn ($row) => [
                'category' => $row->category,
                'sales_usd' => round((float) $row->sales_usd, 2),
                'pct' => $periodSalesUsd > 0 ? round(((float) $row->sales_usd / $periodSalesUsd) * 100, 2) : 0,
            ]);

        $transactions = (clone $dayBase)
            ->leftJoin('products as p', 'p.id', '=', 's.product_id')
            ->select([
                's.id',
                's.sale_date',
                's.hora',
                's.folio',
                's.pdv',
                's.cashier',
                's.amount_cop',
                's.value_usd',
                's.quantity',
                'p.description as product',
            ])
            ->orderByDesc(DB::raw("COALESCE(s.sale_datetime, CONCAT(s.sale_date, ' ', COALESCE(s.hora, '00:00:00')))"))
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'id' => $row->id,
                'time' => $row->hora,
                'folio' => $row->folio,
                'pdv' => $row->pdv,
                'cashier' => $row->cashier ?: 'Sin cajero',
                'product' => $row->product ?: 'Producto sin descripcion',
                'quantity' => (float) $row->quantity,
                'amount_cop' => (int) $row->amount_cop,
                'value_usd' => round((float) $row->value_usd, 2),
            ]);

        $dailyRaw = (clone $periodBase)
            ->selectRaw("
                DATE(s.sale_date) as day_date,
                COALESCE(SUM(s.amount_cop), 0) as sales_cop,
                COALESCE(SUM(s.value_usd), 0) as sales_usd,
                COALESCE(SUM(s.quantity), 0) as units,
                COUNT(DISTINCT COALESCE(NULLIF(s.folio, ''), CONCAT('row-', s.id))) as tickets,
                COUNT(*) as rows_count,
                AVG(NULLIF(s.exchange_rate, 0)) as avg_exchange_rate
            ")
            ->groupBy('day_date')
            ->get()
            ->keyBy('day_date');

        $dailyPerformance = [];
        $cursor = $rangeStartObj;
        for ($day = 1; $day <= $daysInRange; $day++) {
            $dayDate = $cursor->format('Y-m-d');
            $row = $dailyRaw->get($dayDate);
            $salesUsd = (float) ($row->sales_usd ?? 0);
            $salesCop = (int) ($row->sales_cop ?? 0);
            $dayTickets = (int) ($row->tickets ?? 0);
            $dayBudgetDaily = round((float) ($budgetByDate[$dayDate] ?? 0), 2);

            $dailyPerformance[] = [
                'date' => $dayDate,
                'year' => (int) $cursor->format('Y'),
                'month' => $cursor->format('F'),
                'day' => (int) $cursor->format('j'),
                'weekday' => $cursor->format('D'),
                'sales_usd' => round($salesUsd, 2),
                'sales_cop' => $salesCop,
                'budget_daily_usd' => $dayBudgetDaily,
                'diff_usd' => round($salesUsd - $dayBudgetDaily, 2),
                'compliance_pct' => $dayBudgetDaily > 0 ? round(($salesUsd / $dayBudgetDaily) * 100, 1) : 0,
                'units' => (float) ($row->units ?? 0),
                'trx' => $dayTickets,
                'tkt_usd' => $dayTickets > 0 ? round($salesUsd / $dayTickets, 2) : 0,
                'avg_exchange_rate' => round((float) ($row->avg_exchange_rate ?? 0), 2),
                'is_selected' => $dayDate === $date,
            ];

            $cursor = $cursor->modify('+1 day');
        }

        $pdvs = $this->budgetDB()->table('sales')
            ->whereNotNull('pdv')
            ->whereRaw("TRIM(pdv) <> ''")
            ->distinct()
            ->orderBy('pdv')
            ->pluck('pdv')
            ->values();

        $budgets = $this->budgetDB()->table('budgets')
            ->select('id', 'name', 'target_amount', 'start_date', 'end_date')
            ->orderByDesc('start_date')
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'name' => $row->name,
                'target_amount' => round((float) $row->target_amount, 2),
                'start_date' => $row->start_date,
                'end_date' => $row->end_date,
            ])
            ->values();

        return response()->json([
            'date' => $date,
            'filters' => ['pdvs' => $pdvsFilter, 'budget_id' => $budget->id ?? null],
            'pdvs' => $pdvs,
            'budgets' => $budgets,
            'available_period' => $availableRange,
            'budget' => [
                'id' => $budget->id ?? null,
                'name' => $budget->name ?? null,
                'monthly_usd' => round($monthlyBudget, 2),
                'days_in_month' => $daysInRange,
                'days_in_range' => $daysInRange,
                'budget_daily_usd' => $budgetDaily,
                'month_sales_usd' => round($periodSalesUsd, 2),
                'month_diff_usd' => round($periodSalesUsd - $rangeBudget, 2),
                'month_compliance_pct' => $rangeBudget > 0 ? round(($periodSalesUsd / $rangeBudget) * 100, 1) : 0,
                'range_budget_usd' => $rangeBudget,
                'period' => ['start' => $rangeStart, 'end' => $rangeEnd],
                'range' => ['start' => $rangeStart, 'end' => $rangeEnd],
            ],
            'summary' => [
                'total_cop' => (int) ($summary->total_cop ?? 0),
                'total_usd' => round($totalUsd, 2),
                'tickets' => $tickets,
                'rows_count' => (int) ($summary->rows_count ?? 0),
                'units' => (float) ($summary->units ?? 0),
                'cashiers_count' => (int) ($summary->cashiers_count ?? 0),
                'avg_exchange_rate' => round((float) ($summary->avg_exchange_rate ?? 0), 2),
                'avg_ticket_usd' => $tickets > 0 ? round($totalUsd / $tickets, 2) : 0,
            ],
            'hourly' => $hourly,
            'by_pdv' => $byPdv,
            'cashiers' => $cashiers,
            'categories' => $categories,
            'daily_performance' => $dailyPerformance,
            'transactions' => $transactions,
        ]);
    }

    public function whatsappDailyReportPreview(Request $request, DailyWhatsappReportImageService $imageService)
    {
        $report = $this->dailyWhatsappReportData($request);

        return response($imageService->make($report), 200, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => 'inline; filename="daily-whatsapp-report.png"',
        ]);
    }

    public function sendWhatsappDailyReport(
        Request $request,
        DailyWhatsappReportImageService $imageService,
        WhatsappReportSender $sender
    ) {
        $report = $this->dailyWhatsappReportData($request);
        $images = $imageService->makeImages($report);
        $caption = sprintf(
            'Daily ventas %s - %s',
            $report['budget']['period']['end'] ?? $report['date'] ?? now('America/Bogota')->toDateString(),
            $report['budget']['name'] ?? 'Presupuesto activo'
        );

        if (count($images) === 1) {
            $result = [$sender->sendImage((string) $images[0]['bytes'], $caption)];
        } else {
            $result = $sender->sendImages($images);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Reporte enviado a WhatsApp.',
            'images_count' => count($images),
            'whatsapp' => $result,
        ]);
    }

    protected function dailyWhatsappReportData(Request $request): array
    {
        $today = now('America/Bogota')->toDateString();
        $date = $request->query('date', $request->input('date', $today));
        $date = (new \DateTimeImmutable((string) $date))->format('Y-m-d');

        $budget = $this->budgetDB()->table('budgets')
            ->where('start_date', '<=', $date)
            ->where('end_date', '>=', $date)
            ->orderByDesc('start_date')
            ->first();

        if (!$budget) {
            abort(422, 'No hay presupuesto activo para la fecha seleccionada.');
        }

        $dailyRequest = Request::create($request->path(), 'GET', [
            'budget_id' => $budget->id,
            'start_date' => (new \DateTimeImmutable((string) $budget->start_date))->format('Y-m-d'),
            'end_date' => $date,
            'pdvs' => $this->normalizePdvs($request),
        ]);

        return $this->cashRegisterClosure($dailyRequest)->getData(true);
    }

    protected function normalizePdvs(Request $request): array
    {
        $raw = $request->query('pdvs', $request->query('pdv', []));

        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }

        if (!is_array($raw)) {
            return [];
        }

        return collect($raw)
            ->map(fn ($item) => trim((string) $item))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    protected function excludeGpwCategory($query): void
    {
        $query->whereNotExists(function ($q) {
            $q->selectRaw('1')
                ->from('products as px')
                ->whereColumn('px.id', 's.product_id')
                ->where(function ($qq) {
                    $qq->whereRaw("UPPER(TRIM(COALESCE(px.classification, ''))) = '98'")
                        ->orWhereRaw("UPPER(TRIM(COALESCE(px.classification_desc, ''))) = 'GPW'");
                });
        });
    }

    protected function availableDateRange(): array
    {
        $budgetStart = $this->budgetDB()->table('budgets')->min('start_date');
        $budgetEnd = $this->budgetDB()->table('budgets')->max('end_date');
        $salesStart = $this->budgetDB()->table('sales')->min('sale_date');
        $salesEnd = $this->budgetDB()->table('sales')->max('sale_date');

        $startCandidates = array_filter([$budgetStart, $salesStart]);
        $endCandidates = array_filter([$budgetEnd, $salesEnd]);

        return [
            'start' => $startCandidates ? (new \DateTimeImmutable((string) min($startCandidates)))->format('Y-m-d') : now('America/Bogota')->toDateString(),
            'end' => $endCandidates ? (new \DateTimeImmutable((string) max($endCandidates)))->format('Y-m-d') : now('America/Bogota')->toDateString(),
        ];
    }

    protected function budgetDailyByDate(string $rangeStart, string $rangeEnd): array
    {
        $rows = $this->budgetDB()->table('budgets')
            ->where('start_date', '<=', $rangeEnd)
            ->where('end_date', '>=', $rangeStart)
            ->select('target_amount', 'start_date', 'end_date')
            ->get();

        $budgetByDate = [];

        foreach ($rows as $row) {
            $budgetStart = new \DateTimeImmutable((string) $row->start_date);
            $budgetEnd = new \DateTimeImmutable((string) $row->end_date);
            $days = $budgetStart->diff($budgetEnd)->days + 1;
            $daily = $days > 0 ? ((float) $row->target_amount / $days) : 0;

            $overlapStart = max($budgetStart->format('Y-m-d'), $rangeStart);
            $overlapEnd = min($budgetEnd->format('Y-m-d'), $rangeEnd);
            $cursor = new \DateTimeImmutable($overlapStart);
            $end = new \DateTimeImmutable($overlapEnd);

            while ($cursor <= $end) {
                $budgetByDate[$cursor->format('Y-m-d')] = ($budgetByDate[$cursor->format('Y-m-d')] ?? 0) + $daily;
                $cursor = $cursor->modify('+1 day');
            }
        }

        return $budgetByDate;
    }

    protected function defaultVisualizationDate(): string
    {
        $latestSaleDate = $this->budgetDB()->table('sales as s')
            ->whereExists(function ($q) {
                $q->selectRaw('1')
                    ->from('budgets as b')
                    ->whereColumn('b.start_date', '<=', 's.sale_date')
                    ->whereColumn('b.end_date', '>=', 's.sale_date');
            })
            ->max('s.sale_date');

        if ($latestSaleDate) {
            return (new \DateTimeImmutable((string) $latestSaleDate))->format('Y-m-d');
        }

        $latestBudgetEnd = $this->budgetDB()->table('budgets')->max('end_date');

        if ($latestBudgetEnd) {
            return (new \DateTimeImmutable((string) $latestBudgetEnd))->format('Y-m-d');
        }

        return now('America/Bogota')->toDateString();
    }
}

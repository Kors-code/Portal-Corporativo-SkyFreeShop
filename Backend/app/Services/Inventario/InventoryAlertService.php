<?php

namespace App\Services\Inventario;

use Illuminate\Mail\Message;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class InventoryAlertService
{
    private const STRONG_LEVELS = ['critico', 'sin_stock'];
    private const NOTICE_LEVELS = ['alto'];
    public function __construct(private InventoryReportService $reportService)
    {
    }

    public function lists(): array
    {
        $lists = DB::connection('budget')
            ->table('inventory_alert_lists')
            ->orderByDesc('id')
            ->get();

        if ($lists->isEmpty()) {
            return [];
        }

        $ids = $lists->pluck('id')->all();
        $stores = DB::connection('budget')->table('inventory_alert_list_stores as ls')
            ->join('stores as s', 's.id', '=', 'ls.store_id')
            ->whereIn('ls.list_id', $ids)
            ->select('ls.list_id', 's.id', 's.code', 's.name')
            ->orderBy('s.name')
            ->get()
            ->groupBy('list_id');

        $productCounts = DB::connection('budget')->table('inventory_alert_list_products')
            ->whereIn('list_id', $ids)
            ->select('list_id')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('list_id')
            ->pluck('total', 'list_id');

        $recipientCounts = DB::connection('budget')->table('inventory_alert_recipients')
            ->whereIn('list_id', $ids)
            ->where('is_active', true)
            ->select('list_id')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('list_id')
            ->pluck('total', 'list_id');

        return $lists->map(fn ($list) => [
            'id' => (int) $list->id,
            'name' => $list->name,
            'is_active' => (bool) $list->is_active,
            'auto_send' => (bool) $list->auto_send,
            'frequency_days' => (int) ($list->frequency_days ?? 1),
            'top_months' => (int) $list->top_months,
            'top_limit' => (int) $list->top_limit,
            'stores' => ($stores[$list->id] ?? collect())->values()->all(),
            'products_count' => (int) ($productCounts[$list->id] ?? 0),
            'recipients_count' => (int) ($recipientCounts[$list->id] ?? 0),
            'created_at' => $list->created_at,
            'updated_at' => $list->updated_at,
        ])->values()->all();
    }

    public function getList(int $id): array
    {
        $list = DB::connection('budget')->table('inventory_alert_lists')->where('id', $id)->first();
        if (!$list) {
            abort(404, 'Lista de alertas no encontrada.');
        }

        return [
            'id' => (int) $list->id,
            'name' => $list->name,
            'is_active' => (bool) $list->is_active,
            'auto_send' => (bool) $list->auto_send,
            'frequency_days' => (int) ($list->frequency_days ?? 1),
            'top_months' => (int) $list->top_months,
            'top_limit' => (int) $list->top_limit,
            'stores' => $this->listStores($id),
            'products' => $this->listProducts($id),
            'recipients' => $this->listRecipients($id),
            'current_alerts' => [],
            'history' => $this->history($id, 12),
        ];
    }

    public function saveList(array $data, ?int $id = null, ?int $userId = null): array
    {
        $storeIds = collect($data['store_ids'] ?? [])->map(fn ($value) => (int) $value)->filter()->unique()->values();
        if ($storeIds->isEmpty()) {
            abort(422, 'Selecciona al menos una tienda.');
        }

        $payload = [
            'name' => trim((string) $data['name']),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'auto_send' => (bool) ($data['auto_send'] ?? true),
            'frequency_days' => max(1, min(30, (int) ($data['frequency_days'] ?? 1))),
            'top_months' => max(1, min(12, (int) ($data['top_months'] ?? 3))),
            'top_limit' => max(1, min(200, (int) ($data['top_limit'] ?? 50))),
            'updated_by' => $userId,
            'updated_at' => now(),
        ];

        DB::connection('budget')->transaction(function () use ($payload, $id, $userId, $storeIds, &$data) {
            if ($id) {
                DB::connection('budget')->table('inventory_alert_lists')->where('id', $id)->update($payload);
                $listId = $id;
            } else {
                $listId = DB::connection('budget')->table('inventory_alert_lists')->insertGetId([
                    ...$payload,
                    'created_by' => $userId,
                    'created_at' => now(),
                ]);
            }

            DB::connection('budget')->table('inventory_alert_list_stores')->where('list_id', $listId)->delete();
            foreach ($storeIds as $storeId) {
                DB::connection('budget')->table('inventory_alert_list_stores')->insert([
                    'list_id' => $listId,
                    'store_id' => $storeId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            if (array_key_exists('recipients', $data)) {
                $this->replaceRecipients($listId, $data['recipients'] ?? []);
            }

            if (array_key_exists('product_ids', $data)) {
                $this->replaceProducts($listId, $data['product_ids'] ?? [], 'manual');
            }

            $data['id'] = $listId;
        });

        return $this->getList((int) $data['id']);
    }

    public function deleteList(int $id): void
    {
        DB::connection('budget')->table('inventory_alert_lists')->where('id', $id)->delete();
    }

    public function searchProducts(?string $search, int $limit = 20): array
    {
        $q = trim((string) $search);

        return DB::connection('budget')->table('products')
            ->select('id', 'product_code', 'description', 'brand', 'provider_name')
            ->when($q !== '', function ($query) use ($q) {
                $term = '%' . $q . '%';
                $query->where(function ($sub) use ($term) {
                    $sub->where('product_code', 'like', $term)
                        ->orWhere('description', 'like', $term)
                        ->orWhere('brand', 'like', $term)
                        ->orWhere('provider_name', 'like', $term);
                });
            })
            ->orderBy('product_code')
            ->limit(max(1, min(50, $limit)))
            ->get()
            ->map(fn ($product) => [
                'id' => (int) $product->id,
                'product_code' => $product->product_code,
                'description' => $product->description,
                'brand' => $product->brand,
                'provider_name' => $product->provider_name,
            ])
            ->all();
    }

    public function topProducts(array $storeIds, int $months = 3, int $limit = 50, bool $useCache = true): array
    {
        $storeIds = collect($storeIds)->map(fn ($value) => (int) $value)->filter()->unique()->values()->all();
        if (empty($storeIds)) {
            abort(422, 'Selecciona al menos una tienda.');
        }

        $months = max(1, min(12, $months));
        $limit = max(1, min(200, $limit));

        if ($useCache) {
            $cached = $this->cachedTopProducts($storeIds, $months, $limit);
            if ($cached !== null) {
                return $cached;
            }
        }

        $rows = $this->calculateTopProducts($storeIds, $months, $limit);
        $this->storeTopProductsCache($storeIds, $months, $limit, $rows);

        return $rows;
    }

    public function warmTopCaches(bool $force = false): array
    {
        $lists = DB::connection('budget')->table('inventory_alert_lists')
            ->select('id', 'name', 'top_months', 'top_limit')
            ->orderBy('id')
            ->get();

        $results = [];
        foreach ($lists as $list) {
            $storeIds = DB::connection('budget')->table('inventory_alert_list_stores')
                ->where('list_id', $list->id)
                ->pluck('store_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if (empty($storeIds)) {
                $results[] = ['list_id' => (int) $list->id, 'status' => 'skipped', 'message' => 'Lista sin tiendas.'];
                continue;
            }

            try {
                $rows = $this->topProducts($storeIds, (int) $list->top_months, (int) $list->top_limit, !$force);
                $results[] = [
                    'list_id' => (int) $list->id,
                    'status' => 'cached',
                    'rows' => count($rows),
                ];
            } catch (\Throwable $e) {
                Log::error('Error calentando cache de top de inventario', [
                    'list_id' => (int) $list->id,
                    'error' => $e->getMessage(),
                ]);
                $results[] = ['list_id' => (int) $list->id, 'status' => 'failed', 'message' => $e->getMessage()];
            }
        }

        return $results;
    }

    private function calculateTopProducts(array $storeIds, int $months, int $limit): array
    {
        $startDate = Carbon::today()->subMonths($months)->toDateString();

        return DB::connection('budget')->table('sales as s')
            ->join('products as p', 'p.id', '=', 's.product_id')
            ->whereIn('s.store_id', $storeIds)
            ->whereDate('s.sale_date', '>=', $startDate)
            ->select('p.id', 'p.product_code', 'p.description', 'p.brand', 'p.provider_name')
            ->selectRaw('SUM(COALESCE(s.value_usd, 0)) as total_usd')
            ->selectRaw('SUM(COALESCE(s.quantity, 0)) as total_units')
            ->groupBy('p.id', 'p.product_code', 'p.description', 'p.brand', 'p.provider_name')
            ->orderByDesc('total_usd')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'product_code' => $row->product_code,
                'description' => $row->description,
                'brand' => $row->brand,
                'provider_name' => $row->provider_name,
                'total_usd' => (float) $row->total_usd,
                'total_units' => (float) $row->total_units,
            ])
            ->all();
    }

    private function cachedTopProducts(array $storeIds, int $months, int $limit): ?array
    {
        $cache = DB::connection('budget')->table('inventory_alert_top_cache')
            ->where('cache_key', $this->topCacheKey($storeIds, $months, $limit))
            ->where('computed_at', '>=', Carbon::today()->startOfDay())
            ->first();

        if (!$cache) {
            return null;
        }

        $products = json_decode($cache->products_json ?? '[]', true);
        return is_array($products) ? $products : null;
    }

    private function storeTopProductsCache(array $storeIds, int $months, int $limit, array $products): void
    {
        DB::connection('budget')->table('inventory_alert_top_cache')->updateOrInsert(
            ['cache_key' => $this->topCacheKey($storeIds, $months, $limit)],
            [
                'store_ids_json' => json_encode($this->normalizedTopStoreIds($storeIds)),
                'months' => $months,
                'limit' => $limit,
                'products_json' => json_encode($products),
                'computed_at' => now(),
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    private function topCacheKey(array $storeIds, int $months, int $limit): string
    {
        return hash('sha256', json_encode([
            'store_ids' => $this->normalizedTopStoreIds($storeIds),
            'months' => $months,
            'limit' => $limit,
        ]));
    }

    private function normalizedTopStoreIds(array $storeIds): array
    {
        return collect($storeIds)
            ->map(fn ($value) => (int) $value)
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    public function addTopToList(int $listId, int $months, int $limit): array
    {
        $storeIds = DB::connection('budget')->table('inventory_alert_list_stores')
            ->where('list_id', $listId)
            ->pluck('store_id')
            ->all();
        $top = $this->topProducts($storeIds, $months, $limit);

        foreach ($top as $product) {
            DB::connection('budget')->table('inventory_alert_list_products')->updateOrInsert(
                ['list_id' => $listId, 'product_id' => $product['id']],
                ['source' => 'top', 'updated_at' => now(), 'created_at' => now()]
            );
        }

        return $this->listProducts($listId);
    }

    public function addProduct(int $listId, int $productId): array
    {
        DB::connection('budget')->table('inventory_alert_list_products')->updateOrInsert(
            ['list_id' => $listId, 'product_id' => $productId],
            ['source' => 'manual', 'updated_at' => now(), 'created_at' => now()]
        );

        return $this->listProducts($listId);
    }

    public function removeProduct(int $listId, int $productId): array
    {
        DB::connection('budget')->table('inventory_alert_list_products')
            ->where('list_id', $listId)
            ->where('product_id', $productId)
            ->delete();

        return $this->listProducts($listId);
    }

    public function sendList(int $listId, string $mode = 'manual', bool $force = false, bool $test = false): array
    {
        $list = DB::connection('budget')->table('inventory_alert_lists')->where('id', $listId)->first();
        if (!$list) {
            abort(404, 'Lista de alertas no encontrada.');
        }

        $runId = DB::connection('budget')->table('inventory_alert_runs')->insertGetId([
            'list_id' => $listId,
            'mode' => $test ? 'test' : $mode,
            'status' => 'running',
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $recipients = collect($this->listRecipients($listId))->where('is_active', true)->values();
            if ($recipients->isEmpty()) {
                $this->finishRun($runId, 'skipped', 'Lista sin destinatarios activos.', 0, 1, 0);
                return ['status' => 'skipped', 'message' => 'Lista sin destinatarios activos.'];
            }

            $evaluation = $this->evaluateList($listId, $force || $test, $this->listFrequencyDays($list));
            if (empty($evaluation['alert_products']) && !$test) {
                $this->finishRun($runId, 'skipped', 'Sin alertas nuevas para enviar.', 0, $evaluation['skipped_count'], 0);
                return ['status' => 'skipped', 'message' => 'Sin alertas nuevas para enviar.'];
            }

            $sent = $this->sendSummaryEmail($list, $recipients, $evaluation, $test);
            if (!$sent) {
                $this->recordNotifications($runId, $listId, $evaluation['notifiable_rows'], 'failed');
                $this->finishRun($runId, 'failed', 'Error enviando correo.', 0, $evaluation['skipped_count'], count($evaluation['notifiable_rows']));
                return ['status' => 'failed', 'message' => 'Error enviando correo.'];
            }

            if (!$test) {
                $this->recordNotifications($runId, $listId, $evaluation['notifiable_rows'], 'sent');
            }

            $this->finishRun($runId, 'sent', $test ? 'Correo de prueba enviado.' : 'Resumen enviado.', count($evaluation['notifiable_rows']), $evaluation['skipped_count'], 0);

            return [
                'status' => 'sent',
                'message' => $test ? 'Correo de prueba enviado.' : 'Resumen enviado.',
                'sent_count' => count($evaluation['notifiable_rows']),
                'skipped_count' => $evaluation['skipped_count'],
            ];
        } catch (\Throwable $e) {
            Log::error('Error procesando alertas de inventario', [
                'list_id' => $listId,
                'run_id' => $runId,
                'error' => $e->getMessage(),
            ]);
            $this->finishRun($runId, 'failed', $e->getMessage(), 0, 0, 1);
            return ['status' => 'failed', 'message' => $e->getMessage()];
        }
    }

    public function sendAutomatic(): array
    {
        $lists = DB::connection('budget')->table('inventory_alert_lists')
            ->where('is_active', true)
            ->where('auto_send', true)
            ->orderBy('id')
            ->get();

        $results = [];
        foreach ($lists as $list) {
            if (!$this->isDueForAutomaticSend($list)) {
                $results[] = [
                    'list_id' => (int) $list->id,
                    'status' => 'skipped',
                    'message' => 'Lista aun no cumple su frecuencia de envio.',
                ];
                continue;
            }

            $results[] = [
                'list_id' => (int) $list->id,
                ...$this->sendList((int) $list->id, 'automatic', false, false),
            ];
        }

        return $results;
    }

    private function isDueForAutomaticSend(object $list): bool
    {
        $frequencyDays = $this->listFrequencyDays($list);

        $lastFinishedAt = DB::connection('budget')->table('inventory_alert_runs')
            ->where('list_id', $list->id)
            ->where('mode', 'automatic')
            ->whereIn('status', ['sent', 'skipped'])
            ->whereNotNull('finished_at')
            ->orderByDesc('finished_at')
            ->value('finished_at');

        if (!$lastFinishedAt) {
            return true;
        }

        return Carbon::parse($lastFinishedAt)->startOfDay()->addDays($frequencyDays)->lte(now()->startOfDay());
    }

    public function currentAlerts(int $listId): array
    {
        $evaluation = $this->evaluateList($listId, true);
        return $evaluation['alert_products'];
    }

    public function history(?int $listId = null, int $limit = 20): array
    {
        return DB::connection('budget')->table('inventory_alert_runs as r')
            ->leftJoin('inventory_alert_lists as l', 'l.id', '=', 'r.list_id')
            ->when($listId, fn ($q) => $q->where('r.list_id', $listId))
            ->select('r.*', 'l.name as list_name')
            ->orderByDesc('r.id')
            ->limit(max(1, min(100, $limit)))
            ->get()
            ->map(fn ($run) => [
                'id' => (int) $run->id,
                'list_id' => $run->list_id ? (int) $run->list_id : null,
                'list_name' => $run->list_name,
                'mode' => $run->mode,
                'status' => $run->status,
                'sent_count' => (int) $run->sent_count,
                'skipped_count' => (int) $run->skipped_count,
                'failed_count' => (int) $run->failed_count,
                'message' => $run->message,
                'started_at' => $run->started_at,
                'finished_at' => $run->finished_at,
            ])
            ->all();
    }

    private function listFrequencyDays(object $list): int
    {
        return max(1, min(30, (int) ($list->frequency_days ?? 1)));
    }

    private function evaluateList(int $listId, bool $force, int $suppressDays = 1): array
    {
        $storeIds = DB::connection('budget')->table('inventory_alert_list_stores')->where('list_id', $listId)->pluck('store_id')->map(fn ($id) => (int) $id)->all();
        $productIds = DB::connection('budget')->table('inventory_alert_list_products')->where('list_id', $listId)->pluck('product_id')->map(fn ($id) => (int) $id)->all();

        if (empty($storeIds) || empty($productIds)) {
            return ['alert_products' => [], 'notifiable_rows' => [], 'skipped_count' => 0];
        }

        $rows = collect($this->reportService->getReport(null, $storeIds, null, null, $productIds))
            ->values();
        $alertRows = $rows->filter(fn ($row) => in_array($row['stock_alert_level'] ?? '', [...self::STRONG_LEVELS, ...self::NOTICE_LEVELS], true))->values();
        $notifiableRows = [];
        $skippedCount = 0;

        foreach ($alertRows as $row) {
            if (!$force && $this->wasRecentlySent($listId, $row, $suppressDays)) {
                $skippedCount++;
                continue;
            }
            $notifiableRows[] = $row;
        }

        $notifiableProductIds = collect($notifiableRows)->pluck('product_id')->unique()->all();
        $alertProducts = $rows
            ->whereIn('product_id', $notifiableProductIds)
            ->groupBy('product_id')
            ->map(function (Collection $productRows) {
                $first = $productRows->first();
                return [
                    'product_id' => (int) $first['product_id'],
                    'product_code' => $first['product_code'],
                    'description' => $first['description'],
                    'brand' => $first['brand'] ?? null,
                    'stores' => $productRows->map(fn ($row) => [
                        'store_id' => $row['store_id'] ?? null,
                        'store_code' => $row['store_code'] ?? $row['store_name'] ?? null,
                        'store_name' => $row['store_name'] ?? null,
                        'level' => $row['stock_alert_level'] ?? null,
                        'label' => $row['stock_alert_label'] ?? null,
                        'stock_actual' => (float) ($row['stock_actual'] ?? 0),
                        'maximo_mes' => (float) ($row['maximo_mes'] ?? 0),
                        'dias_disponibles' => round((float) ($row['dias_disponibles'] ?? 0), 2),
                        'suggested_units' => $this->suggestedUnits($row),
                    ])->values()->all(),
                ];
            })
            ->values()
            ->all();

        return [
            'alert_products' => $alertProducts,
            'notifiable_rows' => $notifiableRows,
            'skipped_count' => $skippedCount,
        ];
    }

    private function suggestedUnits(array $row): int
    {
        $maximoMes = (float) ($row['maximo_mes'] ?? 0);
        $stock = (float) ($row['stock_actual'] ?? 0);
        return $maximoMes > 0 ? (int) max(0, ceil(($maximoMes * 2) - $stock)) : 0;
    }

    private function wasRecentlySent(int $listId, array $row, int $suppressDays): bool
    {
        $threshold = now()->subDays(max(0, $suppressDays - 1))->startOfDay();

        return DB::connection('budget')->table('inventory_alert_notifications')
            ->where('list_id', $listId)
            ->where('product_id', $row['product_id'])
            ->where('store_id', $row['store_id'])
            ->where('alert_level', $row['stock_alert_level'])
            ->where('notification_status', 'sent')
            ->where('notified_at', '>=', $threshold)
            ->exists();
    }

    private function recordNotifications(int $runId, int $listId, array $rows, string $status): void
    {
        foreach ($rows as $row) {
            DB::connection('budget')->table('inventory_alert_notifications')->insert([
                'run_id' => $runId,
                'list_id' => $listId,
                'product_id' => $row['product_id'],
                'store_id' => $row['store_id'] ?? null,
                'alert_level' => $row['stock_alert_level'] ?? 'unknown',
                'notification_status' => $status,
                'stock_actual' => $row['stock_actual'] ?? null,
                'maximo_mes' => $row['maximo_mes'] ?? null,
                'dias_disponibles' => isset($row['dias_disponibles']) ? round((float) $row['dias_disponibles'], 2) : null,
                'notified_at' => $status === 'sent' ? now() : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function sendSummaryEmail(object $list, Collection $recipients, array $evaluation, bool $test): bool
    {
        $emails = $recipients->pluck('email')->all();
        $subject = ($test ? '[PRUEBA] ' : '') . 'Resumen de alertas de inventario - ' . $list->name;
        $html = $this->emailHtml($list, $evaluation, $test);

        try {
            Mail::mailer('smtp')->send([], [], function (Message $message) use ($emails, $subject, $html) {
                $message
                    ->from(config('mail.from.address'), 'Sky Free Shop - Inventario')
                    ->replyTo(config('mail.from.address'), 'No Reply')
                    ->to($emails)
                    ->subject($subject)
                    ->html($html);
            });

            return true;
        } catch (\Throwable $e) {
            Log::error('Error enviando alertas de inventario', ['error' => $e->getMessage()]);
            return false;
        }
    }

    private function emailHtml(object $list, array $evaluation, bool $test): string
    {
        $title = e(($test ? 'Prueba - ' : '') . 'Alertas de inventario');
        $portalUrl = rtrim((string) env('APP_URL', ''), '/') . '/panel/inventarios/alertas';
        $rows = '';

        foreach ($evaluation['alert_products'] as $product) {
            $storeRows = '';
            foreach ($product['stores'] as $store) {
                $level = e((string) ($store['label'] ?? $store['level'] ?? '-'));
                $storeName = e((string) ($store['store_code'] ?? $store['store_name'] ?? '-'));
                $stock = number_format((float) $store['stock_actual'], 0);
                $maximo = number_format((float) $store['maximo_mes'], 0);
                $dias = number_format((float) $store['dias_disponibles'], 2);
                $suggested = number_format((float) $store['suggested_units'], 0);
                $storeRows .= "<tr><td>{$storeName}</td><td>{$level}</td><td style='text-align:right'>{$stock}</td><td style='text-align:right'>{$maximo}</td><td style='text-align:right'>{$dias}</td><td style='text-align:right'>{$suggested}</td></tr>";
            }

            $sku = e((string) $product['product_code']);
            $description = e((string) $product['description']);
            $rows .= "
                <h3 style='margin:22px 0 6px;color:#111827;font-size:16px;'>{$sku} - {$description}</h3>
                <table width='100%' cellspacing='0' cellpadding='7' style='border-collapse:collapse;border:1px solid #e5e7eb;font-size:13px;'>
                    <thead><tr style='background:#f3f4f6;color:#374151;'><th align='left'>Tienda</th><th align='left'>Estado</th><th align='right'>Inventario</th><th align='right'>Proyectado</th><th align='right'>Dias disp.</th><th align='right'>Sugerido</th></tr></thead>
                    <tbody>{$storeRows}</tbody>
                </table>";
        }

        if ($rows === '') {
            $rows = "<p style='color:#475569;font-size:15px;'>No hay productos criticos, sin stock o altos en este momento para la lista evaluada.</p>";
        }

        $badge = $test ? "<p style='margin:0 0 18px;padding:10px 12px;background:#fef3c7;border-radius:8px;color:#92400e;font-weight:700;'>Este es un correo de prueba con datos reales actuales.</p>" : '';
        $listName = e($list->name);

        return "
        <div style='background:#f5f7fb;padding:28px;font-family:Arial,Helvetica,sans-serif;color:#111827;'>
          <div style='max-width:820px;margin:0 auto;background:#ffffff;border-radius:14px;overflow:hidden;border:1px solid #e5e7eb;'>
            <div style='background:#0f172a;color:#ffffff;padding:24px 28px;'>
              <div style='font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#bae6fd;'>Inventario</div>
              <h1 style='margin:8px 0 0;font-size:26px;'>{$title}</h1>
              <p style='margin:8px 0 0;color:#cbd5e1;'>Lista: {$listName}</p>
            </div>
            <div style='padding:26px 28px;'>
              {$badge}
              {$rows}
              <p style='margin:26px 0 0;'><a href='" . e($portalUrl) . "' style='display:inline-block;background:#0f172a;color:white;text-decoration:none;padding:11px 16px;border-radius:9px;font-weight:700;'>Abrir alertas en el portal</a></p>
            </div>
          </div>
        </div>";
    }

    private function finishRun(int $runId, string $status, string $message, int $sent, int $skipped, int $failed): void
    {
        DB::connection('budget')->table('inventory_alert_runs')->where('id', $runId)->update([
            'status' => $status,
            'message' => $message,
            'sent_count' => $sent,
            'skipped_count' => $skipped,
            'failed_count' => $failed,
            'finished_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function listStores(int $listId): array
    {
        return DB::connection('budget')->table('inventory_alert_list_stores as ls')
            ->join('stores as s', 's.id', '=', 'ls.store_id')
            ->where('ls.list_id', $listId)
            ->select('s.id', 's.code', 's.name')
            ->orderBy('s.name')
            ->get()
            ->map(fn ($store) => ['id' => (int) $store->id, 'code' => $store->code, 'name' => $store->name])
            ->all();
    }

    private function listProducts(int $listId): array
    {
        return DB::connection('budget')->table('inventory_alert_list_products as lp')
            ->join('products as p', 'p.id', '=', 'lp.product_id')
            ->where('lp.list_id', $listId)
            ->select('p.id', 'p.product_code', 'p.description', 'p.brand', 'p.provider_name', 'lp.source')
            ->orderBy('p.product_code')
            ->get()
            ->map(fn ($product) => [
                'id' => (int) $product->id,
                'product_code' => $product->product_code,
                'description' => $product->description,
                'brand' => $product->brand,
                'provider_name' => $product->provider_name,
                'source' => $product->source,
            ])
            ->all();
    }

    private function listRecipients(int $listId): array
    {
        return DB::connection('budget')->table('inventory_alert_recipients')
            ->where('list_id', $listId)
            ->orderBy('name')
            ->get()
            ->map(fn ($recipient) => [
                'id' => (int) $recipient->id,
                'name' => $recipient->name,
                'email' => $recipient->email,
                'is_active' => (bool) $recipient->is_active,
            ])
            ->all();
    }

    private function replaceRecipients(int $listId, array $recipients): void
    {
        DB::connection('budget')->table('inventory_alert_recipients')->where('list_id', $listId)->delete();

        foreach ($recipients as $recipient) {
            $email = trim((string) ($recipient['email'] ?? ''));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            DB::connection('budget')->table('inventory_alert_recipients')->insert([
                'list_id' => $listId,
                'name' => trim((string) ($recipient['name'] ?? '')) ?: null,
                'email' => $email,
                'is_active' => (bool) ($recipient['is_active'] ?? true),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function replaceProducts(int $listId, array $productIds, string $source): void
    {
        DB::connection('budget')->table('inventory_alert_list_products')->where('list_id', $listId)->delete();

        foreach (collect($productIds)->map(fn ($value) => (int) $value)->filter()->unique() as $productId) {
            DB::connection('budget')->table('inventory_alert_list_products')->insert([
                'list_id' => $listId,
                'product_id' => $productId,
                'source' => $source,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}

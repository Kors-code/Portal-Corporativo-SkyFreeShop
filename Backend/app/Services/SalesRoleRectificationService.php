<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SalesRoleRectificationService
{
    private const SELLER_ROLE_NAME = 'vendedor';
    private const CASHIER_ROLE_NAMES = ['cajero', 'cashier'];
    private const MIN_CASHIER_CONFIRMATION_DAYS = 3;

    public function rectifyImportBatch(int $batchId, bool $dryRun = false): array
    {
        $range = DB::connection('budget')->table('sales')
            ->where('import_batch_id', $batchId)
            ->selectRaw('MIN(sale_date) as start_date, MAX(sale_date) as end_date')
            ->first();

        if (empty($range?->start_date) || empty($range?->end_date)) {
            return [
                'dry_run' => $dryRun,
                'batch_id' => $batchId,
                'message' => 'No hay ventas para rectificar roles.',
                'users_count' => 0,
                'ranges_count' => 0,
                'ranges' => [],
            ];
        }

        return $this->rectifyRange(
            Carbon::parse($range->start_date)->toDateString(),
            Carbon::parse($range->end_date)->toDateString(),
            null,
            $dryRun,
            $batchId
        );
    }

    public function rectifyRange(
        string $startDate,
        string $endDate,
        ?array $onlyUserIds = null,
        bool $dryRun = false,
        ?int $batchId = null,
        ?array $context = null
    ): array {
        $startDate = Carbon::parse($startDate)->toDateString();
        $endDate = Carbon::parse($endDate)->toDateString();

        if ($endDate < $startDate) {
            throw new \InvalidArgumentException('La fecha final debe ser mayor o igual a la fecha inicial.');
        }

        $roleIds = $this->resolveRoleIds();
        $dailyRoles = $this->buildDailyRoles($startDate, $endDate, $roleIds, $onlyUserIds);
        $rangesByUser = $this->compactDailyRoles($dailyRoles);
        $targetUserIds = array_map('intval', array_keys($rangesByUser));

        $summary = [
            'dry_run' => $dryRun,
            'batch_id' => $batchId,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'role_ids' => $roleIds,
            'users_count' => count($targetUserIds),
            'ranges_count' => array_sum(array_map('count', $rangesByUser)),
            'trimmed_rows' => 0,
            'deleted_rows' => 0,
            'inserted_rows' => 0,
            'merged_rows' => 0,
            'backup_id' => null,
            'backup_key' => null,
            'backup_rows' => 0,
            'ranges' => $this->formatRangesForOutput($rangesByUser),
        ];

        if ($dryRun || empty($targetUserIds)) {
            return $summary;
        }

        $this->ensureBackupTable();
        $backup = $this->createBackup(
            $targetUserIds,
            array_values($roleIds),
            [
                'batch_id' => $batchId,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'context' => $context,
            ]
        );
        $summary['backup_id'] = $backup['backup_id'];
        $summary['backup_key'] = $backup['backup_key'];
        $summary['backup_rows'] = $backup['backup_rows'];

        DB::connection('budget')->transaction(function () use (
            $startDate,
            $endDate,
            $roleIds,
            $rangesByUser,
            $targetUserIds,
            &$summary
        ) {
            $trimSummary = $this->replaceExistingRoleWindow($targetUserIds, array_values($roleIds), $startDate, $endDate);

            $rows = [];
            $now = now();
            foreach ($rangesByUser as $userId => $ranges) {
                foreach ($ranges as $range) {
                    $rows[] = [
                        'user_id' => (int) $userId,
                        'role_id' => (int) $range['role_id'],
                        'start_date' => $range['start_date'],
                        'end_date' => $range['end_date'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::connection('budget')->table('user_roles')->insert($chunk);
            }

            $summary['trimmed_rows'] = $trimSummary['trimmed_rows'];
            $summary['deleted_rows'] = $trimSummary['deleted_rows'];
            $summary['inserted_rows'] = count($rows);
            $summary['merged_rows'] = 0;
        });

        Log::info('SALES ROLE RECTIFICATION COMPLETED', [
            'batch_id' => $batchId,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'users_count' => $summary['users_count'],
            'ranges_count' => $summary['ranges_count'],
            'deleted_rows' => $summary['deleted_rows'],
            'inserted_rows' => $summary['inserted_rows'],
        ]);

        return $summary;
    }

    private function resolveRoleIds(): array
    {
        $sellerRoleId = DB::connection('budget')->table('roles')
            ->whereRaw('LOWER(name) = ?', [self::SELLER_ROLE_NAME])
            ->value('id');

        $cashierRoleId = DB::connection('budget')->table('roles')
            ->whereRaw('LOWER(name) IN (?, ?)', self::CASHIER_ROLE_NAMES)
            ->value('id');

        if (!$sellerRoleId || !$cashierRoleId) {
            throw new \RuntimeException('No se encontraron los roles Vendedor/cajero en budget.roles.');
        }

        return [
            'seller' => (int) $sellerRoleId,
            'cashier' => (int) $cashierRoleId,
        ];
    }

    private function buildDailyRoles(string $startDate, string $endDate, array $roleIds, ?array $onlyUserIds): array
    {
        $onlyUserIds = $this->normalizeUserIds($onlyUserIds);
        $dailyRoles = [];
        $cashierCandidateDates = [];

        $users = DB::connection('budget')->table('users')
            ->select('id', 'name')
            ->when(!empty($onlyUserIds), fn ($q) => $q->whereIn('id', $onlyUserIds))
            ->get();

        $userIdsByName = [];
        foreach ($users as $user) {
            $userName = $this->normalizeName($user->name ?? '');
            if ($userName === '') {
                continue;
            }

            $userId = (int) $user->id;
            $userIdsByName[$userName][] = $userId;
        }

        DB::connection('budget')->table('sales')
            ->whereBetween('sale_date', [$startDate, $endDate])
            ->whereNotNull('seller_id')
            ->when(!empty($onlyUserIds), fn ($q) => $q->whereIn('seller_id', $onlyUserIds))
            ->select('seller_id', 'sale_date')
            ->distinct()
            ->orderBy('sale_date')
            ->chunk(1000, function ($rows) use (&$dailyRoles, $roleIds) {
                foreach ($rows as $row) {
                    $userId = (int) $row->seller_id;
                    $date = Carbon::parse($row->sale_date)->toDateString();
                    $dailyRoles[$userId][$date] = $dailyRoles[$userId][$date] ?? $roleIds['seller'];
                }
            });

        DB::connection('budget')->table('sales')
            ->whereBetween('sale_date', [$startDate, $endDate])
            ->whereRaw("TRIM(COALESCE(cashier, '')) <> ''")
            ->select('cashier', 'sale_date')
            ->distinct()
            ->orderBy('sale_date')
            ->chunk(1000, function ($rows) use (&$cashierCandidateDates, $userIdsByName, $onlyUserIds) {
                foreach ($rows as $row) {
                    $cashierName = $this->normalizeName($row->cashier ?? '');
                    if ($cashierName === '' || empty($userIdsByName[$cashierName])) {
                        continue;
                    }

                    $date = Carbon::parse($row->sale_date)->toDateString();
                    foreach ($userIdsByName[$cashierName] as $userId) {
                        if (!empty($onlyUserIds) && !in_array($userId, $onlyUserIds, true)) {
                            continue;
                        }

                        $cashierCandidateDates[$userId][$date] = true;
                    }
                }
            });

        foreach ($cashierCandidateDates as $userId => $dates) {
            $confirmedDates = array_keys($dates);
            sort($confirmedDates);

            if (count($confirmedDates) < self::MIN_CASHIER_CONFIRMATION_DAYS) {
                continue;
            }

            foreach ($confirmedDates as $date) {
                $dailyRoles[(int) $userId][$date] = $roleIds['cashier'];
            }
        }

        foreach ($dailyRoles as $userId => $rolesByDate) {
            ksort($rolesByDate);
            $dailyRoles[$userId] = $rolesByDate;
        }

        return $dailyRoles;
    }

    private function compactDailyRoles(array $dailyRoles): array
    {
        $rangesByUser = [];

        foreach ($dailyRoles as $userId => $rolesByDate) {
            $currentRoleId = null;
            $rangeStart = null;
            $previousDate = null;
            $userRanges = [];

            foreach ($rolesByDate as $date => $roleId) {
                if ($currentRoleId === null) {
                    $currentRoleId = (int) $roleId;
                    $rangeStart = $date;
                    $previousDate = $date;
                    continue;
                }

                if ((int) $roleId !== $currentRoleId) {
                    $userRanges[] = [
                        'role_id' => $currentRoleId,
                        'start_date' => $rangeStart,
                        'end_date' => Carbon::parse($date)->subDay()->toDateString(),
                    ];

                    $currentRoleId = (int) $roleId;
                    $rangeStart = $date;
                }

                $previousDate = $date;
            }

            if ($currentRoleId !== null) {
                $userRanges[] = [
                    'role_id' => $currentRoleId,
                    'start_date' => $rangeStart,
                    'end_date' => $previousDate,
                ];
            }

            if (!empty($userRanges)) {
                $rangesByUser[(int) $userId] = $userRanges;
            }
        }

        return $rangesByUser;
    }

    private function replaceExistingRoleWindow(array $userIds, array $roleIds, string $startDate, string $endDate): array
    {
        $now = now();

        $rows = DB::connection('budget')->table('user_roles')
            ->whereIn('user_id', $userIds)
            ->whereIn('role_id', $roleIds)
            ->where('start_date', '<=', $endDate)
            ->where(function ($q) use ($startDate) {
                $q->whereNull('end_date')
                    ->orWhere('end_date', '>=', $startDate);
            })
            ->orderBy('user_id')
            ->orderBy('start_date')
            ->get();

        $preserveRows = [];

        foreach ($rows as $row) {
            try {
                $rowStart = Carbon::parse($row->start_date)->toDateString();
                $rowEnd = $row->end_date ? Carbon::parse($row->end_date)->toDateString() : null;
            } catch (\Throwable $e) {
                continue;
            }

            if ($rowStart < '1900-01-01') {
                continue;
            }

            if ($rowEnd !== null && $rowEnd < '1900-01-01') {
                $rowEnd = null;
            }

            if ($rowStart < $startDate) {
                $preserveRows[$row->user_id . '|' . $rowStart] = [
                    'user_id' => $row->user_id,
                    'role_id' => $row->role_id,
                    'start_date' => $rowStart,
                    'end_date' => Carbon::parse($startDate)->subDay()->toDateString(),
                    'created_at' => $row->created_at ?? $now,
                    'updated_at' => $now,
                ];
            }

            if ($rowEnd === null || $rowEnd > $endDate) {
                $preserveStart = Carbon::parse($endDate)->addDay()->toDateString();
                $preserveRows[$row->user_id . '|' . $preserveStart] = [
                    'user_id' => $row->user_id,
                    'role_id' => $row->role_id,
                    'start_date' => $preserveStart,
                    'end_date' => $rowEnd,
                    'created_at' => $row->created_at ?? $now,
                    'updated_at' => $now,
                ];
            }
        }

        $deleted = DB::connection('budget')->table('user_roles')
            ->whereIn('user_id', $userIds)
            ->whereIn('role_id', $roleIds)
            ->where('start_date', '<=', $endDate)
            ->where(function ($q) use ($startDate) {
                $q->whereNull('end_date')
                    ->orWhere('end_date', '>=', $startDate);
            })
            ->delete();

        $preserveRows = array_values($preserveRows);

        foreach (array_chunk($preserveRows, 500) as $chunk) {
            DB::connection('budget')->table('user_roles')->insert($chunk);
        }

        return [
            'trimmed_rows' => count($preserveRows),
            'deleted_rows' => $deleted,
        ];
    }

    private function ensureBackupTable(): void
    {
        DB::connection('budget')->statement("
            CREATE TABLE IF NOT EXISTS user_roles_rectification_backups (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                backup_key VARCHAR(80) NOT NULL,
                created_by BIGINT UNSIGNED NULL,
                context_json LONGTEXT NULL,
                rows_json LONGTEXT NOT NULL,
                rows_count INT UNSIGNED NOT NULL DEFAULT 0,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX user_roles_rectification_backups_key_idx (backup_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    private function createBackup(array $userIds, array $roleIds, array $context): array
    {
        $rows = DB::connection('budget')->table('user_roles')
            ->whereIn('user_id', $userIds)
            ->whereIn('role_id', $roleIds)
            ->orderBy('user_id')
            ->orderBy('start_date')
            ->orderBy('id')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->values()
            ->all();

        $backupKey = now()->format('YmdHis') . '-' . Str::lower(Str::random(8));
        $backupId = DB::connection('budget')->table('user_roles_rectification_backups')->insertGetId([
            'backup_key' => $backupKey,
            'created_by' => auth()->id(),
            'context_json' => json_encode($context, JSON_UNESCAPED_UNICODE),
            'rows_json' => json_encode($rows, JSON_UNESCAPED_UNICODE),
            'rows_count' => count($rows),
            'created_at' => now(),
        ]);

        return [
            'backup_id' => (int) $backupId,
            'backup_key' => $backupKey,
            'backup_rows' => count($rows),
        ];
    }

    private function mergeAdjacentRoleRanges(array $userIds, array $roleIds): int
    {
        $merged = 0;
        $now = now();

        foreach ($userIds as $userId) {
            $rows = DB::connection('budget')->table('user_roles')
                ->where('user_id', $userId)
                ->whereIn('role_id', $roleIds)
                ->orderBy('role_id')
                ->orderBy('start_date')
                ->orderBy('id')
                ->get();

            $current = null;

            foreach ($rows as $row) {
                if ($current === null || (int) $current->role_id !== (int) $row->role_id) {
                    $current = $row;
                    continue;
                }

                $currentEnd = $current->end_date ? Carbon::parse($current->end_date)->toDateString() : null;
                $rowStart = Carbon::parse($row->start_date)->toDateString();
                $rowEnd = $row->end_date ? Carbon::parse($row->end_date)->toDateString() : null;
                $canMerge = $currentEnd === null
                    || $rowStart <= Carbon::parse($currentEnd)->addDay()->toDateString();

                if (!$canMerge) {
                    $current = $row;
                    continue;
                }

                $newEnd = $this->maxEndDate($currentEnd, $rowEnd);

                DB::connection('budget')->table('user_roles')
                    ->where('id', $current->id)
                    ->update([
                        'end_date' => $newEnd,
                        'updated_at' => $now,
                    ]);

                DB::connection('budget')->table('user_roles')
                    ->where('id', $row->id)
                    ->delete();

                $current->end_date = $newEnd;
                $merged++;
            }
        }

        return $merged;
    }

    private function maxEndDate(?string $left, ?string $right): ?string
    {
        if ($left === null || $right === null) {
            return null;
        }

        return $left >= $right ? $left : $right;
    }

    private function formatRangesForOutput(array $rangesByUser): array
    {
        $out = [];

        foreach ($rangesByUser as $userId => $ranges) {
            foreach ($ranges as $range) {
                $out[] = [
                    'user_id' => (int) $userId,
                    'role_id' => (int) $range['role_id'],
                    'start_date' => $range['start_date'],
                    'end_date' => $range['end_date'],
                ];
            }
        }

        return $out;
    }

    private function normalizeUserIds(?array $userIds): array
    {
        if (empty($userIds)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('intval', $userIds))));
    }

    private function normalizeName(?string $name): string
    {
        $name = trim((string) $name);
        if ($name === '') {
            return '';
        }

        $normalized = iconv('UTF-8', 'ASCII//TRANSLIT', $name);
        if ($normalized === false) {
            $normalized = $name;
        }

        $normalized = mb_strtoupper($normalized);
        $normalized = preg_replace('/\s+/', ' ', $normalized);

        return trim($normalized);
    }
}

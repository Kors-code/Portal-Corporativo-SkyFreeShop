<?php

namespace App\Services;

class DailyWhatsappReportImageService
{
    private array $colors = [];
    private ?string $fontRegular = null;
    private ?string $fontBold = null;

    public function makeImages(array $report): array
    {
        $images = [
            [
                'bytes' => $this->makeExecutive($report),
                'caption' => $this->caption($report, 'Resumen ejecutivo'),
            ],
        ];

        foreach ($this->makeDailyTablePages($report) as $index => $bytes) {
            $images[] = [
                'bytes' => $bytes,
                'caption' => $this->caption($report, 'Detalle diario ' . ($index + 1)),
            ];
        }

        return $images;
    }

    public function make(array $report): string
    {
        return $this->makeExecutive($report);
    }

    private function makeExecutive(array $report): string
    {
        if (!extension_loaded('gd')) {
            throw new \RuntimeException('La extension GD de PHP no esta disponible.');
        }

        $width = 1500;
        $height = 900;
        $image = imagecreatetruecolor($width, $height);

        $this->bootFonts();
        $this->bootColors($image);

        imagefilledrectangle($image, 0, 0, $width, $height, $this->colors['bg']);

        $budget = $report['budget'] ?? [];
        $dailyRows = array_values($report['daily_performance'] ?? []);
        $date = (string) ($report['date'] ?? now('America/Bogota')->toDateString());
        $periodStart = (string) ($budget['period']['start'] ?? $budget['range']['start'] ?? $date);
        $periodEnd = (string) ($budget['period']['end'] ?? $budget['range']['end'] ?? $date);

        $totalSales = (float) ($budget['month_sales_usd'] ?? array_sum(array_map(fn ($row) => (float) ($row['sales_usd'] ?? 0), $dailyRows)));
        $rangeBudget = (float) ($budget['range_budget_usd'] ?? array_sum(array_map(fn ($row) => (float) ($row['budget_daily_usd'] ?? 0), $dailyRows)));
        $diffBudget = (float) ($budget['month_diff_usd'] ?? ($totalSales - $rangeBudget));
        $project = (float) ($budget['month_compliance_pct'] ?? ($rangeBudget > 0 ? ($totalSales / $rangeBudget) * 100 : 0));
        $budgetDaily = (float) ($budget['budget_daily_usd'] ?? 0);

        $totalUnits = array_sum(array_map(fn ($row) => (float) ($row['units'] ?? 0), $dailyRows));
        $totalTrx = array_sum(array_map(fn ($row) => (int) ($row['trx'] ?? 0), $dailyRows));
        $daysWithSales = array_values(array_filter($dailyRows, fn ($row) => (float) ($row['sales_usd'] ?? 0) > 0));
        $avgDaily = count($daysWithSales) > 0 ? $totalSales / count($daysWithSales) : 0;
        $avgTicket = $totalTrx > 0 ? $totalSales / $totalTrx : 0;
        $avgTrx = count($daysWithSales) > 0 ? $totalTrx / count($daysWithSales) : 0;
        $unitsPerTicket = $totalTrx > 0 ? $totalUnits / $totalTrx : 0;
        $forecast = $avgDaily * $this->daysInMonth($periodStart);

        $this->header($image, $budget['name'] ?? 'Presupuesto activo', $periodStart, $periodEnd);

        $kpis = [
            ['Budget daily', $this->usd($budgetDaily), 'Meta diaria', $this->colors['primary']],
            ['Ventas reales rango', $this->usd($totalSales), number_format($project, 1, ',', '.') . '% del esperado', $this->colors['green']],
            ['Diff budget', $this->usd($diffBudget), $diffBudget >= 0 ? 'Sobre meta' : 'Por recuperar', $diffBudget >= 0 ? $this->colors['green'] : $this->colors['red']],
            ['Promedio diario', $this->usd($avgDaily), number_format($totalTrx, 0, ',', '.') . ' transacciones', $this->colors['blue']],
            ['Sales forecast', $this->usd($forecast), 'Promedio diario por mes', $this->colors['blue']],
            ['Ticket promedio', $this->usd($avgTicket), 'Ventas rango / transacciones', $this->colors['primary']],
            ['Transacciones promedio', number_format($avgTrx, 1, ',', '.'), 'Transacciones / dias vendidos', $this->colors['ink']],
            ['Unidades por ticket', number_format($unitsPerTicket, 1, ',', '.'), 'Unidades totales / tickets', $this->colors['primary']],
        ];

        $this->drawKpis($image, $kpis);
        $this->drawProjectBar($image, 50, 385, 1400, 82, $project);
        $this->drawDailyChart($image, 50, 510, 860, 315, $dailyRows);
        $this->drawCategories($image, 940, 510, 510, 315, $report['categories'] ?? []);

        $this->text($image, 'Generado automaticamente desde Portal Sky Free Shop', 50, 870, 15, $this->colors['muted'], false);
        $this->text($image, now('America/Bogota')->format('Y-m-d H:i'), 1280, 870, 15, $this->colors['muted'], true);

        ob_start();
        imagepng($image, null, 9);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        return $png;
    }

    private function makeDailyTablePages(array $report): array
    {
        if (!extension_loaded('gd')) {
            throw new \RuntimeException('La extension GD de PHP no esta disponible.');
        }

        $budget = $report['budget'] ?? [];
        $rows = array_values($report['daily_performance'] ?? []);
        $chunks = array_chunk($rows, 12);
        if (!$chunks) {
            $chunks = [[]];
        }

        $totalSales = (float) ($budget['month_sales_usd'] ?? array_sum(array_map(fn ($row) => (float) ($row['sales_usd'] ?? 0), $rows)));
        $rangeBudget = (float) ($budget['range_budget_usd'] ?? array_sum(array_map(fn ($row) => (float) ($row['budget_daily_usd'] ?? 0), $rows)));
        $diffBudget = (float) ($budget['month_diff_usd'] ?? ($totalSales - $rangeBudget));
        $project = (float) ($budget['month_compliance_pct'] ?? ($rangeBudget > 0 ? ($totalSales / $rangeBudget) * 100 : 0));
        $totalUnits = array_sum(array_map(fn ($row) => (float) ($row['units'] ?? 0), $rows));
        $totalTrx = array_sum(array_map(fn ($row) => (int) ($row['trx'] ?? 0), $rows));
        $avgTicket = $totalTrx > 0 ? $totalSales / $totalTrx : 0;

        $images = [];
        $totalPages = count($chunks);

        foreach ($chunks as $pageIndex => $chunk) {
            $width = 1500;
            $height = 860;
            $image = imagecreatetruecolor($width, $height);

            $this->bootFonts();
            $this->bootColors($image);

            imagefilledrectangle($image, 0, 0, $width, $height, $this->colors['bg']);
            $this->card($image, 50, 35, 1400, 95);
            $this->text($image, 'Detalle diario', 82, 78, 30, $this->colors['ink'], true);
            $this->text($image, 'Project es el cumplimiento contra Budget daily', 82, 108, 14, $this->colors['muted'], false);
            $this->text($image, 'Pagina ' . ($pageIndex + 1) . ' de ' . $totalPages, 1245, 92, 16, $this->colors['muted'], true);

            $this->drawDailyTable(
                $image,
                50,
                160,
                1400,
                570,
                $chunk,
                $pageIndex === $totalPages - 1 ? $totalSales : null,
                $pageIndex === $totalPages - 1 ? $rangeBudget : null,
                $pageIndex === $totalPages - 1 ? $diffBudget : null,
                $pageIndex === $totalPages - 1 ? $project : null,
                $pageIndex === $totalPages - 1 ? $totalUnits : null,
                $pageIndex === $totalPages - 1 ? $totalTrx : null,
                $pageIndex === $totalPages - 1 ? $avgTicket : null
            );

            $this->text($image, 'Generado automaticamente desde Portal Sky Free Shop', 50, 820, 15, $this->colors['muted'], false);
            $this->text($image, now('America/Bogota')->format('Y-m-d H:i'), 1280, 820, 15, $this->colors['muted'], true);

            ob_start();
            imagepng($image, null, 9);
            $images[] = (string) ob_get_clean();
            imagedestroy($image);
        }

        return $images;
    }

    private function header($image, mixed $budgetName, string $start, string $end): void
    {
        $this->card($image, 50, 35, 1400, 105);
        $this->text($image, 'SKY FREE SHOP', 82, 75, 19, $this->colors['primary'], true);
        $this->text($image, 'WhatsApp Daily - Cierre de caja', 82, 112, 30, $this->colors['ink'], true);
        $this->text($image, $this->truncate((string) $budgetName, 46), 950, 78, 18, $this->colors['ink'], true);
        $this->text($image, $start . ' / ' . $end, 950, 112, 18, $this->colors['muted'], false);
    }

    private function drawKpis($image, array $kpis): void
    {
        $x = 50;
        $y = 170;
        $w = 327;
        $h = 86;
        $gapX = 24;
        $gapY = 22;

        foreach ($kpis as $index => [$label, $value, $detail, $accent]) {
            $col = $index % 4;
            $row = intdiv($index, 4);
            $left = $x + ($col * ($w + $gapX));
            $top = $y + ($row * ($h + $gapY));
            $this->card($image, $left, $top, $w, $h);
            $this->roundedRect($image, $left + 18, $top + 17, $left + 48, $top + 47, 8, $this->colors['pink']);
            imagefilledellipse($image, $left + 33, $top + 32, 12, 12, $accent);
            $this->text($image, strtoupper($label), $left + 62, $top + 28, 12, $this->colors['muted'], true);
            $this->text($image, $value, $left + 62, $top + 57, 22, $accent, true);
            $this->text($image, $detail, $left + 62, $top + 78, 11, $this->colors['muted'], false);
        }
    }

    private function drawProjectBar($image, int $x, int $y, int $w, int $h, float $project): void
    {
        $this->card($image, $x, $y, $w, $h);
        $this->text($image, 'PROJECT', $x + 24, $y + 31, 13, $this->colors['muted'], true);
        $this->text($image, 'Cumplimiento proyectado contra presupuesto', $x + 24, $y + 56, 13, $this->colors['muted'], false);
        $this->text($image, number_format($project, 1, ',', '.') . '%', $x + $w - 105, $y + 42, 24, $this->colors['ink'], true);

        $barX = $x + 24;
        $barY = $y + 64;
        $barW = $w - 48;
        $this->roundedRect($image, $barX, $barY, $barX + $barW, $barY + 16, 8, $this->colors['softLine']);
        $fillW = (int) min($barW, max(0, $barW * ($project / 100)));
        $this->roundedRect($image, $barX, $barY, $barX + $fillW, $barY + 16, 8, $project >= 100 ? $this->colors['green'] : $this->colors['red']);
    }

    private function drawDailyTable($image, int $x, int $y, int $w, int $h, array $rows, ?float $totalSales, ?float $rangeBudget, ?float $diffBudget, ?float $project, ?float $totalUnits, ?int $totalTrx, ?float $avgTicket): void
    {
        $this->card($image, $x, $y, $w, $h);

        $tableX = $x + 18;
        $tableY = $y + 22;
        $tableW = $w - 36;
        $headerH = 36;
        imagefilledrectangle($image, $tableX, $tableY, $tableX + $tableW, $tableY + $headerH, $this->colors['ink']);

        $cols = [
            ['DIA', 14, 'left'],
            ['WEEKDAY', 85, 'left'],
            ['SALES', 350, 'right'],
            ['BUDGET DAILY', 535, 'right'],
            ['DIFF BUDGET', 730, 'right'],
            ['PROJECT', 900, 'right'],
            ['UNITS', 1035, 'right'],
            ['TRX', 1160, 'right'],
            ['TKT', 1325, 'right'],
        ];

        foreach ($cols as [$label, $pos, $align]) {
            $this->textAligned($image, $label, $tableX + $pos, $tableY + 24, 11, $this->colors['white'], true, $align);
        }

        $visibleRows = array_slice($rows, 0, 12);
        $rowY = $tableY + $headerH;
        foreach ($visibleRows as $index => $row) {
            $fill = $index % 2 === 0 ? $this->colors['white'] : $this->colors['soft'];
            imagefilledrectangle($image, $tableX, $rowY, $tableX + $tableW, $rowY + 34, $fill);
            imageline($image, $tableX, $rowY + 34, $tableX + $tableW, $rowY + 34, $this->colors['line']);

            $sales = (float) ($row['sales_usd'] ?? 0);
            $budget = (float) ($row['budget_daily_usd'] ?? 0);
            $diff = (float) ($row['diff_usd'] ?? ($sales - $budget));
            $pct = (float) ($row['compliance_pct'] ?? ($budget > 0 ? ($sales / $budget) * 100 : 0));
            $trx = (int) ($row['trx'] ?? 0);
            $tkt = $trx > 0 ? $sales / $trx : 0;

            $this->textAligned($image, (string) ($row['day'] ?? ''), $tableX + 14, $rowY + 23, 12, $this->colors['ink'], true, 'left');
            $this->textAligned($image, $this->weekday((string) ($row['weekday'] ?? '')), $tableX + 85, $rowY + 23, 12, $this->colors['muted'], false, 'left');
            $this->textAligned($image, $this->usd($sales), $tableX + 350, $rowY + 23, 12, $this->colors['ink'], true, 'right');
            $this->textAligned($image, $this->usd2($budget), $tableX + 535, $rowY + 23, 12, $this->colors['ink'], false, 'right');
            $this->textAligned($image, $this->usd($diff), $tableX + 730, $rowY + 23, 12, $diff >= 0 ? $this->colors['green'] : $this->colors['red'], true, 'right');
            $this->textAligned($image, number_format($pct, 1, ',', '.') . '%', $tableX + 900, $rowY + 23, 12, $this->colors['ink'], true, 'right');
            $this->textAligned($image, number_format((float) ($row['units'] ?? 0), 0, ',', '.'), $tableX + 1035, $rowY + 23, 12, $this->colors['ink'], false, 'right');
            $this->textAligned($image, (string) $trx, $tableX + 1160, $rowY + 23, 12, $this->colors['ink'], false, 'right');
            $this->textAligned($image, $this->usd2($tkt), $tableX + 1325, $rowY + 23, 12, $this->colors['ink'], false, 'right');
            $rowY += 34;
        }

        if ($totalSales !== null && $rangeBudget !== null && $diffBudget !== null && $project !== null && $totalUnits !== null && $totalTrx !== null && $avgTicket !== null) {
            imagefilledrectangle($image, $tableX, $rowY, $tableX + $tableW, $rowY + 38, $this->colors['total']);
            $this->text($image, 'Total', $tableX + 14, $rowY + 25, 12, $this->colors['ink'], true);
            $this->textAligned($image, $this->usd($totalSales), $tableX + 350, $rowY + 25, 12, $this->colors['ink'], true, 'right');
            $this->textAligned($image, $this->usd($rangeBudget), $tableX + 535, $rowY + 25, 12, $this->colors['ink'], true, 'right');
            $this->textAligned($image, $this->usd($diffBudget), $tableX + 730, $rowY + 25, 12, $diffBudget >= 0 ? $this->colors['green'] : $this->colors['red'], true, 'right');
            $this->textAligned($image, number_format($project, 1, ',', '.') . '%', $tableX + 900, $rowY + 25, 12, $this->colors['ink'], true, 'right');
            $this->textAligned($image, number_format($totalUnits, 0, ',', '.'), $tableX + 1035, $rowY + 25, 12, $this->colors['ink'], true, 'right');
            $this->textAligned($image, number_format($totalTrx, 0, ',', '.'), $tableX + 1160, $rowY + 25, 12, $this->colors['ink'], true, 'right');
            $this->textAligned($image, $this->usd2($avgTicket), $tableX + 1325, $rowY + 25, 12, $this->colors['ink'], true, 'right');
        }
    }

    private function drawDailyChart($image, int $x, int $y, int $w, int $h, array $rows): void
    {
        $this->card($image, $x, $y, $w, $h);
        $this->text($image, 'Cumplimiento diario', $x + 22, $y + 34, 20, $this->colors['ink'], true);
        $this->text($image, 'Ventas vs Budget daily', $x + 22, $y + 58, 12, $this->colors['muted'], false);

        $chartX = $x + 80;
        $chartY = $y + 92;
        $chartW = $w - 130;
        $chartH = $h - 135;
        $max = max(1, max(array_map(fn ($row) => max((float) ($row['sales_usd'] ?? 0), (float) ($row['budget_daily_usd'] ?? 0)), $rows ?: [['sales_usd' => 1]])));
        $max *= 1.15;

        imageline($image, $chartX, $chartY + $chartH, $chartX + $chartW, $chartY + $chartH, $this->colors['axis']);
        imageline($image, $chartX, $chartY, $chartX, $chartY + $chartH, $this->colors['axis']);

        $rows = array_slice($rows, 0, 12);
        $count = max(1, count($rows));
        $gap = 24;
        $barW = max(26, (int) (($chartW - ($gap * ($count + 1))) / $count));

        foreach ($rows as $index => $row) {
            $sales = (float) ($row['sales_usd'] ?? 0);
            $budget = (float) ($row['budget_daily_usd'] ?? 0);
            $pct = (float) ($row['compliance_pct'] ?? ($budget > 0 ? ($sales / $budget) * 100 : 0));
            $barH = (int) (($sales / $max) * $chartH);
            $barX = $chartX + $gap + ($index * ($barW + $gap));
            $barY = $chartY + $chartH - $barH;
            $this->roundedRect($image, $barX, $barY, $barX + $barW, $chartY + $chartH, 5, $this->colors['teal']);
            $this->textAligned($image, number_format($pct, 1, ',', '.') . '%', $barX + (int) ($barW / 2), $barY - 8, 10, $this->colors['ink'], true, 'center');
            $this->textAligned($image, (string) ($row['day'] ?? ''), $barX + (int) ($barW / 2), $chartY + $chartH + 24, 11, $this->colors['muted'], false, 'center');
        }

        $budgetValue = (float) ($rows[0]['budget_daily_usd'] ?? 0);
        if ($budgetValue > 0) {
            $budgetY = $chartY + $chartH - (int) (($budgetValue / $max) * $chartH);
            $budget80Y = $chartY + $chartH - (int) ((($budgetValue * 0.8) / $max) * $chartH);
            imageline($image, $chartX, $budgetY, $chartX + $chartW, $budgetY, $this->colors['green']);
            imageline($image, $chartX, $budget80Y, $chartX + $chartW, $budget80Y, $this->colors['yellow']);
        }
    }

    private function drawCategories($image, int $x, int $y, int $w, int $h, array $rows): void
    {
        $this->card($image, $x, $y, $w, $h);
        $this->text($image, 'Categorias', $x + 22, $y + 34, 20, $this->colors['ink'], true);
        $this->text($image, 'Mix del periodo seleccionado', $x + 22, $y + 58, 12, $this->colors['muted'], false);

        $rows = array_slice(array_values($rows), 0, 7);
        if (!$rows) {
            $this->text($image, 'Sin categorias para mostrar.', $x + 22, $y + 130, 14, $this->colors['muted'], false);
            return;
        }

        $max = max(array_map(fn ($row) => (float) ($row['sales_usd'] ?? 0), $rows));
        $barMax = 230;
        $lineY = $y + 100;
        foreach ($rows as $row) {
            $label = $this->truncate((string) ($row['category'] ?? 'Sin categoria'), 15);
            $value = (float) ($row['sales_usd'] ?? 0);
            $barW = $max > 0 ? (int) (($value / $max) * $barMax) : 0;
            $this->textAligned($image, $label, $x + 145, $lineY + 18, 10, $this->colors['ink'], true, 'right');
            $this->roundedRect($image, $x + 160, $lineY, $x + 160 + max(5, $barW), $lineY + 24, 5, $this->colors['blue']);
            $this->text($image, $this->usd($value), $x + 400, $lineY + 17, 10, $this->colors['ink'], true);
            $lineY += 32;
        }
    }

    private function card($image, int $x, int $y, int $w, int $h): void
    {
        $this->roundedRect($image, $x, $y, $x + $w, $y + $h, 11, $this->colors['white']);
        imagerectangle($image, $x, $y, $x + $w, $y + $h, $this->colors['line']);
    }

    private function text($image, string $text, int $x, int $y, int $size, int $color, bool $bold = false): void
    {
        $text = $this->safe($text);
        $font = $bold ? $this->fontBold : $this->fontRegular;

        if ($font && function_exists('imagettftext')) {
            imagettftext($image, $size, 0, $x, $y, $color, $font, $text);
            return;
        }

        imagestring($image, 5, $x, $y - 14, $text, $color);
    }

    private function textAligned($image, string $text, int $x, int $y, int $size, int $color, bool $bold = false, string $align = 'left'): void
    {
        $text = $this->safe($text);
        $font = $bold ? $this->fontBold : $this->fontRegular;
        if ($font && function_exists('imagettfbbox')) {
            $box = imagettfbbox($size, 0, $font, $text);
            $textW = abs(($box[2] ?? 0) - ($box[0] ?? 0));
            if ($align === 'right') {
                $x -= $textW;
            } elseif ($align === 'center') {
                $x -= (int) ($textW / 2);
            }
        }
        $this->text($image, $text, $x, $y, $size, $color, $bold);
    }

    private function roundedRect($image, int $x1, int $y1, int $x2, int $y2, int $radius, int $color): void
    {
        imagefilledrectangle($image, $x1 + $radius, $y1, $x2 - $radius, $y2, $color);
        imagefilledrectangle($image, $x1, $y1 + $radius, $x2, $y2 - $radius, $color);
        imagefilledellipse($image, $x1 + $radius, $y1 + $radius, $radius * 2, $radius * 2, $color);
        imagefilledellipse($image, $x2 - $radius, $y1 + $radius, $radius * 2, $radius * 2, $color);
        imagefilledellipse($image, $x1 + $radius, $y2 - $radius, $radius * 2, $radius * 2, $color);
        imagefilledellipse($image, $x2 - $radius, $y2 - $radius, $radius * 2, $radius * 2, $color);
    }

    private function bootColors($image): void
    {
        $this->colors = [
            'bg' => $this->color($image, '#f3f5f8'),
            'white' => $this->color($image, '#ffffff'),
            'soft' => $this->color($image, '#f8fafc'),
            'pink' => $this->color($image, '#f8e8ef'),
            'line' => $this->color($image, '#d9e1ea'),
            'softLine' => $this->color($image, '#edf1f5'),
            'total' => $this->color($image, '#eef2f7'),
            'axis' => $this->color($image, '#a8b3c2'),
            'ink' => $this->color($image, '#020617'),
            'muted' => $this->color($image, '#5f7087'),
            'primary' => $this->color($image, '#970032'),
            'green' => $this->color($image, '#047857'),
            'red' => $this->color($image, '#dc2626'),
            'blue' => $this->color($image, '#2563eb'),
            'teal' => $this->color($image, '#147f73'),
            'yellow' => $this->color($image, '#facc15'),
        ];
    }

    private function bootFonts(): void
    {
        $this->fontRegular = collect([
            'C:/Windows/Fonts/arial.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        ])->first(fn ($path) => is_file($path));

        $this->fontBold = collect([
            'C:/Windows/Fonts/arialbd.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
        ])->first(fn ($path) => is_file($path));
    }

    private function color($image, string $hex): int
    {
        $hex = ltrim($hex, '#');
        return imagecolorallocate($image, hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)));
    }

    private function usd(float $value): string
    {
        return 'US$ ' . number_format($value, 0, ',', '.');
    }

    private function usd2(float $value): string
    {
        return 'US$ ' . number_format($value, 2, ',', '.');
    }

    private function weekday(string $value): string
    {
        return [
            'Mon' => 'Lun',
            'Tue' => 'Mar',
            'Wed' => 'Mie',
            'Thu' => 'Jue',
            'Fri' => 'Vie',
            'Sat' => 'Sab',
            'Sun' => 'Dom',
        ][$value] ?? $value;
    }

    private function daysInMonth(string $date): int
    {
        try {
            return (int) (new \DateTimeImmutable($date))->format('t');
        } catch (\Throwable) {
            return 30;
        }
    }

    private function truncate(string $value, int $max): string
    {
        return mb_strlen($value) > $max ? mb_substr($value, 0, $max - 3) . '...' : $value;
    }

    private function safe(string $value): string
    {
        return iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
    }

    private function caption(array $report, string $label): string
    {
        $budget = $report['budget']['name'] ?? 'Presupuesto activo';
        $end = $report['budget']['period']['end'] ?? $report['date'] ?? now('America/Bogota')->toDateString();

        return 'Daily cierre de caja - ' . $label . ' - ' . $budget . ' - ' . $end;
    }
}

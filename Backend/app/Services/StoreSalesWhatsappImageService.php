<?php

namespace App\Services;

class StoreSalesWhatsappImageService
{
    private array $colors = [];
    private ?string $fontRegular = null;
    private ?string $fontBold = null;

    public function make(array $report): string
    {
        if (!extension_loaded('gd')) {
            throw new \RuntimeException('La extension GD de PHP no esta disponible.');
        }

        $width = 1280;
        $height = 820;
        $image = imagecreatetruecolor($width, $height);

        $this->bootFonts();
        $this->bootColors($image);
        imagefilledrectangle($image, 0, 0, $width, $height, $this->colors['bg']);

        $date = (string) ($report['date_label'] ?? $report['date'] ?? now('America/Bogota')->toDateString());
        $isRange = (string) ($report['start_date'] ?? $report['date'] ?? '') !== (string) ($report['end_date'] ?? $report['date'] ?? '');
        $stores = array_values($report['stores'] ?? []);
        $totals = $report['totals'] ?? [];
        $meta = (float) ($report['meta_usd'] ?? 0);
        $compliance = (float) ($report['compliance_pct'] ?? 0);

        $this->drawHeader($image, $date, $totals);
        $this->drawMetrics($image, $date, $totals);
        $this->drawTableHeader($image, $isRange);

        $y = 465;
        foreach ($stores as $row) {
            $this->drawRow($image, $row, $y, false);
            $y += 76;
        }

        $this->drawRow($image, $totals, $y, true);
        $this->drawFooter($image, $compliance, $meta, $this->updatedAtLabel($report), $isRange);

        ob_start();
        imagepng($image, null, 9);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        return $png;
    }

    private function drawHeader($image, string $date, array $totals): void
    {
        $this->roundedRect($image, 34, 28, 1246, 124, 18, $this->colors['white']);
        imagerectangle($image, 34, 28, 1246, 124, $this->colors['line']);
        $this->roundedRect($image, 58, 52, 104, 98, 10, $this->colors['pink']);
        imagefilledellipse($image, 81, 75, 18, 18, $this->colors['primary']);
        $this->text($image, 'VISUALIZATIONS', 126, 66, 13, $this->colors['primary'], true);
        $this->text($image, 'Daily Sales', 126, 98, 29, $this->colors['ink'], true);
        $this->textAligned($image, $this->dateLabel($date), 1218, 68, 18, $this->colors['ink'], true, 'right');
        $this->textAligned($image, $this->usd((float) ($totals['total_usd'] ?? 0)), 1218, 102, 25, $this->colors['primary'], true, 'right');
    }

    private function drawMetrics($image, string $date, array $totals): void
    {
        $cards = [
            ['Global sales', $this->usd((float) ($totals['total_usd'] ?? 0)), $date],
            ['TRX', $this->number((float) ($totals['trx'] ?? 0)), 'Transactions'],
            ['TKT', $this->usd((float) ($totals['tkt_usd'] ?? 0)), 'Average ticket'],
            ['UNITS/TKT', $this->number((float) ($totals['units_per_ticket'] ?? 0)), 'Units per ticket'],
        ];

        $x = 34;
        foreach ($cards as [$label, $value, $detail]) {
            $this->roundedRect($image, $x, 148, $x + 286, 256, 14, $this->colors['white']);
            imagerectangle($image, $x, 148, $x + 286, 256, $this->colors['line']);
            $this->roundedRect($image, $x + 18, 170, $x + 54, 206, 8, $this->colors['pink']);
            imagefilledellipse($image, $x + 36, 188, 13, 13, $this->colors['primary']);
            $this->text($image, strtoupper($label), $x + 70, 184, 12, $this->colors['muted'], true);
            $this->text($image, $value, $x + 70, 216, 22, $this->colors['ink'], true);
            $this->text($image, $detail, $x + 70, 239, 12, $this->colors['muted'], false);
            $x += 308;
        }
    }

    private function drawTableHeader($image, bool $isRange): void
    {
        $this->roundedRect($image, 34, 288, 1246, 650, 16, $this->colors['white']);
        imagerectangle($image, 34, 288, 1246, 650, $this->colors['line']);
        $this->text($image, 'Store Summary', 58, 324, 20, $this->colors['ink'], true);
        $this->text($image, $isRange ? 'Arrivals + Departures, selected period.' : 'Arrivals + Departures, daily period.', 58, 348, 12, $this->colors['muted'], false);
        imagefilledrectangle($image, 34, 370, 1246, 420, $this->colors['dark']);
        $this->text($image, 'STORE', 58, 402, 12, $this->colors['white'], true);
        $this->textAligned($image, 'SALES', 720, 402, 12, $this->colors['white'], true, 'right');
        $this->textAligned($image, 'TRX', 875, 402, 12, $this->colors['white'], true, 'right');
        $this->textAligned($image, 'TKT', 1030, 402, 12, $this->colors['white'], true, 'right');
        $this->textAligned($image, 'UNITS/TKT', 1216, 402, 12, $this->colors['white'], true, 'right');
    }

    private function drawRow($image, array $row, int $y, bool $highlight): void
    {
        if ($highlight) {
            imagefilledrectangle($image, 34, $y - 43, 1246, $y + 15, $this->colors['primary']);
        } else {
            imageline($image, 58, $y + 16, 1222, $y + 16, $this->colors['line']);
        }

        $color = $highlight ? $this->colors['white'] : $this->colors['ink'];
        $this->text($image, (string) ($row['label'] ?? ''), 58, $y, 21, $color, true);
        if (!$highlight && isset($row['code'])) {
            $this->text($image, (string) $row['code'], 58, $y + 23, 11, $this->colors['muted'], true);
        }
        $this->textAligned($image, $this->number((float) ($row['total_usd'] ?? 0)), 720, $y, 21, $color, true, 'right');
        $this->textAligned($image, $this->number((float) ($row['trx'] ?? 0)), 875, $y, 19, $color, true, 'right');
        $this->textAligned($image, $this->number((float) ($row['tkt_usd'] ?? 0)), 1030, $y, 19, $color, true, 'right');
        $this->textAligned($image, $this->number((float) ($row['units_per_ticket'] ?? 0)), 1216, $y, 19, $color, true, 'right');
    }

    private function drawFooter($image, float $compliance, float $meta, string $updatedAtLabel, bool $isRange): void
    {
        $this->roundedRect($image, 34, 680, 620, 780, 14, $this->colors['white']);
        imagerectangle($image, 34, 680, 620, 780, $this->colors['line']);
        $this->text($image, '% COMPLIANCE', 58, 718, 12, $this->colors['muted'], true);
        $this->text($image, $this->percent($compliance), 58, 754, 28, $compliance >= 100 ? $this->colors['green'] : $this->colors['red'], true);

        $this->roundedRect($image, 660, 680, 1246, 780, 14, $this->colors['white']);
        imagerectangle($image, 660, 680, 1246, 780, $this->colors['line']);
        $this->text($image, $isRange ? 'RANGE TARGET' : 'DAILY TARGET', 684, 718, 12, $this->colors['muted'], true);
        $this->text($image, $this->usd($meta), 684, 754, 28, $this->colors['ink'], true);

        $this->text($image, 'Generado automaticamente desde Portal Sky Free Shop', 34, 808, 12, $this->colors['muted'], false);
        $this->textAligned($image, $updatedAtLabel, 1246, 808, 12, $this->colors['muted'], false, 'right');
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

    private function bootColors($image): void
    {
        $this->colors = [
            'bg' => $this->color($image, '#f3f5f8'),
            'dark' => $this->color($image, '#020617'),
            'white' => $this->color($image, '#ffffff'),
            'ink' => $this->color($image, '#111827'),
            'line' => $this->color($image, '#d9e1ea'),
            'muted' => $this->color($image, '#64748b'),
            'pink' => $this->color($image, '#f8e8ef'),
            'soft' => $this->color($image, '#f8fafc'),
            'primary' => $this->color($image, '#970032'),
            'green' => $this->color($image, '#047857'),
            'red' => $this->color($image, '#dc2626'),
        ];
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

    private function color($image, string $hex): int
    {
        $hex = ltrim($hex, '#');
        return imagecolorallocate($image, hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)));
    }

    private function dateLabel(string $date): string
    {
        try {
            return (new \DateTimeImmutable($date))->format('d/m/Y');
        } catch (\Throwable) {
            return $date;
        }
    }

    private function number(float $value): string
    {
        return number_format($value, 2, ',', '.');
    }

    private function usd(float $value): string
    {
        return 'US$ ' . number_format($value, 2, ',', '.');
    }

    private function percent(float $value): string
    {
        return number_format($value, 2, ',', '.') . '%';
    }

    private function updatedAtLabel(array $report): string
    {
        $updatedAt = $report['sales_data_updated_at'] ?? null;

        if (is_array($updatedAt) && !empty($updatedAt['label'])) {
            return (string) $updatedAt['label'];
        }

        return 'Actualizado: sin ventas';
    }

    private function safe(string $value): string
    {
        return iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
    }
}

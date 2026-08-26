<?php

namespace App\Services;

class AdvisorSalesWhatsappImageService
{
    private array $colors = [];
    private ?string $fontRegular = null;
    private ?string $fontBold = null;

    public function make(array $report): string
    {
        if (!extension_loaded('gd')) {
            throw new \RuntimeException('La extension GD de PHP no esta disponible.');
        }

        $rows = array_values($report['advisors'] ?? []);
        $rowHeight = 42;
        $height = 190 + (max(1, count($rows)) * $rowHeight) + 54 + 86;
        $width = 1280;
        $image = imagecreatetruecolor($width, $height);

        $this->bootFonts();
        $this->bootColors($image);
        imagefilledrectangle($image, 0, 0, $width, $height, $this->colors['bg']);

        $dateLabel = (string) ($report['date_label'] ?? $report['date'] ?? now('America/Bogota')->toDateString());
        $totals = $report['totals'] ?? [];

        $this->drawTable($image, $rows, $totals, $dateLabel, $height, $rowHeight, $this->updatedAtLabel($report));

        ob_start();
        imagepng($image, null, 9);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        return $png;
    }

    private function drawTable($image, array $rows, array $totals, string $dateLabel, int $height, int $rowHeight, string $updatedAtLabel): void
    {
        $tableTop = 34;
        $titleBottom = 112;
        $headTop = 112;
        $headBottom = 160;
        $firstRowY = 198;
        $totalTop = $firstRowY + (max(1, count($rows)) * $rowHeight) + 8;
        $tableBottom = $totalTop + 58;
        $this->roundedRect($image, 34, $tableTop, 1246, $tableBottom, 16, $this->colors['white']);
        imagerectangle($image, 34, $tableTop, 1246, $tableBottom, $this->colors['line']);
        $this->text($image, 'Advisor Sales', 58, 74, 26, $this->colors['ink'], true);
        $this->text($image, 'Period - ' . $dateLabel, 58, 99, 13, $this->colors['muted'], false);
        $this->textAligned($image, $this->usd((float) ($totals['total_usd'] ?? 0)), 1216, 74, 24, $this->colors['primary'], true, 'right');
        $this->textAligned($image, $this->int((float) ($totals['advisors_count'] ?? 0)) . ' advisors with sales', 1216, 99, 12, $this->colors['muted'], true, 'right');
        imageline($image, 58, $titleBottom, 1222, $titleBottom, $this->colors['line']);

        imagefilledrectangle($image, 34, $headTop, 1246, $headBottom, $this->colors['dark']);
        $this->text($image, 'ADVISOR', 58, 143, 12, $this->colors['white'], true);
        $this->textAligned($image, 'SALES', 720, 143, 12, $this->colors['white'], true, 'right');
        $this->textAligned($image, 'TRX', 875, 143, 12, $this->colors['white'], true, 'right');
        $this->textAligned($image, 'TKT', 1030, 143, 12, $this->colors['white'], true, 'right');
        $this->textAligned($image, 'UNITS/TKT', 1216, 143, 12, $this->colors['white'], true, 'right');

        $y = $firstRowY;
        if (!$rows) {
            $this->text($image, 'No advisor sales for this date.', 58, $y, 18, $this->colors['muted'], false);
        }

        foreach ($rows as $row) {
            imageline($image, 58, $y + 12, 1222, $y + 12, $this->colors['line']);
            $this->text($image, $this->truncate((string) ($row['advisor'] ?? 'Advisor'), 40), 58, $y - 3, 15, $this->colors['ink'], true);
            $code = (string) ($row['seller_code'] ?? '');
            if ($code !== '') {
                $this->text($image, $code, 58, $y + 15, 9, $this->colors['muted'], true);
            }
            $this->textAligned($image, $this->number((float) ($row['total_usd'] ?? 0)), 720, $y, 15, $this->colors['ink'], true, 'right');
            $this->textAligned($image, $this->number((float) ($row['trx'] ?? 0)), 875, $y, 14, $this->colors['ink'], true, 'right');
            $this->textAligned($image, $this->number((float) ($row['tkt_usd'] ?? 0)), 1030, $y, 14, $this->colors['ink'], true, 'right');
            $this->textAligned($image, $this->number((float) ($row['units_per_ticket'] ?? 0)), 1216, $y, 14, $this->colors['ink'], true, 'right');
            $y += $rowHeight;
        }

        imagefilledrectangle($image, 34, $totalTop, 1246, $tableBottom, $this->colors['primary']);
        $this->text($image, 'Total advisors', 58, $totalTop + 36, 20, $this->colors['white'], true);
        $this->textAligned($image, $this->number((float) ($totals['total_usd'] ?? 0)), 720, $totalTop + 36, 19, $this->colors['white'], true, 'right');
        $this->textAligned($image, $this->number((float) ($totals['trx'] ?? 0)), 875, $totalTop + 36, 18, $this->colors['white'], true, 'right');
        $this->textAligned($image, $this->number((float) ($totals['tkt_usd'] ?? 0)), 1030, $totalTop + 36, 18, $this->colors['white'], true, 'right');
        $this->textAligned($image, $this->number((float) ($totals['units_per_ticket'] ?? 0)), 1216, $totalTop + 36, 18, $this->colors['white'], true, 'right');

        $this->text($image, 'Generado automaticamente desde Portal Sky Free Shop', 34, $height - 28, 12, $this->colors['muted'], false);
        $this->textAligned($image, $updatedAtLabel, 1246, $height - 28, 12, $this->colors['muted'], false, 'right');
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
            'primary' => $this->color($image, '#970032'),
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

    private function updatedAtLabel(array $report): string
    {
        $updatedAt = $report['sales_data_updated_at'] ?? null;

        if (is_array($updatedAt) && !empty($updatedAt['label'])) {
            return (string) $updatedAt['label'];
        }

        return 'Actualizado: sin ventas';
    }

    private function number(float $value): string
    {
        return number_format($value, 2, ',', '.');
    }

    private function int(float $value): string
    {
        return number_format($value, 0, ',', '.');
    }

    private function usd(float $value): string
    {
        return 'US$ ' . number_format($value, 2, ',', '.');
    }

    private function truncate(string $value, int $max): string
    {
        return mb_strlen($value) > $max ? mb_substr($value, 0, $max - 3) . '...' : $value;
    }

    private function safe(string $value): string
    {
        return iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
    }
}

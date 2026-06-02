<?php

namespace App\Imports;

use App\Models\Product;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class ProductCatalogImport implements ToCollection, WithHeadingRow
{
    private int $processed = 0;
    private int $created = 0;
    private int $updated = 0;
    private int $skipped = 0;
    private int $duplicates = 0;
    private int $warnings = 0;
    private array $seenProductCodes = [];

    public function collection(Collection $rows)
    {
        if ($rows->isEmpty()) {
            return;
        }

        foreach ($rows as $row) {
            $row = is_array($row) ? $row : $row->toArray();
            $productCode = $this->pick($row, ['sku_code', 'sku', 'codigo', 'codigo_producto', 'product_code', 'cod_producto']);

            if ($productCode === '') {
                $this->skipped++;
                continue;
            }

            $skuMia = $this->pick($row, ['sku_mia', 'sku_miami']);
            $upc1 = $this->pick($row, ['upc1', 'upc', 'barcode', 'ean']);
            $upc2 = $this->pick($row, ['upc2']);
            $upc3 = $this->pick($row, ['upc3']);
            $description = $this->pick($row, ['product_description', 'description', 'descripcion', 'producto', 'product']);
            $classification = $this->pick($row, ['category_code', 'classification', 'clasificacion', 'codigo_categoria']);
            $classificationDesc = $this->pick($row, ['category_description', 'classification_desc', 'category', 'categoria', 'descripcion_categoria']);
            $productStatus = $this->pick($row, ['product_status', 'status', 'estado']);
            $costUnit = $this->number($this->pick($row, ['cost_unit', 'cost', 'costo']));
            $costUsd = $this->number($this->pick($row, ['cost_unit_usd', 'cost_usd', 'costo_usd', 'cogs_usd']));
            $retail = $this->number($this->pick($row, ['retail_price', 'regular_price', 'price_sale', 'precio', 'precio_venta']));
            $retailCop = $this->number($this->pick($row, ['retail_price_cop', 'precio_cop']));
            $precioMx = $this->number($this->pick($row, ['precio_mx']));
            $brand = $this->pick($row, ['brand_description', 'brand', 'marca']);
            $supplierCode = $this->pick($row, ['supplier_code', 'provider_code', 'codigo_proveedor']);
            $supplierName = $this->pick($row, ['supplier_description', 'provider_name', 'supplier', 'proveedor']);
            $type = $this->pick($row, ['type', 'tipo']);
            $origin = $this->pick($row, ['origen', 'origin']);
            $line = $this->pick($row, ['line', 'linea']);

            $incomingSnapshot = [
                'upc' => $upc1,
                'classification' => $classification,
                'classification_desc' => $classificationDesc,
                'description' => $description,
            ];

            if (isset($this->seenProductCodes[$productCode])) {
                $this->duplicates++;
                if ($this->hasCriticalConflict($this->seenProductCodes[$productCode], $incomingSnapshot)) {
                    $this->warnings++;
                    $this->skipped++;
                    continue;
                }
            } else {
                $this->seenProductCodes[$productCode] = $incomingSnapshot;
            }

            $product = Product::on('budget')->firstOrNew(['product_code' => $productCode]);
            $wasRecentlyCreated = ! $product->exists;

            $this->fillWhenPresent($product, [
                'sku_mia' => $skuMia,
                'upc' => $upc1,
                'upc2' => $upc2,
                'upc3' => $upc3,
                'description' => $description,
                'brand' => $brand,
                'classification' => $classification,
                'classification_desc' => $classificationDesc,
                'provider_code' => $supplierCode,
                'provider_name' => $supplierName,
                'regular_price' => $retail ?? $retailCop ?? $precioMx,
                'cost_usd' => $costUsd ?? $costUnit,
                'avg_cost_usd' => $costUsd ?? $costUnit,
                'currency' => 'USD',
                'type' => $type,
                'status' => $productStatus,
                'origin' => $origin,
                'line' => $line,
            ]);

            $product->currency = $product->currency ?: 'USD';
            $product->save();

            $wasRecentlyCreated ? $this->created++ : $this->updated++;
            $this->processed++;
        }
    }

    public function summary(): array
    {
        return [
            'processed' => $this->processed,
            'created' => $this->created,
            'updated' => $this->updated,
            'skipped' => $this->skipped,
            'duplicates' => $this->duplicates,
            'warnings' => $this->warnings,
        ];
    }

    private function pick(array $row, array $keys): string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $row)) {
                continue;
            }

            $value = $this->str($row[$key]);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function fillWhenPresent(Product $product, array $values): void
    {
        foreach ($values as $field => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $product->{$field} = $value;
        }
    }

    private function hasCriticalConflict(array $first, array $duplicate): bool
    {
        foreach (['upc', 'classification', 'classification_desc', 'description'] as $field) {
            $left = $this->normalizeForCompare($first[$field] ?? '');
            $right = $this->normalizeForCompare($duplicate[$field] ?? '');

            if ($left !== '' && $right !== '' && $left !== $right) {
                return true;
            }
        }

        return false;
    }

    private function normalizeForCompare(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/\s+/', ' ', $value);

        return (string) $value;
    }

    private function str(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_float($value) && floor($value) === $value) {
            $value = (int) $value;
        }

        return trim(str_replace("\xc2\xa0", ' ', (string) $value));
    }

    private function number(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);
        if ($text === '' || $text === '-') {
            return null;
        }

        $text = preg_replace('/[^\d,.\-]/', '', $text);
        if ($text === '') {
            return null;
        }

        $lastComma = strrpos($text, ',');
        $lastDot = strrpos($text, '.');

        if ($lastComma !== false && $lastDot !== false) {
            if ($lastDot > $lastComma) {
                $text = str_replace(',', '', $text);
            } else {
                $text = str_replace('.', '', $text);
                $text = str_replace(',', '.', $text);
            }
        } elseif ($lastComma !== false) {
            $parts = explode(',', $text);
            if (count($parts) === 2 && strlen($parts[1]) <= 2) {
                $text = str_replace(',', '.', $text);
            } else {
                $text = str_replace(',', '', $text);
            }
        }

        return is_numeric($text) ? (float) $text : null;
    }
}

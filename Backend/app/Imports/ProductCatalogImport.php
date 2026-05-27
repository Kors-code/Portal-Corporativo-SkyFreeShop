<?php

namespace App\Imports;

use App\Models\Product;
use App\Models\Inventario\Supplier;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class ProductCatalogImport implements ToCollection, WithHeadingRow
{
    public function collection(Collection $rows)
    {
        if ($rows->isEmpty()) {
            return;
        }

        DB::connection('budget')->transaction(function () use ($rows) {
            foreach ($rows as $row) {
                $productCode = $this->str($row['sku_code'] ?? $row['codigo'] ?? null);

                if ($productCode === '') {
                    continue;
                }

                $skuMia = $this->str($row['sku_mia'] ?? null);
                $upc1 = $this->str($row['upc1'] ?? null);
                $upc2 = $this->str($row['upc2'] ?? null);
                $upc3 = $this->str($row['upc3'] ?? null);
                $ref = $this->str($row['ref'] ?? null);

                $description = $this->str($row['product_description'] ?? null);
                $classification = $this->str($row['category_code'] ?? null);
                $classificationDesc = $this->str($row['category_description'] ?? null);
                $productStatus = $this->str($row['product_status'] ?? null);

                $costUnit = $this->number($row['cost_unit'] ?? null);
                $costUsd = $this->number($row['cost_unit_usd'] ?? null);
                $retail = $this->number($row['retail_price'] ?? null);
                $retailCop = $this->number($row['retail_price_cop'] ?? null);
                $precioMx = $this->number($row['precio_mx'] ?? null);

                $brandCode = $this->str($row['brand_code'] ?? null);
                $brand = $this->str($row['brand_description'] ?? null);

                $supplierCode = $this->str($row['supplier_code'] ?? null);
                $supplierName = $this->str($row['supplier_description'] ?? null);

                $type = $this->str($row['type'] ?? null);
                $origin = $this->str($row['origen'] ?? $row['origin'] ?? null);
                $line = $this->str($row['line'] ?? null);

                $supplierId = null;

                if ($supplierName !== '') {
                    $supplier = Supplier::on('budget')->firstOrCreate(
                        ['name' => $supplierName],
                        [
                            'origin' => $origin !== '' ? $origin : null,
                            'tipo' => 'local',
                        ]
                    );

                    $supplierId = $supplier->id;
                }

                // No dejar que un UPC repetido rompa la importación.
                if ($upc1 !== '') {
                    $conflict = Product::on('budget')
                        ->where('upc', $upc1)
                        ->where('product_code', '!=', $productCode)
                        ->first(['id', 'product_code']);

                    if ($conflict) {
                        Log::warning('UPC conflict detected during catalog import', [
                            'incoming_product_code' => $productCode,
                            'incoming_upc' => $upc1,
                            'existing_product_code' => $conflict->product_code,
                            'existing_product_id' => $conflict->id,
                        ]);
                    }
                }

                Product::on('budget')->updateOrCreate(
                    ['product_code' => $productCode],
                    [
                        'sku_mia' => $skuMia !== '' ? $skuMia : null,
                        'upc' => $upc1 !== '' ? $upc1 : null,
                        'upc2' => $upc2 !== '' ? $upc2 : null,
                        'upc3' => $upc3 !== '' ? $upc3 : null,
                        'description' => $description !== '' ? $description : null,
                        'brand' => $brand !== '' ? $brand : null,
                        'classification' => $classification !== '' ? $classification : null,
                        'classification_desc' => $classificationDesc !== '' ? $classificationDesc : null,
                        'provider_code' => $supplierCode !== '' ? $supplierCode : null,
                        'provider_name' => $supplierName !== '' ? $supplierName : null,
                        'regular_price' => $retail ?? $retailCop ?? $precioMx,
                        'cost_usd' => $costUsd ?? $costUnit,
                        'avg_cost_usd' => $costUsd ?? $costUnit,
                        'currency' => 'USD',
                        'type' => $type !== '' ? $type : null,
                        'status' => $productStatus !== '' ? $productStatus : null,
                        'origin' => $origin !== '' ? $origin : null,
                        'line' => $line !== '' ? $line : null,
                        'supplier_id' => $supplierId,
                    ]
                );

                DB::connection('mysql_personal')->table('catalog_products')->updateOrInsert(
                    ['sku' => $productCode],
                    [
                        'product' => $description !== '' ? $description : null,
                        'category' => $classificationDesc !== '' ? $classificationDesc : ($classification !== '' ? $classification : null),
                        'brand' => $brand !== '' ? $brand : null,
                        'supplier' => $supplierName !== '' ? $supplierName : null,
                        'cost_unit' => $costUsd ?? $costUnit,
                        'price_sale' => $retail ?? $retailCop ?? $precioMx,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        });
    }

    private function str(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return trim((string) $value);
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
        if ($text === '' || $text === null) {
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

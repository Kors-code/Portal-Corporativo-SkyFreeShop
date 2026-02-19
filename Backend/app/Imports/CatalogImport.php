<?php

namespace App\Imports;

class CatalogImport
{
    private $rows = 0;

    // Mapear fila normalizada (assoc header->valor) a columnas DB
    public function mapRow(array $row): array
    {
        // intentar varios nombres de columnas comunes
        $sku = $this->firstNotEmpty($row, ['sku_code', 'sku', 'codigo', 'codigo_producto']);
        $product = $this->firstNotEmpty($row, ['product_description', 'product', 'descripcion', 'description']);
        $category = $this->firstNotEmpty($row, ['category_description', 'category', 'clasificacion', 'classification']);
        $brand = $this->firstNotEmpty($row, ['brand_description', 'brand', 'marca']);
        $supplier = $this->firstNotEmpty($row, ['supplier_description', 'supplier', 'proveedor']);
        $cost_unit = $this->parseNumber($this->firstNotEmpty($row, ['cost_unit', 'costo', 'cost', 'cost_unit']));
        $price_sale = $this->parseNumber($this->firstNotEmpty($row, ['retail_price', 'price_sale', 'precio', 'precio_venta']));

        // Si la fila está vacía en campos relevantes, devolver array vacío para saltarla
        if ($sku === null && $product === null && $category === null && $brand === null && $supplier === null) {
            return [];
        }

        return [
            'sku' => $sku,
            'product' => $product,
            'category' => $category,
            'brand' => $brand,
            'supplier' => $supplier,
            'cost_unit' => $cost_unit,
            'price_sale' => $price_sale,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    public function getRowCount(): int
    {
        return $this->rows;
    }

    public function incrementRowCount(int $by = 1): void
    {
        $this->rows += $by;
    }

    protected function firstNotEmpty(array $row, array $keys)
    {
        foreach ($keys as $k) {
            if (!isset($row[$k])) continue;
            $v = trim((string)$row[$k]);
            if ($v !== '' && strtolower($v) !== 'null') return $v;
        }
        return null;
    }

    protected function parseNumber($v)
    {
        if ($v === null) return null;
        $s = trim((string)$v);
        if ($s === '' || strtolower($s) === 'null') return null;

        $s = str_replace([' ', "\u{00A0}"], '', $s);

        if (strpos($s, ',') !== false && strpos($s, '.') !== false) {
            $s = str_replace(',', '', $s);
        } elseif (strpos($s, ',') !== false) {
            $s = str_replace(',', '.', $s);
        }

        $s = preg_replace('/[^\d\.\-]/', '', $s);
        return is_numeric($s) ? (float)$s : null;
    }
}

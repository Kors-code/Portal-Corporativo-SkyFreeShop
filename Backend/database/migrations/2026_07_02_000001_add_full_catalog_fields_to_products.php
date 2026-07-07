<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('budget')->table('products', function (Blueprint $table) {
            $this->stringColumn($table, 'ref');
            $this->decimalColumn($table, 'cost_unit');
            $this->decimalColumn($table, 'retail_price_cop');
            $this->decimalColumn($table, 'precio_mx');
            $this->stringColumn($table, 'codigo_sat', 500);
            $this->stringColumn($table, 'purchase_unit', 100);
            $this->stringColumn($table, 'sales_unit', 100);
            $this->stringColumn($table, 'product_use', 255);
            $this->stringColumn($table, 'fraction', 100);
            $this->stringColumn($table, 'brand_code', 100);
            $this->stringColumn($table, 'color', 100);
            $this->stringColumn($table, 'size', 100);
            $this->stringColumn($table, 'origine', 100);
            $this->stringColumn($table, 'prepak', 100);
            $this->decimalColumn($table, 'cumt', 18, 6);
            $this->stringColumn($table, 'umt', 100);
            $this->stringColumn($table, 'aduana_description', 500);
            $this->stringColumn($table, 'no_constancia', 255);
            $this->stringColumn($table, 'tamano_etiqueta', 100);
            $this->stringColumn($table, 'complete_classification', 500);
            $this->decimalColumn($table, 'factor_caja', 18, 6);
            $this->stringColumn($table, 'make_country', 100);
            $this->decimalColumn($table, 'iva_venta');
            $this->stringColumn($table, 'objeto_de_impuesto', 500);
        });
    }

    public function down(): void
    {
        Schema::connection('budget')->table('products', function (Blueprint $table) {
            foreach ($this->columns() as $column) {
                if (Schema::connection('budget')->hasColumn('products', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function stringColumn(Blueprint $table, string $name, int $length = 255): void
    {
        if (!Schema::connection('budget')->hasColumn('products', $name)) {
            $table->string($name, $length)->nullable();
        }
    }

    private function decimalColumn(Blueprint $table, string $name, int $precision = 14, int $scale = 2): void
    {
        if (!Schema::connection('budget')->hasColumn('products', $name)) {
            $table->decimal($name, $precision, $scale)->nullable();
        }
    }

    private function columns(): array
    {
        return [
            'ref',
            'cost_unit',
            'retail_price_cop',
            'precio_mx',
            'codigo_sat',
            'purchase_unit',
            'sales_unit',
            'product_use',
            'fraction',
            'brand_code',
            'color',
            'size',
            'origine',
            'prepak',
            'cumt',
            'umt',
            'aduana_description',
            'no_constancia',
            'tamano_etiqueta',
            'complete_classification',
            'factor_caja',
            'make_country',
            'iva_venta',
            'objeto_de_impuesto',
        ];
    }
};

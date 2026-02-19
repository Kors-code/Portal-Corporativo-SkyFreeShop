<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class CommissionSellerDetailExport implements WithMultipleSheets
{
    protected array $categories;
    protected array $sales;
    protected array $meta;

    public function __construct(array $categories, array $sales, array $meta = [])
    {
        $this->categories = $categories;
        $this->sales = $sales;
        $this->meta = $meta;
    }

    public function sheets(): array
    {
        return [
            new \App\Exports\Sheets\SellerCategoriesSheet($this->categories, $this->meta),
            new \App\Exports\Sheets\SellerSalesSheet($this->sales, $this->meta),
        ];
    }
}

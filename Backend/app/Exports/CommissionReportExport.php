<?php
namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class CommissionReportExport implements WithMultipleSheets
{
    protected $sellers;
    protected $categories;
    protected $meta;

    public function __construct(array $sellers, array $categories, array $meta = [])
    {
        $this->sellers = $sellers;
        $this->categories = $categories;
        $this->meta = $meta;
    }

    public function sheets(): array
    {
        return [
            new \App\Exports\Sheets\SellersSheet($this->sellers, $this->meta),
            new \App\Exports\Sheets\CategoriesSheet($this->categories, $this->meta),
        ];
    }
}

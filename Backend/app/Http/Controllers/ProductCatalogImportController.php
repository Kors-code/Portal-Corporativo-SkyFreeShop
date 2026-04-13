<?php

namespace App\Http\Controllers;

use App\Imports\ProductCatalogImport;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class ProductCatalogImportController extends Controller
{
    public function import(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv'],
        ]);

        Excel::import(new ProductCatalogImport(), $request->file('file'));

        return response()->json([
            'message' => 'Catálogo importado correctamente.',
        ]);
    }
}
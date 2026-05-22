<?php

namespace App\Jobs;

use App\Http\Controllers\ImportSalesController;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Http\Request;

class ProcessSalesImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $path;
    public $storeId;
    public $replaceExisting;

    public function __construct($path, $storeId, $replaceExisting)
    {
        $this->path = $path;
        $this->storeId = $storeId;
        $this->replaceExisting = $replaceExisting;
    }

    public function handle()
    {
        $controller = new ImportSalesController();

        $request = new Request([
            'store_id' => $this->storeId,
            'replace_existing' => $this->replaceExisting,
        ]);

        $request->files->set(
            'file',
            new \Illuminate\Http\UploadedFile(
                storage_path('app/' . $this->path),
                basename($this->path),
                null,
                null,
                true
            )
        );

        $controller->import($request);
    }
}
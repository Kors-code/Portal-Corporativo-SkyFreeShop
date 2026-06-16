<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ImportSalesControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('database.connections.budget', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        Config::set('filesystems.default', 'local');
        Config::set('app.key', Config::get('app.key') ?: 'base64:'.base64_encode(random_bytes(32)));
        putenv('IMPORT_AUTOMATION_TOKEN=test-token');
        $_ENV['IMPORT_AUTOMATION_TOKEN'] = 'test-token';
        $_SERVER['IMPORT_AUTOMATION_TOKEN'] = 'test-token';

        DB::purge('budget');
        DB::connection('budget')->getPdo()->exec('PRAGMA foreign_keys = ON');

        $this->createBudgetSchema();
    }

    public function test_start_chunked_replaces_previous_batch_for_same_sales_company_month_and_year(): void
    {
        Storage::fake('local');

        $oldBatchId = DB::connection('budget')->table('import_batches')->insertGetId([
            'filename' => 'SALES DFP COLOMBIA Junio 2026.xlsx',
            'checksum' => 'old-checksum',
            'import_date' => '2026-06-01',
            'rows' => 25,
            'status' => 'processing',
            'note' => 'stale processing import',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::connection('budget')->table('sales')->insert([
            'import_batch_id' => $oldBatchId,
            'store_id' => 1,
            'seller_id' => 40,
            'product_id' => 1,
            'sale_date' => '2026-06-10',
            'sale_datetime' => '2026-06-10 12:00:00',
            'folio' => 'OLD',
            'quantity' => 1,
            'amount' => 10,
            'total' => 10,
            'value_pesos' => 10,
            'value_usd' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->withHeaders([
            'Accept' => 'application/json',
            'X-Automation-Token' => 'test-token',
        ])->post('/api/automation/import-sales', [
            'file' => $this->xlsxUpload('SALES DFP COLOMBIA Junio 2026.xlsx', [
                $this->salesHeaders(),
            ]),
            'store_id' => 1,
            'replace_existing' => '1',
        ]);

        $response->assertAccepted()
            ->assertJsonPath('next_row', 2)
            ->assertJsonPath('total_rows', 0);

        $this->assertDatabaseMissing('import_batches', ['id' => $oldBatchId], 'budget');
        $this->assertSame(0, DB::connection('budget')->table('sales')->where('folio', 'OLD')->count());
        $this->assertSame(1, DB::connection('budget')->table('import_batches')->where('filename', 'SALES DFP COLOMBIA Junio 2026.xlsx')->count());
    }

    public function test_automation_chunk_returns_404_when_batch_is_missing_before_sales_insert(): void
    {
        Storage::fake('local');
        $path = $this->storeSalesWorkbook('sales-imports/missing-batch.xlsx', [
            $this->salesHeaders(),
            $this->salesRow(),
        ]);

        $response = $this->withHeaders([
            'Accept' => 'application/json',
            'X-Automation-Token' => 'test-token',
        ])
            ->post('/api/automation/import-sales/chunk', [
                'path' => $path,
                'batch_id' => 999,
                'store_id' => 1,
                'next_row' => 2,
                'total_rows' => 1,
                'chunk_size' => 100,
            ]);

        $response->assertNotFound()
            ->assertJsonPath('message', 'El lote de importacion ya no existe.')
            ->assertJsonPath('batch_id', 999);

        $this->assertSame(0, DB::connection('budget')->table('sales')->count());
    }

    public function test_automation_chunk_imports_sale_when_batch_exists(): void
    {
        Storage::fake('local');
        $path = $this->storeSalesWorkbook('sales-imports/valid.xlsx', [
            $this->salesHeaders(),
            $this->salesRow([
                'folio' => 'TST0001',
                'applied_promotion' => str_repeat('PROMO-', 80),
            ]),
        ]);

        $batchId = DB::connection('budget')->table('import_batches')->insertGetId([
            'filename' => 'SALES DFP COLOMBIA Junio 2026.xlsx',
            'checksum' => 'valid-checksum',
            'import_date' => '2026-06-10',
            'rows' => 0,
            'status' => 'processing',
            'note' => 'Importacion por bloques',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->withHeaders([
            'Accept' => 'application/json',
            'X-Automation-Token' => 'test-token',
        ])
            ->post('/api/automation/import-sales/chunk', [
                'path' => $path,
                'batch_id' => $batchId,
                'store_id' => 1,
                'next_row' => 2,
                'total_rows' => 1,
                'chunk_size' => 100,
            ]);

        $response->assertOk()
            ->assertJsonPath('done', true)
            ->assertJsonPath('processed_rows', 1)
            ->assertJsonPath('summary.created.sales', 1);

        $sale = DB::connection('budget')->table('sales')->where('folio', 'TST0001')->first();

        $this->assertNotNull($sale);
        $this->assertSame($batchId, (int) $sale->import_batch_id);
        $this->assertSame(255, strlen($sale->applied_promotion));
        $this->assertDatabaseHas('import_batches', [
            'id' => $batchId,
            'status' => 'done',
            'rows' => 1,
        ], 'budget');
    }

    private function createBudgetSchema(): void
    {
        Schema::connection('budget')->create('import_batches', function ($table) {
            $table->id();
            $table->string('filename');
            $table->string('checksum')->unique();
            $table->date('import_date')->nullable();
            $table->integer('rows')->default(0);
            $table->string('status')->nullable();
            $table->longText('note')->nullable();
            $table->timestamps();
        });

        Schema::connection('budget')->create('stores', function ($table) {
            $table->id();
            $table->string('code')->nullable();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        Schema::connection('budget')->create('users', function ($table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable()->unique();
            $table->string('codigo_vendedor')->nullable();
            $table->timestamps();
        });

        Schema::connection('budget')->create('products', function ($table) {
            $table->id();
            $table->string('product_code')->nullable();
            $table->string('sku_mia')->nullable();
            $table->string('upc')->nullable();
            $table->string('upc2')->nullable();
            $table->string('upc3')->nullable();
            $table->string('description')->nullable();
            $table->string('classification')->nullable();
            $table->string('classification_desc')->nullable();
            $table->string('brand')->nullable();
            $table->decimal('regular_price', 12, 2)->nullable();
            $table->decimal('cost_usd', 12, 2)->nullable();
            $table->decimal('avg_cost_usd', 12, 2)->nullable();
            $table->string('currency')->nullable();
            $table->timestamps();
        });

        Schema::connection('budget')->create('passengers', function ($table) {
            $table->id();
            $table->string('passport_number')->nullable();
            $table->string('passenger_name')->nullable();
            $table->string('customer_code')->nullable();
            $table->string('nationality')->nullable();
            $table->date('date_birth')->nullable();
            $table->string('gender')->nullable();
            $table->timestamps();
        });

        Schema::connection('budget')->create('travel_itineraries', function ($table) {
            $table->id();
            $table->string('line_code')->nullable();
            $table->string('flight_cruise')->nullable();
            $table->string('origin')->nullable();
            $table->string('destination')->nullable();
            $table->timestamps();
        });

        Schema::connection('budget')->create('trms', function ($table) {
            $table->id();
            $table->date('date')->unique();
            $table->decimal('value', 12, 4)->nullable();
            $table->timestamps();
        });

        Schema::connection('budget')->create('sales', function ($table) {
            $table->id();
            $table->date('sale_date')->nullable();
            $table->dateTime('sale_datetime')->nullable();
            $table->string('folio')->nullable();
            $table->string('pdv')->nullable();
            $table->foreignId('product_id')->nullable();
            $table->foreignId('passenger_id')->nullable();
            $table->foreignId('travel_itinerary_id')->nullable();
            $table->string('customer_code')->nullable();
            $table->decimal('quantity', 12, 2)->nullable();
            $table->decimal('amount', 14, 2)->nullable();
            $table->decimal('amount_cop', 14, 2)->nullable();
            $table->decimal('discount', 14, 2)->nullable();
            $table->decimal('total', 14, 2)->nullable();
            $table->decimal('value_pesos', 14, 2)->nullable();
            $table->decimal('value_usd', 14, 2)->nullable();
            $table->decimal('cost', 14, 2)->nullable();
            $table->decimal('cogs_usd', 14, 2)->nullable();
            $table->string('currency')->nullable();
            $table->decimal('exchange_rate', 14, 4)->nullable();
            $table->decimal('exchange_rate_cogs', 14, 4)->nullable();
            $table->decimal('regular_price', 14, 2)->nullable();
            $table->string('status')->nullable();
            $table->string('applied_promotion')->nullable();
            $table->dateTime('cancellation_date')->nullable();
            $table->string('line_code')->nullable();
            $table->string('flight_cruise')->nullable();
            $table->string('seat')->nullable();
            $table->string('origin')->nullable();
            $table->string('destination')->nullable();
            $table->string('nationality')->nullable();
            $table->string('passport_number')->nullable();
            $table->string('passenger_name')->nullable();
            $table->date('date_birth')->nullable();
            $table->string('gender')->nullable();
            $table->longText('raw_payload')->nullable();
            $table->time('hora')->nullable();
            $table->foreignId('seller_id')->nullable();
            $table->foreignId('store_id')->nullable();
            $table->string('cashier')->nullable();
            $table->foreignId('cashier_id')->nullable();
            $table->foreignId('import_batch_id')->nullable()->constrained('import_batches')->nullOnDelete();
            $table->timestamps();
        });

        DB::connection('budget')->table('stores')->insert([
            'id' => 1,
            'code' => 'COLS1',
            'name' => 'DFP COLS1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::connection('budget')->table('users')->insert([
            'id' => 40,
            'name' => 'Default Seller',
            'email' => 'default@example.com',
            'codigo_vendedor' => '0000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::connection('budget')->table('products')->insert([
            'id' => 1,
            'product_code' => '100415',
            'description' => 'Test Product',
            'regular_price' => 111,
            'cost_usd' => 44.23,
            'avg_cost_usd' => 44.23,
            'currency' => 'USD',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function xlsxUpload(string $filename, array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'sales-test-');
        $this->writeWorkbook($path, $rows);

        return new UploadedFile(
            $path,
            $filename,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );
    }

    private function storeSalesWorkbook(string $path, array $rows): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'sales-test-');
        $this->writeWorkbook($tmp, $rows);
        Storage::put($path, file_get_contents($tmp));

        return $path;
    }

    private function writeWorkbook(string $path, array $rows): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($rows);

        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
    }

    private function salesHeaders(): array
    {
        return [
            'DATE',
            'FOLIO',
            'STORE',
            'SKU',
            'QUANTITY',
            'COGS PES',
            'COGS USD',
            'EXCHANGE RATE COGS',
            'AMOUNT PES',
            'AMOUNT USD',
            'EXCHANGE RATE SALE',
            'PRECIO REGULAR',
            'DISCOUNT',
            'TOTAL',
            'CUSTOMER CODE',
            'CURRENCY',
            'STATUS',
            'CANCELLATION DATE',
            'TIME',
            'APPLIED PROMOTION',
            'LINE',
            'FLIGHT/CRUISE',
            'SEAT',
            'ORIGIN',
            'DESTINATION',
            'NATIONALITY',
            'PASSPORT NUMBER',
            'PASSENGER NAME',
            'SELLER CODE',
            'SELLER',
            'CASHIER',
            'DATE BIRTH',
            'GENDER',
        ];
    }

    private function salesRow(array $overrides = []): array
    {
        $row = [
            'date' => '2026-06-10',
            'folio' => 'MAR000000035994',
            'store' => 'COLS1',
            'sku' => '100415',
            'quantity' => 1,
            'cogs_pes' => 168260.67,
            'cogs_usd' => 44.23,
            'exchange_rate_cogs' => 3804.22,
            'amount_pes' => 397542.06,
            'amount_usd' => 111,
            'exchange_rate_sale' => 3581,
            'precio_regular' => 111,
            'discount' => 0,
            'total' => 111,
            'customer_code' => 'PAX',
            'currency' => 'USD',
            'status' => 'ACTIVO',
            'cancellation_date' => '',
            'time' => '13:37:01',
            'applied_promotion' => '',
            'line' => 'B6',
            'flight_cruise' => '1703',
            'seat' => '15D',
            'origin' => 'SJU',
            'destination' => 'MDE',
            'nationality' => 'USA',
            'passport_number' => 'A00818240',
            'passenger_name' => 'FIGUEROA-YADIEL Y',
            'seller_code' => '7707',
            'seller' => 'ASTRID MILEIDY HURTADO RAMIREZ',
            'cashier' => 'JESSICA RIVERA OCHOA',
            'date_birth' => '13/03/1999',
            'gender' => 'MASCULINO',
        ];

        $row = array_merge($row, $overrides);

        return array_values($row);
    }
}

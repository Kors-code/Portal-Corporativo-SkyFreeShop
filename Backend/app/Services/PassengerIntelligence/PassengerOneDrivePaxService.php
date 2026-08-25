<?php

namespace App\Services\PassengerIntelligence;

use App\Models\PassengerIntelligence\PassengerSourceFile;
use Carbon\Carbon;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PassengerOneDrivePaxService
{
    private const GRAPH_BASE_URL = 'https://graph.microsoft.com/v1.0';
    private const IMPORTABLE_EXTENSIONS = ['xlsx', 'xls'];

    private ?string $accessToken = null;

    public function discoverFiles(bool $recursive = true): array
    {
        $items = $this->listFolderPath($this->rootFolder());
        $files = $this->collectFiles($items, $recursive);

        return collect($files)
            ->map(fn (array $item) => $this->upsertSourceFile($item))
            ->sortByDesc('source_last_modified_at')
            ->values()
            ->map(fn (PassengerSourceFile $file) => $this->filePayload($file))
            ->all();
    }

    public function importFile(PassengerSourceFile $sourceFile, PassengerExcelImportService $importer, ?int $userId = null): array
    {
        $path = $this->downloadToTemporaryPath($sourceFile);

        try {
            $result = $importer->importPath($path, $sourceFile->name, [
                'source_file_id' => $sourceFile->id,
                'source_type' => 'onedrive_skyfree_pax',
                'source_name' => 'OneDrive Sky Free PAX Col',
                'source_path' => $sourceFile->parent_path,
                'source_url' => $sourceFile->web_url,
                'data_type' => 'observed',
                'observed_scope' => 'commercial_flow',
                'imported_by' => $userId,
                'fail_on_duplicate' => false,
            ]);

            $sourceFile->update([
                'checksum' => $result['checksum'] ?? $sourceFile->checksum,
                'downloaded_at' => now(),
                'status' => $result['duplicate'] ? 'already_imported' : 'imported',
                'notes' => [
                    'batch_id' => $result['batch_id'] ?? null,
                    'duplicate' => (bool) ($result['duplicate'] ?? false),
                    'imported_rows' => $result['rows_imported'] ?? 0,
                ],
            ]);

            return [
                ...$result,
                'source_file' => $this->filePayload($sourceFile->fresh()),
            ];
        } catch (\Throwable $e) {
            $sourceFile->update([
                'status' => 'import_failed',
                'notes' => ['error' => $e->getMessage()],
            ]);

            throw $e;
        } finally {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    public function filePayload(PassengerSourceFile $file): array
    {
        return [
            'id' => $file->id,
            'provider' => $file->provider,
            'drive_item_id' => $file->drive_item_id,
            'name' => $file->name,
            'extension' => $file->extension,
            'size' => $file->size,
            'web_url' => $file->web_url,
            'parent_path' => $file->parent_path,
            'source_last_modified_at' => $file->source_last_modified_at?->toDateTimeString(),
            'discovered_at' => $file->discovered_at?->toDateTimeString(),
            'downloaded_at' => $file->downloaded_at?->toDateTimeString(),
            'status' => $file->status,
            'checksum' => $file->checksum,
            'notes' => $file->notes,
        ];
    }

    private function collectFiles(array $items, bool $recursive): array
    {
        $files = [];

        foreach ($items as $item) {
            if (isset($item['file']) && $this->isImportableFile($item)) {
                $files[] = $item;
                continue;
            }

            if ($recursive && isset($item['folder'], $item['id'])) {
                $files = array_merge($files, $this->collectFiles($this->listFolderById((string) $item['id']), true));
            }
        }

        return $files;
    }

    private function isImportableFile(array $item): bool
    {
        $extension = strtolower(pathinfo((string) ($item['name'] ?? ''), PATHINFO_EXTENSION));

        return in_array($extension, self::IMPORTABLE_EXTENSIONS, true);
    }

    private function upsertSourceFile(array $item): PassengerSourceFile
    {
        $name = (string) ($item['name'] ?? 'archivo.xlsx');
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $parent = $item['parentReference'] ?? [];
        $driveItemId = (string) ($item['id'] ?? '');
        $eTag = $item['eTag'] ?? null;
        $existing = PassengerSourceFile::where('provider', 'onedrive')
            ->where('drive_item_id', $driveItemId)
            ->first();
        $status = $existing
            && $existing->e_tag === $eTag
            && in_array($existing->status, ['imported', 'already_imported'], true)
                ? $existing->status
                : 'discovered';

        return PassengerSourceFile::updateOrCreate(
            [
                'provider' => 'onedrive',
                'drive_item_id' => $driveItemId,
            ],
            [
                'drive_id' => $parent['driveId'] ?? null,
                'name' => $name,
                'extension' => $extension,
                'mime_type' => $item['file']['mimeType'] ?? null,
                'size' => (int) ($item['size'] ?? 0),
                'web_url' => $item['webUrl'] ?? null,
                'parent_path' => $parent['path'] ?? $this->rootFolder(),
                'e_tag' => $eTag,
                'c_tag' => $item['cTag'] ?? null,
                'source_last_modified_at' => $this->parseGraphDate($item['lastModifiedDateTime'] ?? null),
                'discovered_at' => now(),
                'status' => $status,
            ]
        );
    }

    private function downloadToTemporaryPath(PassengerSourceFile $sourceFile): string
    {
        $response = $this->graph()->get($this->driveBasePath().'/items/'.$sourceFile->drive_item_id.'/content');

        if (!$response->successful()) {
            throw new RuntimeException('No se pudo descargar el archivo PAX desde OneDrive.');
        }

        $extension = $sourceFile->extension ?: 'xlsx';
        $tmp = tempnam(sys_get_temp_dir(), 'pi_pax_');
        $path = $tmp . '.' . $extension;

        file_put_contents($path, $response->body());
        @unlink($tmp);

        return $path;
    }

    private function listFolderPath(string $folderPath): array
    {
        $encodedPath = collect(explode('/', trim($folderPath, '/')))
            ->filter()
            ->map(fn (string $part) => rawurlencode($part))
            ->implode('/');

        return $this->pagedItems($this->driveBasePath().'/root:/'.$encodedPath.':/children');
    }

    private function listFolderById(string $itemId): array
    {
        return $this->pagedItems($this->driveBasePath().'/items/'.$itemId.'/children');
    }

    private function pagedItems(string $url): array
    {
        $items = [];

        while ($url) {
            $response = $this->graph()->get($url);

            if ($response->status() === 404) {
                throw new RuntimeException('No existe la carpeta PAX configurada en OneDrive.');
            }

            if (!$response->successful()) {
                throw new RuntimeException('Microsoft Graph respondio con error al listar PAX OneDrive.');
            }

            $data = $response->json();
            $items = array_merge($items, $data['value'] ?? []);
            $url = $data['@odata.nextLink'] ?? null;
        }

        return $items;
    }

    private function graph(): PendingRequest
    {
        return Http::withToken($this->accessToken())
            ->withOptions($this->httpOptions())
            ->acceptJson()
            ->timeout(90);
    }

    private function accessToken(): string
    {
        if ($this->accessToken) {
            return $this->accessToken;
        }

        $tenantId = $this->config('tenant_id');
        $clientId = $this->config('client_id');
        $clientSecret = $this->config('client_secret');

        if (!$tenantId || !$clientId || !$clientSecret || !$this->userId()) {
            throw new RuntimeException('Faltan variables de OneDrive para Passenger Intelligence PAX.');
        }

        $response = Http::asForm()
            ->withOptions($this->httpOptions())
            ->timeout(30)
            ->post("https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token", [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'scope' => 'https://graph.microsoft.com/.default',
                'grant_type' => 'client_credentials',
            ]);

        if (!$response->successful()) {
            throw new RuntimeException('No se pudo autenticar con Microsoft Graph para PAX.');
        }

        return $this->accessToken = (string) $response->json('access_token');
    }

    private function driveBasePath(): string
    {
        return self::GRAPH_BASE_URL.'/users/'.$this->userId().'/drive';
    }

    private function parseGraphDate(?string $value): ?Carbon
    {
        return $value ? Carbon::parse($value) : null;
    }

    private function rootFolder(): string
    {
        return trim((string) $this->config('root_folder'), '/');
    }

    private function userId(): ?string
    {
        $value = $this->config('user_id');

        return $value ? trim($value) : null;
    }

    private function config(string $key, mixed $default = null): mixed
    {
        return config("services.onedrive_passenger_pax.{$key}", $default);
    }

    private function httpOptions(): array
    {
        $caBundle = $this->config('ca_bundle');

        if ($caBundle) {
            return ['verify' => $caBundle];
        }

        return [];
    }
}

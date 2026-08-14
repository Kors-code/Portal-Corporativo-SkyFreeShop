<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class OneDriveAdvisorInfoService
{
    private const GRAPH_BASE_URL = 'https://graph.microsoft.com/v1.0';

    private ?string $accessToken = null;

    public function listProviders(): array
    {
        $items = $this->listChildren($this->rootFolder());

        $providers = collect($items)
            ->filter(fn (array $item) => isset($item['folder']))
            ->map(fn (array $item) => $this->mapFolder($item))
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();

        $files = collect($items)
            ->filter(fn (array $item) => isset($item['file']))
            ->map(fn (array $item) => $this->mapFile($item))
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();

        return [
            'root_folder' => $this->rootFolder(),
            'providers' => $providers,
            'root_files' => $files,
        ];
    }

    public function listProviderFiles(string $providerId): array
    {
        $provider = $this->item($providerId);
        $items = $this->listChildrenById($providerId);

        return [
            'provider' => $this->mapFolder($provider),
            'files' => collect($items)
                ->filter(fn (array $item) => isset($item['file']))
                ->map(fn (array $item) => $this->mapFile($item))
                ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
                ->values()
                ->all(),
            'folders' => collect($items)
                ->filter(fn (array $item) => isset($item['folder']))
                ->map(fn (array $item) => $this->mapFolder($item))
                ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
                ->values()
                ->all(),
        ];
    }

    public function file(string $itemId): array
    {
        return $this->mapFile($this->item($itemId));
    }

    public function contentResponse(string $itemId)
    {
        $item = $this->item($itemId);
        $response = $this->graph()->get($this->driveBasePath().'/items/'.$itemId.'/content');

        if (!$response->successful()) {
            throw new RuntimeException('No se pudo descargar el archivo desde OneDrive.');
        }

        return response($response->body(), 200, [
            'Content-Type' => $item['file']['mimeType'] ?? 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="'.$this->safeFilename($item['name'] ?? 'archivo').'"',
        ]);
    }

    private function listChildren(string $folderPath): array
    {
        $encodedPath = collect(explode('/', trim($folderPath, '/')))
            ->filter()
            ->map(fn (string $part) => rawurlencode($part))
            ->implode('/');

        $url = $this->driveBasePath().'/root:/'.$encodedPath.':/children';

        return $this->pagedItems($url);
    }

    private function listChildrenById(string $itemId): array
    {
        return $this->pagedItems($this->driveBasePath().'/items/'.$itemId.'/children');
    }

    private function pagedItems(string $url): array
    {
        $items = [];

        while ($url) {
            $response = $this->graph()->get($url);

            if ($response->status() === 404) {
                throw new RuntimeException('No existe la carpeta configurada en OneDrive.');
            }

            if (!$response->successful()) {
                throw new RuntimeException('Microsoft Graph respondio con error al listar OneDrive.');
            }

            $data = $response->json();
            $items = array_merge($items, $data['value'] ?? []);
            $url = $data['@odata.nextLink'] ?? null;
        }

        return $items;
    }

    private function item(string $itemId): array
    {
        $response = $this->graph()->get($this->driveBasePath().'/items/'.$itemId);

        if (!$response->successful()) {
            throw new RuntimeException('No se encontro el archivo en OneDrive.');
        }

        return $response->json();
    }

    private function graph(): PendingRequest
    {
        return Http::withToken($this->accessToken())
            ->withOptions($this->httpOptions())
            ->acceptJson()
            ->timeout(60);
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
            throw new RuntimeException('Faltan variables de OneDrive para Info Asesores.');
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
            throw new RuntimeException('No se pudo autenticar con Microsoft Graph.');
        }

        return $this->accessToken = (string) $response->json('access_token');
    }

    private function driveBasePath(): string
    {
        return self::GRAPH_BASE_URL.'/users/'.$this->userId().'/drive';
    }

    private function mapFolder(array $item): array
    {
        return [
            'id' => $item['id'] ?? '',
            'name' => $item['name'] ?? 'Sin nombre',
            'webUrl' => $item['webUrl'] ?? null,
            'updatedAt' => $item['lastModifiedDateTime'] ?? null,
            'childCount' => $item['folder']['childCount'] ?? 0,
        ];
    }

    private function mapFile(array $item): array
    {
        $name = $item['name'] ?? 'archivo';
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        return [
            'id' => $item['id'] ?? '',
            'name' => $name,
            'extension' => $extension,
            'mimeType' => $item['file']['mimeType'] ?? null,
            'size' => $item['size'] ?? 0,
            'webUrl' => $item['webUrl'] ?? null,
            'updatedAt' => $item['lastModifiedDateTime'] ?? null,
            'previewable' => in_array($extension, ['pdf', 'png', 'jpg', 'jpeg', 'gif', 'webp'], true),
        ];
    }

    private function safeFilename(string $name): string
    {
        return Str::of($name)->replace('"', "'")->toString();
    }

    private function rootFolder(): string
    {
        return trim((string) $this->config('root_folder', 'Info Asesores'), '/');
    }

    private function userId(): ?string
    {
        $value = $this->config('user_id');

        return $value ? trim($value) : null;
    }

    private function config(string $key, mixed $default = null): mixed
    {
        return config("services.onedrive_advisor_info.{$key}", $default);
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

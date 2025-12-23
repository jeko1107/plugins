<?php

namespace Boy132\Subdomains\Models;

use App\Models\Server;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class Subdomain extends Model implements HasLabel
{
    protected $fillable = [
        'name',
        'record_type',
        'domain_id',
        'server_id',
        'cloudflare_id',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::created(function (self $model) {
            $model->createOnCloudflare();
        });

        static::updated(function (self $model) {
            $model->updateOnCloudflare();
        });

        static::deleted(function (self $model) {
            $model->deleteOnCloudflare();
        });
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(CloudflareDomain::class, 'domain_id');
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function getLabel(): string|Htmlable|null
    {
        return $this->name . '.' . ($this->domain ? $this->domain->name : 'unknown');
    }

    protected function createOnCloudflare(): void
    {
        if (! $this->server || ! $this->server->allocation || $this->cloudflare_id) {
            return;
        }

        if (! $this->domain || ! $this->domain->cloudflare_id) {
             return;
        }

        $response = Http::cloudflare()->post("zones/{$this->domain->cloudflare_id}/dns_records", [
            'name'    => $this->name,
            'ttl'     => 120,
            'type'    => $this->record_type,
            'comment' => 'Created by Pelican Subdomains plugin',
            'content' => ($this->server->allocation->public_ip ?? $this->server->allocation->ip),
            'proxied' => false,
        ])->json();

        if (! empty($response['success'])) {
            $dnsRecord = $response['result'];

            $this->updateQuietly([
                'cloudflare_id' => $dnsRecord['id'] ?? null,
            ]);
        }
    }

    protected function updateOnCloudflare(): void
    {
        if (! $this->server || ! $this->server->allocation || ! $this->cloudflare_id) {
            return;
        }

        if (! $this->domain || ! $this->domain->cloudflare_id) {
             return;
        }

        Http::cloudflare()->patch("zones/{$this->domain->cloudflare_id}/dns_records/{$this->cloudflare_id}", [
            'name'    => $this->name,
            'ttl'     => 120,
            'type'    => $this->record_type,
            'comment' => 'Updated by Pelican Subdomains plugin',
            'content' => ($this->server->allocation->public_ip ?? $this->server->allocation->ip),
            'proxied' => false,
        ]);
    }

    protected function deleteOnCloudflare(): void
    {
        if (! $this->cloudflare_id || ! $this->domain || ! $this->domain->cloudflare_id) {
            Log::info('subdomains: deleteOnCloudflare skipped', [
                'id' => $this->id ?? null,
                'cloudflare_id' => $this->cloudflare_id ?? null,
            ]);
            return;
        }

        try {
            $response = Http::cloudflare()->delete("zones/{$this->domain->cloudflare_id}/dns_records/{$this->cloudflare_id}");

            $json = method_exists($response, 'json') ? $response->json() : null;

            Log::info('subdomains: deleteOnCloudflare response', [
                'http_status' => method_exists($response, 'status') ? $response->status() : null,
                'body' => $json,
                'id' => $this->id,
                'cloudflare_id' => $this->cloudflare_id,
            ]);

            if (! empty($json['success'])) {
                $this->updateQuietly(['cloudflare_id' => null]);
            }
        } catch (\Throwable $e) {
            Log::error('subdomains: deleteOnCloudflare failed', [
                'exception' => $e->getMessage(),
                'id' => $this->id ?? null,
                'cloudflare_id' => $this->cloudflare_id ?? null,
            ]);
        }
    }
}

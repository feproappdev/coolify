<?php

namespace App\Jobs;

use App\Models\Server;
use App\Services\ConfigurationRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ServerConnectionCheckJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public $timeout = 30;

    public function __construct(
        public Server $server,
        public bool $disableMux = true
    ) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('server-connection-check-'.$this->server->uuid))->expireAfter(45)->dontRelease()];
    }

    private function disableSshMux(): void
    {
        $configRepository = app(ConfigurationRepository::class);
        $configRepository->disableSshMux();
    }

    public function handle()
    {
        try {
            Log::info('ServerConnectionCheck: Starting check', [
                'server_id' => $this->server->id,
                'server_name' => $this->server->name,
                'server_ip' => $this->server->ip,
            ]);

            // Check if server is disabled
            if ($this->server->settings->force_disabled) {
                $this->server->settings->update([
                    'is_reachable' => false,
                    'is_usable' => false,
                ]);
                Log::debug('ServerConnectionCheck: Server is disabled', [
                    'server_id' => $this->server->id,
                    'server_name' => $this->server->name,
                ]);

                return;
            }

            // Check Hetzner server status if applicable
            if ($this->server->hetzner_server_id && $this->server->cloudProviderToken) {
                $this->checkHetznerStatus();
            }

            // Temporarily disable mux if requested
            if ($this->disableMux) {
                $this->disableSshMux();
            }

            // Check basic connectivity first
            $isReachable = $this->checkConnection();

            Log::info('ServerConnectionCheck: Connection result', [
                'server_id' => $this->server->id,
                'server_name' => $this->server->name,
                'is_reachable' => $isReachable,
            ]);

            if (! $isReachable) {
                $this->server->settings->update([
                    'is_reachable' => false,
                    'is_usable' => false,
                ]);

                Log::warning('ServerConnectionCheck: Server not reachable', [
                    'server_id' => $this->server->id,
                    'server_name' => $this->server->name,
                    'server_ip' => $this->server->ip,
                ]);

                return;
            }

            // Server is reachable, check if Docker is available
            $isUsable = $this->checkDockerAvailability();

            Log::info('ServerConnectionCheck: Docker result', [
                'server_id' => $this->server->id,
                'server_name' => $this->server->name,
                'is_usable' => $isUsable,
            ]);

            $this->server->settings->update([
                'is_reachable' => true,
                'is_usable' => $isUsable,
            ]);

            Log::info('ServerConnectionCheck: Completed successfully', [
                'server_id' => $this->server->id,
                'server_name' => $this->server->name,
                'is_reachable' => true,
                'is_usable' => $isUsable,
            ]);

        } catch (\Throwable $e) {

            Log::error('ServerConnectionCheckJob failed', [
                'error' => $e->getMessage(),
                'server_id' => $this->server->id,
            ]);
            $this->server->settings->update([
                'is_reachable' => false,
                'is_usable' => false,
            ]);

            throw $e;
        }
    }

    private function checkHetznerStatus(): void
    {
        try {
            $hetznerService = new \App\Services\HetznerService($this->server->cloudProviderToken->token);
            $serverData = $hetznerService->getServer($this->server->hetzner_server_id);
            $status = $serverData['status'] ?? null;

        } catch (\Throwable $e) {
            Log::debug('ServerConnectionCheck: Hetzner status check failed', [
                'server_id' => $this->server->id,
                'error' => $e->getMessage(),
            ]);
        }
        if ($this->server->hetzner_server_status !== $status) {
            $this->server->update(['hetzner_server_status' => $status]);
            $this->server->hetzner_server_status = $status;
            if ($status === 'off') {
                ray('Server is powered off, marking as unreachable');
                throw new \Exception('Server is powered off');
            }
        }

    }

    private function checkConnection(): bool
    {
        try {
            // Use instant_remote_process with a simple command
            // This will automatically handle mux, sudo, IPv6, Cloudflare tunnel, etc.
            $output = instant_remote_process_with_timeout(
                ['ls -la /'],
                $this->server,
                false // don't throw error
            );

            $result = $output !== null;
            
            Log::debug('ServerConnectionCheck: checkConnection result', [
                'server_id' => $this->server->id,
                'server_name' => $this->server->name,
                'output_is_null' => $output === null,
                'result' => $result,
            ]);

            return $result;
        } catch (\Throwable $e) {
            Log::warning('ServerConnectionCheck: Connection check failed with exception', [
                'server_id' => $this->server->id,
                'server_name' => $this->server->name,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    private function checkDockerAvailability(): bool
    {
        try {
            // Use instant_remote_process to check Docker
            // The function will automatically handle sudo for non-root users
            $output = instant_remote_process_with_timeout(
                ['docker version --format json'],
                $this->server,
                false // don't throw error
            );

            if ($output === null) {
                return false;
            }

            // Try to parse the JSON output to ensure Docker is really working
            $output = trim($output);
            if (! empty($output)) {
                $dockerInfo = json_decode($output, true);

                return isset($dockerInfo['Server']['Version']);
            }

            return false;
        } catch (\Throwable $e) {
            Log::debug('ServerConnectionCheck: Docker check failed', [
                'server_id' => $this->server->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}

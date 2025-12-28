<?php

namespace App\Actions\Service;

use App\Models\Service;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\Yaml\Yaml;

class StartService
{
    use AsAction;

    public string $jobQueue = 'high';

    public function handle(Service $service, bool $pullLatestImages = false, bool $stopBeforeStart = false)
    {
        $service->parse();
        if ($stopBeforeStart) {
            StopService::run(service: $service, dockerCleanup: false);
        }
        $service->saveComposeConfigs();
        $service->isConfigurationChanged(save: true);
        $workdir = $service->workdir();
        
        $commands[] = "echo 'Saved configuration files to {$workdir}.'";
        $commands[] = "touch {$workdir}/.env";
        
        // HACK: Detect if server is Swarm and use docker stack deploy for HA
        // This works regardless of destination type (StandaloneDocker or SwarmDocker)
        $isSwarm = $service->server->isSwarm();
        
        if ($pullLatestImages && !$isSwarm) {
            $commands[] = "echo 'Pulling images.'";
            $commands[] = "docker compose --project-directory {$workdir} pull";
        }
        
        if ($service->networks()->count() > 0 && !$isSwarm) {
            $commands[] = "echo 'Creating Docker network.'";
            $commands[] = "docker network inspect $service->uuid >/dev/null 2>&1 || docker network create --attachable $service->uuid";
        }
        
        $commands[] = 'echo Starting service.';
        
        if ($isSwarm) {
            // Docker Swarm deployment for HA
            // Create overlay network for the service (Swarm requires overlay networks)
            $commands[] = "echo 'Creating Swarm overlay network...'";
            $commands[] = "docker network inspect {$service->uuid} >/dev/null 2>&1 || docker network create --driver overlay --attachable {$service->uuid}";
            
            // Preprocess docker-compose.yml to remove unsupported Swarm options
            $commands[] = "echo 'Preprocessing compose file for Swarm compatibility...'";
            // Remove container_name (not supported in Swarm)
            $commands[] = "sed -i '/container_name:/d' {$workdir}/docker-compose.yml";
            // Remove restart: policy (Swarm uses deploy.restart_policy)
            $commands[] = "sed -i '/^[[:space:]]*restart:/d' {$workdir}/docker-compose.yml";
            // Change external network to not external (we create it above)
            $commands[] = "sed -i 's/external: true/external: false/g' {$workdir}/docker-compose.yml";
            // Add overlay driver to networks
            $commands[] = "sed -i '/^networks:/,/^[^ ]/{s/driver: bridge/driver: overlay/}' {$workdir}/docker-compose.yml";
            // Ensure coolify-overlay network is included for proxy connectivity
            $commands[] = "grep -q 'coolify-overlay' {$workdir}/docker-compose.yml || sed -i '/^networks:/a\\    coolify-overlay:\\n        external: true' {$workdir}/docker-compose.yml";
            
            $commands[] = "echo 'Deploying to Docker Swarm for HA...'";
            $commands[] = "docker stack deploy -c {$workdir}/docker-compose.yml {$service->uuid} --with-registry-auth --detach=false 2>&1 || docker stack deploy -c {$workdir}/docker-compose.yml {$service->uuid} --with-registry-auth";
        } else {
            // Regular docker compose deployment
            $commands[] = "docker compose --project-directory {$workdir} -f {$workdir}/docker-compose.yml --project-name {$service->uuid} up -d --remove-orphans --force-recreate --build";
        }
        
        if (!$isSwarm) {
            $commands[] = "docker network connect $service->uuid coolify-proxy >/dev/null 2>&1 || true";
            if (data_get($service, 'connect_to_docker_network')) {
                $compose = data_get($service, 'docker_compose', []);
                $network = $service->destination->network;
                $serviceNames = data_get(Yaml::parse($compose), 'services', []);
                foreach ($serviceNames as $serviceName => $serviceConfig) {
                    $commands[] = "docker network connect --alias {$serviceName}-{$service->uuid} $network {$serviceName}-{$service->uuid} >/dev/null 2>&1 || true";
                }
            }
        }

        return remote_process($commands, $service->server, type_uuid: $service->uuid, callEventOnFinish: 'ServiceStatusChanged');
    }
}

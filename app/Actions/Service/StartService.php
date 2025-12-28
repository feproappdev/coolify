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

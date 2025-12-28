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
            
            // Preprocess docker-compose.yml using Python for proper YAML handling
            // The service network is pre-created as overlay, so mark it external
            $commands[] = "echo 'Preprocessing compose file for Swarm compatibility...'";
            $serviceUuid = $service->uuid;
            $pythonScript = <<<PYTHON
import yaml
import sys

file = sys.argv[1]
service_uuid = '{$serviceUuid}'

with open(file, 'r') as f:
    data = yaml.safe_load(f)

# Remove container_name and restart from all services
for name, svc in data.get('services', {}).items():
    if 'container_name' in svc:
        del svc['container_name']
    if 'restart' in svc:
        del svc['restart']

# Process networks section - all networks should be external since we pre-create them
nets = data.get('networks', {})
for net_name in list(nets.keys()):
    # All networks (coolify-overlay and service network) are pre-created as overlay
    # Mark them all as external so docker stack doesn't try to create them
    nets[net_name] = {'external': True, 'name': net_name}

with open(file, 'w') as f:
    yaml.dump(data, f, default_flow_style=False, sort_keys=False, allow_unicode=True)

print('Compose file preprocessed for Swarm')
PYTHON;
            $commands[] = "python3 -c ".escapeshellarg($pythonScript)." {$workdir}/docker-compose.yml";
            
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

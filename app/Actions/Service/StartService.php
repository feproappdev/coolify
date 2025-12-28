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
            // Also inline env_file since Docker Swarm doesn't support env_file at runtime
            $commands[] = "echo 'Preprocessing compose file for Swarm compatibility...'";
            $serviceUuid = $service->uuid;
            $pythonScript = <<<PYTHON
import yaml
import sys
import os
import re

file = sys.argv[1]
workdir = os.path.dirname(file)
service_uuid = '{$serviceUuid}'

# Load .env file if exists
env_vars = {}
env_file_path = os.path.join(workdir, '.env')
if os.path.exists(env_file_path):
    with open(env_file_path, 'r') as ef:
        for line in ef:
            line = line.strip()
            if line and not line.startswith('#') and '=' in line:
                key, _, value = line.partition('=')
                env_vars[key.strip()] = value.strip()

def substitute_vars(val):
    if not isinstance(val, str):
        return val
    # Replace \${VAR} and \${VAR:-default} patterns
    def replacer(m):
        var_expr = m.group(1)
        if ':-' in var_expr:
            var_name, default = var_expr.split(':-', 1)
            return env_vars.get(var_name, default)
        elif '-' in var_expr:
            var_name, default = var_expr.split('-', 1)
            return env_vars.get(var_name, default)
        return env_vars.get(var_expr, m.group(0))
    val = re.sub(r'\\\$\{([^}]+)\}', replacer, val)
    val = re.sub(r'\\\$([A-Za-z_][A-Za-z0-9_]*)', lambda m: env_vars.get(m.group(1), m.group(0)), val)
    return val

with open(file, 'r') as f:
    data = yaml.safe_load(f)

# Get all service names for hostname substitution
service_names = list(data.get('services', {}).keys())

# Process each service
for name, svc in data.get('services', {}).items():
    # Remove unsupported options
    if 'container_name' in svc:
        del svc['container_name']
    if 'restart' in svc:
        del svc['restart']
    # Remove env_file - we'll inline the variables
    if 'env_file' in svc:
        del svc['env_file']
    # Remove healthcheck - Swarm health checks cause cascading failures when services start
    # because depends_on is not honored and services fail health checks while waiting for deps
    if 'healthcheck' in svc:
        del svc['healthcheck']
    # Add network aliases so services can find each other by simple name
    if 'networks' in svc:
        for net_name, net_config in svc['networks'].items():
            if net_config is None:
                svc['networks'][net_name] = {'aliases': [name]}
            elif isinstance(net_config, dict):
                if 'aliases' not in net_config:
                    net_config['aliases'] = [name]
                elif name not in net_config['aliases']:
                    net_config['aliases'].append(name)
    # Substitute variables in environment AND replace service hostnames with stack-qualified names
    if 'environment' in svc:
        env = svc['environment']
        def process_env_val(val):
            val = substitute_vars(val)
            # Replace simple service hostnames with stack-qualified names
            # e.g., postgres -> stackname_postgres, redis -> stackname_redis
            if isinstance(val, str):
                for svc_name in service_names:
                    # Handle URLs like redis://default@redis:6379
                    val = re.sub(r'@' + svc_name + r':', '@' + service_uuid + '_' + svc_name + ':', val)
                    # Handle postgres host references
                    if val.lower() == svc_name:
                        val = service_uuid + '_' + svc_name
            return val
        if isinstance(env, dict):
            svc['environment'] = {k: process_env_val(v) for k, v in env.items()}
        elif isinstance(env, list):
            new_env = []
            for item in env:
                if '=' in str(item):
                    k, _, v = str(item).partition('=')
                    new_env.append(f'{k}={process_env_val(v)}')
                else:
                    new_env.append(process_env_val(item))
            svc['environment'] = new_env

# Process networks section - all networks should be external since we pre-create them
nets = data.get('networks', {})
for net_name in list(nets.keys()):
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

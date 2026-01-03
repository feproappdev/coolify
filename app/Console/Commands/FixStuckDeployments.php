<?php

namespace App\Console\Commands;

use App\Jobs\ApplicationDeploymentJob;
use App\Models\ApplicationDeploymentQueue;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class FixStuckDeployments extends Command
{
    protected $signature = 'deployments:fix-stuck {--minutes=10 : Minutes after which a deployment is considered stuck}';
    protected $description = 'Fix stuck deployments by re-dispatching them';

    public function handle()
    {
        $minutes = $this->option('minutes');
        
        // Find deployments that are in_progress but have no logs (never started)
        // or have been running for too long
        $stuck = ApplicationDeploymentQueue::whereIn('status', ['queued', 'in_progress'])
            ->where('created_at', '<', now()->subMinutes($minutes))
            ->get();

        if ($stuck->isEmpty()) {
            $this->info('No stuck deployments found.');
            return 0;
        }

        $this->warn("Found {$stuck->count()} stuck deployment(s).");

        foreach ($stuck as $deployment) {
            $logsEmpty = empty($deployment->logs) || $deployment->logs === '[]' || $deployment->logs === 'null';
            
            if ($logsEmpty && $deployment->status === 'in_progress') {
                // Job was queued but never started - re-dispatch it
                $this->info("Re-dispatching stuck deployment #{$deployment->id} (no logs, never started)");
                
                $deployment->status = 'queued';
                $deployment->logs = null;
                $deployment->save();
                
                dispatch(new ApplicationDeploymentJob($deployment->id));
                
                Log::warning("Re-dispatched stuck deployment", [
                    'deployment_id' => $deployment->id,
                    'application_id' => $deployment->application_id,
                    'created_at' => $deployment->created_at,
                ]);
            } elseif ($deployment->status === 'queued') {
                // Still queued after X minutes - try dispatching again
                $this->info("Re-dispatching queued deployment #{$deployment->id}");
                
                dispatch(new ApplicationDeploymentJob($deployment->id));
                
                Log::warning("Re-dispatched queued deployment", [
                    'deployment_id' => $deployment->id,
                ]);
            } else {
                // Has logs but stuck - might be genuinely stuck mid-execution
                $this->warn("Deployment #{$deployment->id} appears stuck mid-execution. Consider cancelling manually.");
            }
        }

        return 0;
    }
}

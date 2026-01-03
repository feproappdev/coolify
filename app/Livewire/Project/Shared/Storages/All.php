<?php

namespace App\Livewire\Project\Shared\Storages;

use Livewire\Component;

class All extends Component
{
    public $resource;

    protected $listeners = ['refreshStorages' => 'refreshStoragesData'];

    /**
     * Refresh the persistent storages data from the database
     * This ensures the component has the latest data after adding/updating/deleting storages
     */
    public function refreshStoragesData()
    {
        // Reload the persistentStorages relationship from database
        $this->resource->load('persistentStorages');
    }

    public function getFirstStorageIdProperty()
    {
        // Ensure we have fresh data
        if (!$this->resource->relationLoaded('persistentStorages')) {
            $this->resource->load('persistentStorages');
        }

        if ($this->resource->persistentStorages->isEmpty()) {
            return null;
        }

        // Use the storage with the smallest ID as the "first" one
        // This ensures stability even when storages are deleted
        return $this->resource->persistentStorages->sortBy('id')->first()->id;
    }
}

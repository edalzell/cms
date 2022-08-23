<?php

namespace Statamic\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Statamic\Assets\AssetReferenceUpdater;
use Statamic\Events\AssetDeleted;
use Statamic\Events\AssetReferencesUpdated;
use Statamic\Events\AssetSaved;

class UpdateAssetReferences implements ShouldQueue
{
    use Concerns\GetsItemsContainingData;

    /**
     * Register the listeners for the subscriber.
     *
     * @param  \Illuminate\Events\Dispatcher  $events
     */
    public function subscribe($events)
    {
        if (config('statamic.system.update_references') === false) {
            return;
        }

        $events->listen(AssetSaved::class, self::class.'@handleSaved');
        $events->listen(AssetDeleted::class, self::class.'@handleDeleted');
    }

    /**
     * Handle the asset saved event.
     *
     * @param  AssetSaved  $event
     */
    public function handleSaved(AssetSaved $event)
    {
        $asset = $event->asset;
        $originalPath = $asset->getOriginal('path');
        $newPath = $asset->path();

        $this->replaceReferences($asset, $originalPath, $newPath);
    }

    /**
     * Handle the asset deleted event.
     *
     * @param  AssetDeleted  $event
     */
    public function handleDeleted(AssetDeleted $event)
    {
        $asset = $event->asset;
        $originalPath = $asset->getOriginal('path');
        $newPath = null;

        $this->replaceReferences($asset, $originalPath, $newPath);
    }

    /**
     * Replace asset references.
     *
     * @param  \Statamic\Assets\Asset  $asset
     * @param  string  $originalPath
     * @param  string  $newPath
     */
    protected function replaceReferences($asset, $originalPath, $newPath)
    {
        if (! $originalPath || $originalPath === $newPath) {
            return;
        }

        $container = $asset->container()->handle();

        $updatedItems = $this
            ->getItemsContainingData()
            ->map(function ($item) use ($container, $originalPath, $newPath) {
                return AssetReferenceUpdater::item($item)
                    ->filterByContainer($container)
                    ->updateReferences($originalPath, $newPath);
            })
            ->filter();

        if ($updatedItems->isNotEmpty()) {
            AssetReferencesUpdated::dispatch($asset);
        }
    }
}

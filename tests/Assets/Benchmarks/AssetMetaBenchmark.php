<?php

namespace Tests\Assets\Benchmarks;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Blink;
use Statamic\Facades\Stache;
use Statamic\Facades\YAML;
use Statamic\Search\InsertMultipleJob;
use Tests\Fakes\CountingFilesystemAdapter;
use Tests\PreventSavingStacheItemsToDisk;
use Tests\TestCase;

/**
 * Measures what generating asset meta asks of a remote disk.
 *
 * Not part of the default suite. Run it with:
 *
 *   ./vendor/bin/phpunit tests/Assets/Benchmarks/AssetMetaBenchmark.php
 *
 * The table is printed to STDERR, and written as markdown to the path in the
 * ASSET_BENCHMARK_OUTPUT environment variable when it is set.
 */
class AssetMetaBenchmark extends TestCase
{
    use PreventSavingStacheItemsToDisk;

    private const FILE_SIZE = 2 * 1024 * 1024;

    private static array $results = [];

    private CountingFilesystemAdapter $adapter;
    private $container;
    private array $images = [];
    private string $searchPath;

    public function setUp(): void
    {
        parent::setUp();

        $this->adapter = new CountingFilesystemAdapter;

        Storage::set('remote', new FilesystemAdapter(new Filesystem($this->adapter), $this->adapter, []));

        $this->container = tap(AssetContainer::make('remote')->disk('remote'))->save();

        $this->searchPath = sys_get_temp_dir().'/statamic-asset-benchmark-'.uniqid();

        config([
            'statamic.search.drivers.local.path' => $this->searchPath,
            'statamic.search.indexes.default' => [
                'driver' => 'local',
                'searchables' => 'assets:remote',
                'fields' => ['title'],
            ],
        ]);

        $this->seedImages();
    }

    public function tearDown(): void
    {
        array_map('unlink', glob($this->searchPath.'/*') ?: []);
        @rmdir($this->searchPath);

        parent::tearDown();
    }

    public static function tearDownAfterClass(): void
    {
        $columns = ['object_reads', 'object_bytes', 'meta_reads', 'lists', 'heads', 'writes', 'ms'];

        $table = '| Scenario | '.implode(' | ', $columns)." |\n";
        $table .= '|---|'.str_repeat('---:|', count($columns))."\n";

        foreach (self::$results as $scenario => $result) {
            $result['object_bytes'] = number_format($result['object_bytes'] / 1024 / 1024, 2).' MB';
            $table .= "| {$scenario} | ".implode(' | ', array_map(fn ($column) => $result[$column], $columns))." |\n";
        }

        fwrite(STDERR, "\n".$table."\n");

        if ($path = getenv('ASSET_BENCHMARK_OUTPUT')) {
            file_put_contents($path, $table);
        }

        parent::tearDownAfterClass();
    }

    #[Test]
    public function generating_meta_for_images()
    {
        $this->measure('1. generateMeta, '.count($this->images).' images', function () {
            foreach ($this->images as $path => [$width, $height]) {
                $meta = $this->container->makeAsset($path)->generateMeta();

                $this->assertSame([$width, $height], [$meta['width'], $meta['height']], $path);
            }
        });
    }

    #[Test]
    public function reading_null_meta_values()
    {
        // getID3 cannot make sense of this, so its width, height and duration are all null.
        $this->adapter->seed('broken.mp4', Fixtures::junk(self::FILE_SIZE));

        $asset = $this->container->makeAsset('broken.mp4');
        $asset->meta();

        $this->measure('2. null width/height/duration, read 10 times', function () use ($asset) {
            foreach (range(1, 10) as $i) {
                $this->assertNull($asset->width());
                $this->assertNull($asset->height());
                $this->assertNull($asset->duration());
            }
        });
    }

    #[Test]
    public function indexing_without_sidecars()
    {
        $this->measure('3a. InsertMultipleJob, cold cache, no sidecars', fn () => $this->runJob());

        $this->freshProcess();

        $this->measure('3b. InsertMultipleJob again, warm cache', fn () => $this->runJob());
    }

    #[Test]
    public function indexing_with_sidecars()
    {
        $this->seedSidecars();

        $this->measure('3c. InsertMultipleJob, cold cache, sidecars present', fn () => $this->runJob());
    }

    #[Test]
    public function indexing_after_being_interrupted()
    {
        $this->adapter->failAfterObjectReads((int) (count($this->images) / 2));

        try {
            $this->runJob();
            $interrupted = false;
        } catch (\RuntimeException $e) {
            $interrupted = true;
        }

        $this->freshProcess();

        $this->measure('3d. InsertMultipleJob retried after dying halfway'.($interrupted ? '' : ' (never interrupted)'), fn () => $this->runJob());
    }

    private function measure(string $scenario, \Closure $callback): void
    {
        $this->adapter->reset();

        $start = microtime(true);
        $callback();
        $ms = (int) round((microtime(true) - $start) * 1000);

        self::$results[$scenario] = $this->adapter->counts() + ['ms' => $ms];

        $this->addToAssertionCount(1);
    }

    private function runJob(): void
    {
        $documents = collect(array_keys($this->images))
            ->push('document.pdf')
            ->map(fn ($path) => "asset::remote::{$path}");

        (new InsertMultipleJob('default', null, $documents))->handle();
    }

    /**
     * Drop everything a new PHP process would not have, but keep the cache.
     */
    private function freshProcess(): void
    {
        Blink::flush();
        Stache::clear();

        $this->container = AssetContainer::find('remote');
    }

    private function seedImages(): void
    {
        $size = self::FILE_SIZE;

        foreach (range(1, 10) as $i) {
            $this->addImage("photos/plain-{$i}.jpg", Fixtures::jpeg(800, 600, $size), 800, 600);
        }

        // Dimensions are swapped by the EXIF orientation.
        $this->addImage('photos/rotated.jpg', Fixtures::jpeg(800, 600, $size, orientation: 6), 600, 800);

        // Cameras and editors put thumbnails and color profiles ahead of the dimensions.
        $this->addImage('photos/thumbnail.jpg', Fixtures::jpeg(800, 600, $size, headerPadding: 40 * 1024), 800, 600);
        $this->addImage('photos/profile.jpg', Fixtures::jpeg(800, 600, $size, orientation: 6, headerPadding: 300 * 1024), 600, 800);

        foreach (range(1, 3) as $i) {
            $this->addImage("graphics/image-{$i}.png", Fixtures::png(640, 480, $size), 640, 480);
        }

        foreach (range(1, 2) as $i) {
            $this->addImage("graphics/image-{$i}.webp", Fixtures::webp(320, 240, $size), 320, 240);
            $this->addImage("graphics/image-{$i}.gif", Fixtures::gif(200, 100, $size), 200, 100);
        }

        $this->adapter->seed('document.pdf', Fixtures::junk($size));
    }

    private function addImage(string $path, string $contents, int $width, int $height): void
    {
        $this->adapter->seed($path, $contents);

        $this->images[$path] = [$width, $height];
    }

    private function seedSidecars(): void
    {
        foreach ($this->images as $path => [$width, $height]) {
            $this->adapter->seed(dirname($path).'/.meta/'.basename($path).'.yaml', YAML::dump([
                'data' => [],
                'size' => self::FILE_SIZE,
                'last_modified' => 1700000000,
                'width' => $width,
                'height' => $height,
                'mime_type' => 'image/jpeg',
                'duration' => null,
            ]));
        }

        $this->adapter->seed('.meta/document.pdf.yaml', YAML::dump([
            'data' => [],
            'size' => self::FILE_SIZE,
            'last_modified' => 1700000000,
            'width' => null,
            'height' => null,
            'mime_type' => 'application/pdf',
            'duration' => null,
        ]));

        Cache::flush();
    }
}

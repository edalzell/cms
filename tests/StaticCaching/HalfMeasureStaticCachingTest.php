<?php

namespace Tests\StaticCaching;

use Illuminate\Support\Carbon;
use Statamic\StaticCaching\Replacer;
use Symfony\Component\HttpFoundation\Response;
use Tests\FakesContent;
use Tests\FakesViews;
use Tests\PreventSavingStacheItemsToDisk;
use Tests\TestCase;

class HalfMeasureStaticCachingTest extends TestCase
{
    use FakesContent;
    use FakesViews;
    use PreventSavingStacheItemsToDisk;

    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('statamic.static_caching.strategy', 'half');
        $app['config']->set('statamic.static_caching.replacers', [TestReplacer::class]);
    }

    /** @test */
    public function it_statically_caches()
    {
        $this->withStandardFakeViews();
        $this->viewShouldReturnRaw('default', '<h1>{{ title }}</h1> {{ content }}');

        $page = $this->createPage('about', [
            'with' => [
                'title' => 'The About Page',
                'content' => 'This is the about page.',
            ],
        ]);

        $this
            ->get('/about')
            ->assertOk()
            ->assertSee('<h1>The About Page</h1> <p>This is the about page.</p>', false);

        $page
            ->set('content', 'Updated content')
            ->saveQuietly(); // Save quietly to prevent the invalidator from clearing the statically cached page.

        $this
            ->get('/about')
            ->assertOk()
            ->assertSee('<h1>The About Page</h1> <p>This is the about page.</p>', false);
    }

    /** @test */
    public function it_performs_replacements()
    {
        Carbon::setTestNow(Carbon::parse('2019-01-01'));

        $this->withStandardFakeViews();
        $this->viewShouldReturnRaw('default', '{{ now format="Y-m-d" }} REPLACEME');

        $this->createPage('about');

        $response = $this->get('/about')->assertOk();
        $this->assertSame('2019-01-01 INITIAL-2019-01-01', $response->getContent());

        Carbon::setTestNow(Carbon::parse('2020-05-23'));
        $response = $this->get('/about')->assertOk();
        $this->assertSame('2019-01-01 SUBSEQUENT-2020-05-23', $response->getContent());
    }

    /** @test */
    public function it_can_keep_parts_dynamic_using_nocache_tags()
    {
        // Use a tag that outputs something dynamic.
        // It will just increment by one every time it's used.

        app()->instance('example_count', 0);

        (new class extends \Statamic\Tags\Tags
        {
            public static $handle = 'example_count';

            public function index()
            {
                $count = app('example_count');
                $count++;
                app()->instance('example_count', $count);

                return $count;
            }
        })::register();

        $this->withStandardFakeViews();
        $this->viewShouldReturnRaw('default', '{{ example_count }} {{ nocache }}{{ example_count }}{{ /nocache }}');

        $this->createPage('about');

        $this
            ->get('/about')
            ->assertOk()
            ->assertSee('1 2', false);

        $this
            ->get('/about')
            ->assertOk()
            ->assertSee('1 3', false);
    }

    /** @test */
    public function it_can_keep_the_cascade_parts_dynamic_using_nocache_tags()
    {
        // The "now" variable is generated in the cascade on every request.

        Carbon::setTestNow(Carbon::parse('2019-01-01'));

        $this->withStandardFakeViews();
        $this->viewShouldReturnRaw('default', '{{ now format="Y-m-d" }} {{ nocache }}{{ now format="Y-m-d" }}{{ /nocache }}');

        $this->createPage('about');

        $this
            ->get('/about')
            ->assertOk()
            ->assertSee('2019-01-01 2019-01-01', false);

        Carbon::setTestNow(Carbon::parse('2020-05-23'));

        $this
            ->get('/about')
            ->assertOk()
            ->assertSee('2019-01-01 2020-05-23', false);
    }

    /** @test */
    public function it_can_keep_the_urls_page_parts_dynamic_using_nocache_tags()
    {
        // The "page" variable (i.e. the about entry) is inserted into the cascade on every request.

        $this->withStandardFakeViews();
        $this->viewShouldReturnRaw('default', '<h1>{{ title }}</h1> {{ text }} {{ nocache }}{{ text }}{{ /nocache }}');

        $page = $this->createPage('about', [
            'with' => [
                'title' => 'The About Page',
                'text' => 'This is the about page.',
            ],
        ]);

        $this
            ->get('/about')
            ->assertOk()
            ->assertSee('<h1>The About Page</h1> This is the about page. This is the about page.', false);

        $page
            ->set('text', 'Updated text')
            ->saveQuietly(); // Save quietly to prevent the invalidator from clearing the statically cached page.

        $this
            ->get('/about')
            ->assertOk()
            ->assertSee('<h1>The About Page</h1> This is the about page. Updated text', false);
    }
}

class TestReplacer implements Replacer
{
    public function prepareResponseToCache(Response $response, Response $initial)
    {
        $initial->setContent(
            str_replace('REPLACEME', 'INITIAL-'.Carbon::now()->format('Y-m-d'), $initial->getContent())
        );
    }

    public function replaceInCachedResponse(Response $response)
    {
        $response->setContent(
            str_replace('REPLACEME', 'SUBSEQUENT-'.Carbon::now()->format('Y-m-d'), $response->getContent())
        );
    }
}

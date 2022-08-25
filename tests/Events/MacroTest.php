<?php

namespace Tests\Events;

use Illuminate\Support\Facades\Event;
use Statamic\Events\Event as StatamicEvent;
use Tests\TestCase;

class MacroTest extends TestCase
{
    /** @test */
    public function it_can_forget_a_listener_using_string_notation()
    {
        Event::listen(JokeSaved::class, 'Listener@handle');

        $this->assertRegisteredListenersForEvent(JokeSaved::class, [
            'Listener@handle',
        ]);

        Event::forgetListener(JokeSaved::class, 'Listener@handle');

        $this->assertNoRegisteredListenersForEvent(JokeSaved::class);
    }

    /** @test */
    public function it_can_forget_a_listener_using_array_notation()
    {
        Event::listen(JokeSaved::class, ['Listener', 'handle']);

        $this->assertRegisteredListenersForEvent(JokeSaved::class, [
            ['Listener', 'handle'],
        ]);

        Event::forgetListener(JokeSaved::class, ['Listener', 'handle']);

        $this->assertNoRegisteredListenersForEvent(JokeSaved::class);
    }

    /** @test */
    public function forgetting_a_listener_doesnt_affect_other_events_or_listeners()
    {
        Event::listen(JokeSaved::class, 'SubscriberOne@handle');
        Event::listen(JokeSaved::class, 'SubscriberTwo@handle');
        Event::listen(JokeDeleted::class, 'SubscriberOne@handle');

        $this->assertRegisteredListenersForEvent(JokeSaved::class, [
            'SubscriberOne@handle',
            'SubscriberTwo@handle',
        ]);

        $this->assertRegisteredListenersForEvent(JokeDeleted::class, [
            'SubscriberOne@handle',
        ]);

        Event::forgetListener(JokeSaved::class, 'SubscriberOne@handle');

        $this->assertRegisteredListenersForEvent(JokeSaved::class, [
            'SubscriberTwo@handle',
        ]);

        $this->assertRegisteredListenersForEvent(JokeDeleted::class, [
            'SubscriberOne@handle',
        ]);
    }

    private function assertRegisteredListenersForEvent($event, $listeners)
    {
        $this->assertEquals($listeners, array_values(app('events')->getRawListeners()[$event]));
    }

    private function assertNoRegisteredListenersForEvent($event)
    {
        $this->assertCount(0, array_values(app('events')->getRawListeners()[$event]));
    }
}

class JokeSaved extends StatamicEvent
{
    //
}

class JokeDeleted extends StatamicEvent
{
    //
}

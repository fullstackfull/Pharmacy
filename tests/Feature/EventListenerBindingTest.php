<?php

namespace Tests\Feature;

use App\Providers\EventServiceProvider;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\TestCase;

/**
 * Every listener must accept the event it is registered for.
 *
 * Automatic listener discovery is off in this application, so `EventServiceProvider::$listen` is the
 * only thing binding an event to a listener — and nothing checks that the listener's `handle()`
 * actually accepts what it will be handed. PHP enforces the parameter type at call time, so a
 * mismatch is not a warning: dispatching that event throws a TypeError, and the request that
 * dispatched it returns a 500.
 *
 * That is exactly what had happened to the order-edit due-payment notification. Its listener was
 * registered for `OrderEditDuePaymentEvent` and type-hinted `OrderEditEvent`, so a customer who
 * owed more money after their order was edited was never told — the request died instead.
 *
 * This walks the whole map, so the next one is caught here.
 */
class EventListenerBindingTest extends TestCase
{
    public function test_every_listener_accepts_the_event_it_is_registered_for(): void
    {
        $listen = (new ReflectionClass(EventServiceProvider::class))
            ->getDefaultProperties()['listen'] ?? [];

        $this->assertNotEmpty($listen, 'the event map should not be empty');

        foreach ($listen as $event => $listeners) {
            if (!class_exists($event)) {
                continue;   // a string event name has no class to check against
            }

            foreach ((array) $listeners as $listener) {
                $accepts = $this->handledType($listener);

                if ($accepts === null) {
                    continue;   // an untyped handler accepts anything, which is its own choice
                }

                $this->assertTrue(
                    $event === $accepts || is_subclass_of($event, $accepts),
                    sprintf(
                        '%s is registered for %s but its handle() only accepts %s — dispatching that '
                        . 'event throws a TypeError',
                        class_basename($listener),
                        class_basename($event),
                        class_basename($accepts),
                    ),
                );
            }
        }
    }

    /** The class a listener's `handle()` will accept, or null when it is untyped. */
    private function handledType(string $listener): ?string
    {
        if (!class_exists($listener) || !method_exists($listener, 'handle')) {
            return null;
        }

        $parameters = (new ReflectionMethod($listener, 'handle'))->getParameters();
        $type = $parameters === [] ? null : $parameters[0]->getType();

        return $type instanceof ReflectionNamedType && !$type->isBuiltin() ? $type->getName() : null;
    }
}

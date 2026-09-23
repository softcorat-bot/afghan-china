<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Every route must point at a controller class and method that exist.
 * A namespace typo (e.g. `Pos\` vs `POS\`) otherwise only surfaces as a 500
 * on a case-sensitive server — or as `route:list` crashing.
 */
class RouteControllersTest extends TestCase
{
    public function test_every_route_action_resolves(): void
    {
        $broken = [];

        foreach (Route::getRoutes() as $route) {
            $action = $route->getActionName();
            if ($action === 'Closure' || ! str_contains($action, '@')) {
                continue;
            }
            [$class, $method] = explode('@', $action);
            if (! class_exists($class)) {
                $broken[] = "{$route->uri()} → missing class {$class}";
            } elseif (! method_exists($class, $method)) {
                $broken[] = "{$route->uri()} → missing method {$class}@{$method}";
            }
        }

        $this->assertSame([], $broken, implode("\n", $broken));
    }
}

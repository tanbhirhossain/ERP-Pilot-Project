<?php

/**
 * Structural integrity guards.
 *
 * These assertions are deliberately static — they inspect the route table and
 * the source tree rather than issuing HTTP requests, so they run in well under
 * a second and can never be skipped for being slow.
 *
 * They exist because the project shipped two whole classes of dead route:
 *
 *  1. Six HRM controllers were referenced by ~28 routes but the class files did
 *     not exist, so every one of those URLs returned HTTP 500
 *     ("Target class ... does not exist") and `php artisan route:list` crashed.
 *  2. Seven Inertia::render() targets named .vue pages that were never written,
 *     so those controllers threw at render time.
 *
 * Neither was caught, because no test exercised them. Both are caught here.
 */

use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;

/**
 * Resolve every PHP file that could contain an Inertia::render() call.
 *
 * @return list<string>
 */
function phpSourceFiles(): array
{
    $files = [];

    foreach (['modules', 'app', 'routes'] as $dir) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(base_path($dir), FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
    }

    return $files;
}

test('every route targets a controller class and method that exist', function (): void {
    $broken = [];

    foreach (Route::getRoutes() as $route) {
        $action = $route->getAction();
        $uses = $action['uses'] ?? null;

        if (! is_string($uses)) {
            continue; // closure routes — nothing to resolve
        }

        if (str_contains($uses, '@')) {
            [$class, $method] = explode('@', $uses, 2);

            if (! class_exists($class)) {
                $broken[] = "{$route->uri()} → missing class {$class}";

                continue;
            }

            if (! method_exists($class, $method)) {
                $broken[] = "{$route->uri()} → missing method {$class}::{$method}()";
            }

            continue;
        }

        if (class_exists($uses) && ! method_exists($uses, '__invoke')) {
            $broken[] = "{$route->uri()} → {$uses} is not invokable";
        }
    }

    expect($broken, implode(PHP_EOL, $broken))->toBe([]);
});

test('every route middleware class exists', function (): void {
    $broken = [];

    foreach (Route::getRoutes() as $route) {
        foreach ((array) ($route->getAction()['middleware'] ?? []) as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }

            $class = explode(':', $middleware)[0];

            if (str_contains($class, '\\') && ! class_exists($class)) {
                $broken[] = "{$route->uri()} → missing middleware {$class}";
            }
        }
    }

    expect($broken, implode(PHP_EOL, $broken))->toBe([]);
});

test('every Inertia render target has a matching Vue page', function (): void {
    $missing = [];

    foreach (phpSourceFiles() as $file) {
        $source = file_get_contents($file);

        if ($source === false || ! str_contains($source, 'Inertia::render')) {
            continue;
        }

        // Only literal targets can be resolved statically; variable targets are
        // covered by the feature tests for the module that builds them.
        preg_match_all('/Inertia::render\(\s*[\'"]([^\'"]+)[\'"]/', $source, $matches);

        foreach ($matches[1] as $target) {
            if (inertiaPageExists($target)) {
                continue;
            }

            $relative = str_replace(base_path().'/', '', $file);
            $missing[] = "{$target}  (rendered by {$relative})";
        }
    }

    expect(array_values(array_unique($missing)), implode(PHP_EOL, $missing))->toBe([]);
});

test('every permission enforced by a route is seeded', function (): void {
    // Needs the real ACL, so seed it. Without this the in-memory test database
    // has an empty permissions table and every route looks phantom.
    $this->seed(DatabaseSeeder::class);

    $seeded = Permission::pluck('name')->flip();
    $phantom = [];

    foreach (Route::getRoutes() as $route) {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware) || ! str_contains($middleware, 'PermissionMiddleware:')) {
                continue;
            }

            $argument = substr($middleware, strpos($middleware, 'PermissionMiddleware:') + 21);

            foreach (explode('|', $argument) as $group) {
                foreach (explode(',', $group) as $permission) {
                    $permission = trim($permission);

                    if ($permission !== '' && ! isset($seeded[$permission])) {
                        $phantom[] = "{$permission}  (route /{$route->uri()})";
                    }
                }
            }
        }
    }

    // A permission that no seeder creates can never be granted, so the route it
    // guards is permanently 403 for every role except the wildcard Super Admin.
    expect(array_values(array_unique($phantom)), implode(PHP_EOL, $phantom))->toBe([]);
});

/**
 * Mirror the resolution logic in resources/js/app.ts:
 * `MODULE::Path/Page` → modules/MODULE/Resources/js/Pages/Path/Page.vue,
 * anything else       → resources/js/pages/<name>.vue.
 */
function inertiaPageExists(string $name): bool
{
    if (preg_match('/^([^:]+)::(.+)$/', $name, $m)) {
        return is_file(base_path("modules/{$m[1]}/Resources/js/Pages/{$m[2]}.vue"));
    }

    return is_file(base_path("resources/js/pages/{$name}.vue"));
}

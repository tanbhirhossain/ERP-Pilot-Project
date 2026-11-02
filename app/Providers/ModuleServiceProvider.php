<?php

namespace App\Providers;

use Exception;
use Illuminate\Support\ServiceProvider;

class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $providersPath = base_path('modules/*/Providers/*ServiceProvider.php');
        $files = glob($providersPath) ?: [];

        foreach ($files as $filename) {
            $class = $this->resolveNamespace($filename);

            if (class_exists($class)) {
                $this->app->register($class);
            }
        }
    }

    private function resolveNamespace(string $filename): string
    {
        $realPath = realpath($filename);

        if ($realPath === false) {
            throw new Exception("Could not resolve module provider path: {$filename}");
        }

        $pathParts = explode(DIRECTORY_SEPARATOR, $realPath);
        $modulesIndex = array_search('modules', array_map('strtolower', $pathParts), true);

        if ($modulesIndex === false || ! isset($pathParts[$modulesIndex + 1])) {
            throw new Exception("Could not determine module name from path: {$realPath}");
        }

        $moduleName = $pathParts[$modulesIndex + 1];
        $className = basename($realPath, '.php');

        return "Modules\\{$moduleName}\\Providers\\{$className}";
    }
}

<?php

namespace App\Console\Commands;

use App\Support\Modules\ModuleGenerator;
use Illuminate\Console\Command;

class MakeModuleCommand extends Command
{
    protected $signature = 'make:module {name : The module name} {--force : Overwrite existing module files}';

    protected $description = 'Create a new application module with standard folders and bootstrap files';

    public function handle(ModuleGenerator $generator): int
    {
        $module = $generator->normalizeModule((string) $this->argument('name'));
        $force = (bool) $this->option('force');

        $generator->createDirectories($module, [
            'Database/Factories',
            'Database/Migrations',
            'Database/Seeders',
            'Http/Controllers',
            'Interfaces',
            'Models',
            'Providers',
            'Repositories',
            'Requests',
            'Resources/js/Pages',
            'Resources/views',
            'Routes',
            'Services',
        ]);

        $files = [
            $generator->modulePath($module, "Providers/{$module}ServiceProvider.php") => $generator->providerStub($module),
            $generator->modulePath($module, 'Routes/web.php') => $generator->webRoutesStub($module),
            $generator->modulePath($module, 'Routes/api.php') => $generator->apiRoutesStub(),
            $generator->modulePath($module, 'Resources/js/Pages/Index.vue') => $generator->pageStub($module),
        ];

        foreach ($files as $path => $contents) {
            $created = $generator->writeFile($path, $contents, $force);
            $this->line(($created ? '<info>CREATED</info> ' : '<comment>EXISTS</comment>  ').$path);
        }

        $this->info("Module [{$module}] is ready.");

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Support\Modules\ModuleGenerator;
use Illuminate\Console\Command;

class ModuleMakeControllerCommand extends Command
{
    protected $signature = 'module:make-controller {module : The module name} {name : The controller name} {--resource : Generate resource-style methods} {--force : Overwrite existing file}';

    protected $description = 'Create a controller inside a module';

    public function handle(ModuleGenerator $generator): int
    {
        $module = (string) $this->argument('module');
        $class = $generator->normalizeClass((string) $this->argument('name'), 'Controller');

        if (! $generator->moduleExists($module)) {
            $this->error($generator->missingModuleMessage($module));

            return self::FAILURE;
        }

        $path = $generator->modulePath($module, "Http/Controllers/{$class}.php");
        $created = $generator->writeFile($path, $generator->controllerStub($module, $class, (bool) $this->option('resource')), (bool) $this->option('force'));

        $this->line(($created ? '<info>CREATED</info> ' : '<comment>EXISTS</comment>  ').$path);

        return self::SUCCESS;
    }
}

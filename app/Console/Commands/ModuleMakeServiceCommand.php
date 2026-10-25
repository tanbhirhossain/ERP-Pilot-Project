<?php

namespace App\Console\Commands;

use App\Support\Modules\ModuleGenerator;
use Illuminate\Console\Command;

class ModuleMakeServiceCommand extends Command
{
    protected $signature = 'module:make-service {module : The module name} {name : The service name} {--force : Overwrite existing file}';

    protected $description = 'Create a service class inside a module';

    public function handle(ModuleGenerator $generator): int
    {
        $module = (string) $this->argument('module');
        $class = $generator->normalizeClass((string) $this->argument('name'), 'Service');

        if (! $generator->moduleExists($module)) {
            $this->error($generator->missingModuleMessage($module));

            return self::FAILURE;
        }

        $path = $generator->modulePath($module, "Services/{$class}.php");
        $created = $generator->writeFile($path, $generator->serviceStub($module, $class), (bool) $this->option('force'));

        $this->line(($created ? '<info>CREATED</info> ' : '<comment>EXISTS</comment>  ').$path);

        return self::SUCCESS;
    }
}

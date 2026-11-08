<?php

namespace App\Console\Commands;

use App\Support\Modules\ModuleGenerator;
use Illuminate\Console\Command;

class ModuleMakeRequestCommand extends Command
{
    protected $signature = 'module:make-request {module : The module name} {name : The request name} {--force : Overwrite existing file}';

    protected $description = 'Create a form request inside a module';

    public function handle(ModuleGenerator $generator): int
    {
        $module = (string) $this->argument('module');
        $class = $generator->normalizeClass((string) $this->argument('name'), 'Request');

        if (! $generator->moduleExists($module)) {
            $this->error($generator->missingModuleMessage($module));

            return self::FAILURE;
        }

        $path = $generator->modulePath($module, "Requests/{$class}.php");
        $created = $generator->writeFile($path, $generator->requestStub($module, $class), (bool) $this->option('force'));

        $this->line(($created ? '<info>CREATED</info> ' : '<comment>EXISTS</comment>  ').$path);

        return self::SUCCESS;
    }
}

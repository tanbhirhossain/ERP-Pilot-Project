<?php

namespace App\Console\Commands;

use App\Support\Modules\ModuleGenerator;
use Illuminate\Console\Command;

class ModuleMakeRepositoryCommand extends Command
{
    protected $signature = 'module:make-repository {module : The module name} {name : The repository name} {--force : Overwrite existing files}';

    protected $description = 'Create a repository and matching interface inside a module';

    public function handle(ModuleGenerator $generator): int
    {
        $module = (string) $this->argument('module');
        $repository = $generator->normalizeClass((string) $this->argument('name'), 'Repository');
        $interface = $repository.'Interface';
        $force = (bool) $this->option('force');

        if (! $generator->moduleExists($module)) {
            $this->error($generator->missingModuleMessage($module));

            return self::FAILURE;
        }

        $files = [
            $generator->modulePath($module, "Interfaces/{$interface}.php") => $generator->repositoryInterfaceStub($module, $interface),
            $generator->modulePath($module, "Repositories/{$repository}.php") => $generator->repositoryStub($module, $repository, $interface),
        ];

        foreach ($files as $path => $contents) {
            $created = $generator->writeFile($path, $contents, $force);
            $this->line(($created ? '<info>CREATED</info> ' : '<comment>EXISTS</comment>  ').$path);
        }

        return self::SUCCESS;
    }
}

<?php

namespace Square1\NovaResourceViews\Console;

use Illuminate\Support\Str;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

class ResourceViewsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'nova:resource-views {name}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new resource views.';

    public function handle()
    {
        $this->hasValidNameArgument();

        (new Filesystem())->copyDirectory(
            __DIR__.'/stubs',
            $this->toolPath()
        );

        // nova/res/posts/edit
        // nova/posts/{{resource-name}}
        // nova/resources/post-resources
        // nova/resources/posts
        // nova/extended/posts

        // Tool.js replacements...
        $this->replace('{{ resource }}', $this->toolName(), $this->toolPath().'/resources/js/tool.stub');
        $this->renameStub($this->toolPath().'/resources/js/tool.stub', '.js');

        // tool.scss replacements
        $this->renameStub($this->toolPath().'/resources/sass/tool.stub', '.scss');

        // Tool *.vue replacements
        $this->replace('{{ resource }}', $this->toolName(), $this->toolPath().'/resources/js/components/Index/ResourceTableRow.stub');
        $this->replace('{{ resource }}', $this->toolName(), $this->toolPath().'/resources/js/components/CreateResourceButton.stub');
        $this->replace('{{ resource }}', $this->toolName(), $this->toolPath().'/resources/js/views/Create.stub');
        $this->replace('{{ resource }}', $this->toolName(), $this->toolPath().'/resources/js/views/Detail.stub');
        $this->replace('{{ resource }}', $this->toolName(), $this->toolPath().'/resources/js/views/Update.stub');
        $this->renameVueStubs($this->toolPath().'/resources/js/components');
        $this->renameVueStubs($this->toolPath().'/resources/js/views');

        // View replacements
        $this->replace('{{ title }}', ucwords($this->toolName()), $this->toolPath().'/resources/views/navigation.blade.stub');
        $this->replace('{{ resource }}', $this->toolName(), $this->toolPath().'/resources/views/navigation.blade.stub');
        $this->renameStub($this->toolPath().'/resources/views/navigation.blade.stub', '.php');

        // Middleware replacements
        $this->replace('{{ namespace }}', $this->toolNamespace(), $this->toolPath().'/src/Http/Middleware/Authorize.stub');
        $this->replace('{{ class }}', $this->toolClass(), $this->toolPath().'/src/Http/Middleware/Authorize.stub');
        $this->renameStub($this->toolPath().'/src/Http/Middleware/Authorize.stub', '.php');

        // Tool class replacements
        $this->replace('{{ namespace }}', $this->toolNamespace(), $this->toolPath().'/src/ToolClass.stub');
        $this->replace('{{ class }}', $this->toolClass(), $this->toolPath().'/src/ToolClass.stub');
        $this->replace('{{ resource }}', $this->toolName(), $this->toolPath().'/src/ToolClass.stub');
        (new Filesystem())->move($this->toolPath().'/src/ToolClass.stub', $this->toolPath().'/src/'.$this->toolClass().'.php');

        // Tool ServiceProvider replacements
        $this->replace('{{ namespace }}', $this->toolNamespace(), $this->toolPath().'/src/ToolServiceProvider.stub');
        $this->replace('{{ resource }}', $this->toolName(), $this->toolPath().'/src/ToolServiceProvider.stub');
        $this->renameStub($this->toolPath().'/src/ToolServiceProvider.stub', '.php');

        // Composer replacements
        $this->replace('{{ name }}', $this->argument('name'), $this->toolPath().'/composer.stub');
        $this->replace('{{ namespace }}', addslashes($this->toolNamespace()), $this->toolPath().'/composer.stub');
        $this->renameStub($this->toolPath().'/composer.stub', '.json');

        // webpack
        $this->renameStub($this->toolPath().'/package.stub', '.json');
        $this->renameStub($this->toolPath().'/webpack.mix.stub', '.js');

        // Routes
        $this->renameStub($this->toolPath().'/routes/api.stub', '.php');

        // git
        $this->renameStub($this->toolPath().'/.gitignore.stub', '');


        // Register the tool...
        $this->addToolRepositoryToRootComposer();
        $this->addToolPackageToRootComposer();
        $this->addScriptsToNpmPackage();

        if ($this->confirm("Would you like to install the tool's NPM dependencies?", true)) {
            $this->installNpmDependencies();

            $this->output->newLine();
        }

        if ($this->confirm("Would you like to compile the tool's assets?", true)) {
            $this->compile();

            $this->output->newLine();
        }

        if ($this->confirm('Would you like to update your Composer packages?', true)) {
            $this->composerUpdate();
        }
    }

    /**
     * Get the tool's vendor.
     *
     * @return string
     */
    protected function toolVendor()
    {
        return explode('/', $this->argument('name'))[0];
    }

    /**
     * Get the tool's namespace.
     *
     * @return string
     */
    protected function toolNamespace()
    {
        return Str::studly($this->toolVendor()).'\\'.$this->toolClass();
    }

    /**
     * Rename stubs in the given file.
     *
     * @param string $path
     *
     * @return mixed
     */
    protected function renameVueStubs(string $path)
    {
        $files = scandir($path);

        foreach ($files as $filePath) {
            if (in_array($filePath, ['.', '..'])) {
                continue;
            }
            $fullPath = $path.'/'.$filePath;
            if (is_dir($fullPath)) {
                $this->renameVueStubs($fullPath);
            }
            $this->renameStub($fullPath, '.vue');
        }
    }

    /**
     * Replace the given string in the given file.
     *
     * @param string $search
     * @param string $replace
     * @param string $path
     *
     * @return void
     */
    protected function replace($search, $replace, $path)
    {
        file_put_contents($path, str_replace($search, $replace, file_get_contents($path)));
    }

    /**
     * Replace the .stub extension for the given file.
     *
     * @param string $path
     * @param string $ext
     *
     * @return mixed
     */
    protected function renameStub(string $path, string $ext)
    {
        return (new Filesystem())->move($path, str_replace('.stub', $ext, $path));
    }

    /**
     * Rename the stubs with PHP file extensions.
     *
     * @return void
     */
    protected function renameStubs()
    {
        foreach ($this->stubsToRename() as $stub) {
            (new Filesystem())->move($stub, str_replace('.stub', '.php', $stub));
        }
    }

    /**
     * Determine if the name argument is valid.
     *
     * @return bool
     */
    public function hasValidNameArgument()
    {
        $name = $this->argument('name');

        if (! Str::contains($name, '/')) {
            $this->error("The name argument expects a vendor and name in 'Composer' format. Here's an example: `vendor/name`.");

            return false;
        }

        return true;
    }

    /**
     * Get the path to the tool.
     *
     * @return string
     */
    protected function toolPath()
    {
        return base_path('nova-components/'.$this->toolClass());
    }

    /**
     * Get the tool's class name.
     *
     * @return string
     */
    protected function toolClass()
    {
        return Str::studly($this->toolName());
    }

    /**
     * Get the tool's base name.
     *
     * @return string
     */
    protected function toolName()
    {
        return explode('/', $this->argument('name'))[1];
    }

    /**
     * Get the relative path to the tool.
     *
     * @return string
     */
    protected function relativeToolPath()
    {
        return 'nova-components/'.$this->toolClass();
    }

    /**
     * Add a path repository for the tool to the application's composer.json file.
     *
     * @return void
     */
    protected function addToolRepositoryToRootComposer()
    {
        $composer = json_decode(file_get_contents(base_path('composer.json')), true);

        $composer['repositories'][] = [
            'type' => 'path',
            'url' => './'.$this->relativeToolPath(),
        ];

        file_put_contents(
            base_path('composer.json'),
            json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * Add a package entry for the tool to the application's composer.json file.
     *
     * @return void
     */
    protected function addToolPackageToRootComposer()
    {
        $composer = json_decode(file_get_contents(base_path('composer.json')), true);

        $composer['require'][$this->argument('name')] = '*';

        file_put_contents(
            base_path('composer.json'),
            json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * Add a path repository for the tool to the application's composer.json file.
     *
     * @return void
     */
    protected function addScriptsToNpmPackage()
    {
        $package = json_decode(file_get_contents(base_path('package.json')), true);

        $package['scripts']['build-'.$this->toolName()] = 'cd '.$this->relativeToolPath().' && npm run dev';
        $package['scripts']['build-'.$this->toolName().'-prod'] = 'cd '.$this->relativeToolPath().' && npm run prod';

        file_put_contents(
            base_path('package.json'),
            json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * Install the tool's NPM dependencies.
     *
     * @return void
     */
    protected function installNpmDependencies()
    {
        $this->executeCommand('npm set progress=false && npm install', $this->toolPath());
    }

    /**
     * Run the given command as a process.
     *
     * @param string $command
     * @param string $path
     *
     * @return void
     */
    protected function executeCommand($command, $path)
    {
        $process = (new Process($command, $path))->setTimeout(null);

        if ('\\' !== DIRECTORY_SEPARATOR && file_exists('/dev/tty') && is_readable('/dev/tty')) {
            $process->setTty(true);
        }

        $process->run(function ($type, $line) {
            $this->output->write($line);
        });
    }

    /**
     * Compile the tool's assets.
     *
     * @return void
     */
    protected function compile()
    {
        $this->executeCommand('npm run dev', $this->toolPath());
    }

    /**
     * Update the project's composer dependencies.
     *
     * @return void
     */
    protected function composerUpdate()
    {
        $this->executeCommand('composer update', getcwd());
    }
}

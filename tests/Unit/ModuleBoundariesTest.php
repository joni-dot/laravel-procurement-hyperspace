<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Modules plug into Core; Core and other modules don't reach into a module's code. That keeps a
 * module removable and stops the modules from tangling into each other.
 */
class ModuleBoundariesTest extends TestCase
{
    /**
     * Core files that may name module classes, and why.
     *
     * @var list<string>
     */
    private const array CORE_EXCEPTIONS = [
        // Where the modules are plugged in.
        'bootstrap/providers.php',
        // Tells the sidebar which SRM admin pages to show, until modules get their own menus.
        'app/Http/Middleware/HandleInertiaRequests.php',
    ];

    public function test_core_does_not_use_module_code(): void
    {
        $offenders = [];

        foreach (['app', 'bootstrap', 'config', 'routes', 'database/migrations', 'database/factories'] as $folder) {
            foreach ($this->phpFiles($folder) as $file) {
                if (str_starts_with($file, 'app/Modules/') || in_array($file, self::CORE_EXCEPTIONS, true)) {
                    continue;
                }

                if (str_contains($this->read($file), 'App\\Modules\\')) {
                    $offenders[] = $file;
                }
            }
        }

        $this->assertSame([], $offenders, 'Core files use module code.');
    }

    public function test_modules_do_not_use_each_others_code(): void
    {
        $modules = array_map('basename', glob($this->root().'/app/Modules/*', GLOB_ONLYDIR) ?: []);
        $offenders = [];

        foreach ($modules as $module) {
            foreach ($this->phpFiles("app/Modules/{$module}") as $file) {
                foreach (array_diff($modules, [$module]) as $other) {
                    if (str_contains($this->read($file), "App\\Modules\\{$other}\\")) {
                        $offenders[] = "{$file} uses {$other}";
                    }
                }
            }
        }

        $this->assertNotEmpty($modules);
        $this->assertSame([], $offenders, 'A module uses another module\'s code.');
    }

    /**
     * @return list<string> paths relative to the project root
     */
    private function phpFiles(string $folder): array
    {
        $path = $this->root().'/'.$folder;

        if (is_file($path)) {
            return [$folder];
        }

        $files = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = substr($file->getPathname(), strlen($this->root()) + 1);
            }
        }

        sort($files);

        return $files;
    }

    private function read(string $file): string
    {
        return (string) file_get_contents($this->root().'/'.$file);
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }
}

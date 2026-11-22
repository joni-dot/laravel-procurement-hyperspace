<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Demo companies are made in production, where Faker and the model factories are not installed (they are
 * development packages). Demo data must therefore come from fixed word lists in plain PHP.
 */
class DemoDataSourceTest extends TestCase
{
    public function test_demo_data_uses_no_faker_and_no_factories()
    {
        $files = [
            'app/Companies/DemoData.php',
            'app/Companies/DemoRandom.php',
            'app/Actions/Companies/CreateDemoCompany.php',
            ...glob($this->root().'/app/Modules/*/Demo/*.php') ?: [],
        ];
        $offenders = [];

        foreach ($files as $file) {
            $path = str_starts_with($file, '/') ? $file : $this->root().'/'.$file;
            $source = (string) file_get_contents($path);

            if (preg_match('/\bfake\(|Faker|::factory\(/', $source) === 1) {
                $offenders[] = substr($path, strlen($this->root()) + 1);
            }
        }

        $this->assertGreaterThanOrEqual(6, count($files), 'The demo fillers were not found.');
        $this->assertSame([], $offenders, 'Demo data uses Faker or factories, which production does not have.');
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }
}

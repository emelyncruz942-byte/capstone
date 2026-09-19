<?php

namespace Tests\Unit;

use Illuminate\View\Compilers\BladeCompiler;
use PhpToken;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

class BladeCompilationTest extends TestCase
{
    public function test_every_portal_template_compiles_to_valid_php(): void
    {
        $compiler = app(BladeCompiler::class);
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views'))) as $file) {
            if (!$file->isFile() || !str_ends_with($file->getPathname(), '.blade.php')) continue;
            try {
                PhpToken::tokenize($compiler->compileString(file_get_contents($file->getPathname())), TOKEN_PARSE);
                $this->addToAssertionCount(1);
            } catch (\ParseError $exception) {
                $this->fail($file->getPathname().': '.$exception->getMessage());
            }
        }
    }
}

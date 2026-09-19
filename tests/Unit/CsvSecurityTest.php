<?php

namespace Tests\Unit;

use App\Http\Controllers\Controller;
use PHPUnit\Framework\TestCase;

class CsvSecurityTest extends TestCase
{
    public function test_user_controlled_spreadsheet_formulas_are_neutralized(): void
    {
        $controller = new class extends Controller
        {
            public function write($stream, array $cells): void
            {
                $this->writeCsvRow($stream, $cells);
            }
        };

        $stream = fopen('php://temp', 'w+');
        $this->assertIsResource($stream);

        $controller->write($stream, [
            '=HYPERLINK("https://attacker.example")',
            '  +1+1',
            '-10',
            '@SUM(A1:A2)',
            'ordinary text',
        ]);

        rewind($stream);
        $row = fgetcsv($stream);
        fclose($stream);

        $this->assertSame([
            "'=HYPERLINK(\"https://attacker.example\")",
            "'  +1+1",
            "'-10",
            "'@SUM(A1:A2)",
            'ordinary text',
        ], $row);
    }
}

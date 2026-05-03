<?php

declare(strict_types=1);

namespace Qoliber\Tsuku\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Qoliber\Tsuku\Tsuku;

class StreamingTest extends TestCase
{
    private Tsuku $tsuku;

    protected function setUp(): void
    {
        $this->tsuku = new Tsuku();
    }

    public function testStreamsHeaderRowsAndFooterToWriter(): void
    {
        $template = <<<'TPL'
<feed currency="{currency}">
@for(products as product)<item>{product.sku}</item>
@end</feed>
TPL;

        $rows = [
            ['sku' => 'A'],
            ['sku' => 'B'],
            ['sku' => 'C'],
        ];

        $captured = '';
        $writer = function (string $chunk) use (&$captured): void {
            $captured .= $chunk;
        };

        $this->tsuku->processToStream(
            $template,
            ['currency' => 'EUR'],
            $rows,
            'products',
            $writer
        );

        $expected = <<<'OUT'
<feed currency="EUR">
<item>A</item>
<item>B</item>
<item>C</item>
</feed>
OUT;

        $this->assertSame($expected, $captured);
    }

    public function testEmptyRowsEmitsHeaderAndFooterOnly(): void
    {
        $template = <<<'TPL'
<feed>
@for(products as product)<item>{product.sku}</item>
@end</feed>
TPL;

        $captured = '';
        $this->tsuku->processToStream(
            $template,
            [],
            [],
            'products',
            function (string $chunk) use (&$captured): void {
                $captured .= $chunk;
            }
        );

        $this->assertSame("<feed>\n</feed>", $captured);
    }

    public function testWriterIsCalledIncrementallyAsRowsAreYielded(): void
    {
        // Proves streaming (not buffering): the writer must receive each row's
        // output before the generator yields the NEXT row. We track the order
        // of yield-vs-write events.
        $template = "@for(items as item){item.n}|@end";
        $events = [];

        $generator = function () use (&$events): \Generator {
            for ($i = 1; $i <= 3; $i++) {
                $events[] = "yield:$i";
                yield ['n' => $i];
            }
        };

        $writer = function (string $chunk) use (&$events): void {
            $events[] = "write:$chunk";
        };

        $this->tsuku->processToStream(
            $template,
            [],
            $generator(),
            'items',
            $writer
        );

        // Each yield must be immediately followed by its write — never two
        // yields in a row (that would mean buffering).
        $this->assertSame(
            ['yield:1', 'write:1|', 'yield:2', 'write:2|', 'yield:3', 'write:3|'],
            $events
        );
    }

    public function testThrowsWhenStreamingVariableForIsMissing(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/top-level @for over "products"/');

        $this->tsuku->processToStream(
            'no products loop here',
            [],
            [],
            'products',
            fn(string $chunk) => null
        );
    }

    public function testThrowsWhenMultipleTopLevelMatchingForLoops(): void
    {
        $template = "@for(products as p){p.sku}@end\n@for(products as q){q.sku}@end";

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/exactly one top-level @for over "products"; found 2/');

        $this->tsuku->processToStream(
            $template,
            [],
            [],
            'products',
            fn(string $chunk) => null
        );
    }

    public function testThrowsWhenStreamingForIsNestedInsideAnotherDirective(): void
    {
        // The matching @for is nested inside an @if, not at the TemplateNode top level.
        $template = "@if(show)@for(products as p){p.sku}@end@end";

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/top-level @for over "products"/');

        $this->tsuku->processToStream(
            $template,
            ['show' => true],
            [['sku' => 'A']],
            'products',
            fn(string $chunk) => null
        );
    }

    public function testHeaderAndFooterCanReadContextData(): void
    {
        $template = "currency: {currency}\n@for(rows as r){r.v}\n@end-end-{currency}";

        $captured = '';
        $this->tsuku->processToStream(
            $template,
            ['currency' => 'EUR'],
            [['v' => 1], ['v' => 2]],
            'rows',
            function (string $chunk) use (&$captured): void {
                $captured .= $chunk;
            }
        );

        $this->assertSame("currency: EUR\n1\n2\n-end-EUR", $captured);
    }

    public function testRowBodyCanReadBothContextDataAndItem(): void
    {
        $template = "@for(rows as row){row.sku}={row.price}{currency}\n@end";

        $captured = '';
        $this->tsuku->processToStream(
            $template,
            ['currency' => 'USD'],
            [
                ['sku' => 'A', 'price' => '10'],
                ['sku' => 'B', 'price' => '20'],
            ],
            'rows',
            function (string $chunk) use (&$captured): void {
                $captured .= $chunk;
            }
        );

        $this->assertSame("A=10USD\nB=20USD\n", $captured);
    }

    public function testKeyVariableIsAvailableInRowBody(): void
    {
        $template = "@for(rows as row, idx){idx}:{row.sku}\n@end";

        $captured = '';
        $this->tsuku->processToStream(
            $template,
            [],
            [['sku' => 'A'], ['sku' => 'B']],
            'rows',
            function (string $chunk) use (&$captured): void {
                $captured .= $chunk;
            }
        );

        $this->assertSame("0:A\n1:B\n", $captured);
    }
}

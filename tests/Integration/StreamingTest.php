<?php

declare(strict_types=1);

namespace Qoliber\Tsuku\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Qoliber\Tsuku\Tsuku;

class StreamingTest extends TestCase
{
    private Tsuku $tsuku;

    protected function setUp(): void
    {
        $this->tsuku = new Tsuku();
    }

    public function testStreamingXmlProducesByteIdenticalOutputToProcess(): void
    {
        $template = '<?xml version="1.0"?>
<catalog>
@for(products as product)
  <product id="{product.id}">
    <name>{product.name}</name>
    <price>{product.price}</price>
@if(product.stock > 0)
    <availability>in-stock</availability>
@end
  </product>
@end
</catalog>';

        $products = [
            ['id' => '1', 'name' => 'Premium Widget', 'price' => '49.99', 'stock' => 25],
            ['id' => '2', 'name' => 'Basic Widget', 'price' => '19.99', 'stock' => 0],
        ];

        $nonStreaming = $this->tsuku->process($template, ['products' => $products]);

        $streamed = '';
        $this->tsuku->processToStream(
            $template,
            [],
            $products,
            'products',
            function (string $chunk) use (&$streamed): void {
                $streamed .= $chunk;
            }
        );

        $this->assertSame($nonStreaming, $streamed);
    }

    public function testStreamingCsvProducesByteIdenticalOutputToProcess(): void
    {
        $template = "sku,name,price\n@for(rows as r){r.sku},{r.name},{r.price}\n@end";

        $rows = [
            ['sku' => 'A1', 'name' => 'Alpha', 'price' => '10.00'],
            ['sku' => 'B2', 'name' => 'Beta', 'price' => '20.00'],
            ['sku' => 'C3', 'name' => 'Gamma', 'price' => '30.00'],
        ];

        $nonStreaming = $this->tsuku->process($template, ['rows' => $rows]);

        $streamed = '';
        $this->tsuku->processToStream(
            $template,
            [],
            $rows,
            'rows',
            function (string $chunk) use (&$streamed): void {
                $streamed .= $chunk;
            }
        );

        $this->assertSame($nonStreaming, $streamed);
    }

    public function testStreamingHandlesLargeRowCountWithoutBuffering(): void
    {
        // Generate 5000 rows via a generator. If the implementation
        // buffered the full output, peak memory growth would scale with row count.
        // We assert by counting writer calls — should be once per row + header + footer.
        $template = "<feed>\n@for(rows as r){r.n}\n@end</feed>";

        $generator = function (): \Generator {
            for ($i = 1; $i <= 5000; $i++) {
                yield ['n' => $i];
            }
        };

        $writeCount = 0;
        $totalBytes = 0;
        $this->tsuku->processToStream(
            $template,
            [],
            $generator(),
            'rows',
            function (string $chunk) use (&$writeCount, &$totalBytes): void {
                $writeCount++;
                $totalBytes += strlen($chunk);
            }
        );

        // Header + 5000 rows + footer = 5002 writer calls
        $this->assertSame(5002, $writeCount);
        // sanity: total output should at least include all row indexes
        $this->assertGreaterThan(5000, $totalBytes);
    }
}

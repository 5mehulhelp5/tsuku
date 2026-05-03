<?php

/**
 * Tsuku - Transform data into any format
 *
 * @package   Qoliber\Tsuku
 * @author    Jakub Winkler <jwinkler@qoliber.com>
 * @copyright 2025 qoliber
 * @license   MIT
 */

declare(strict_types=1);

namespace Qoliber\Tsuku\Streaming;

use Qoliber\Tsuku\Ast\ForNode;
use Qoliber\Tsuku\Ast\Parser;
use Qoliber\Tsuku\Ast\TemplateNode;
use Qoliber\Tsuku\Compiler\Compiler;
use Qoliber\Tsuku\Function\FunctionRegistry;
use Qoliber\Tsuku\Lexer\Lexer;
use Qoliber\Tsuku\ProcessingContext;

/**
 * Streams a template through a writer, materializing only one row at a time.
 *
 * The template must contain exactly one @for over $rowsVariable at the root
 * (TemplateNode top level). Children before that loop are rendered as a header
 * with $contextData, the loop body is rendered once per row from $rows, and
 * children after are rendered as a footer with $contextData.
 */
class StreamingProcessor
{
    /**
     * @param string $template
     * @param array<mixed> $contextData
     * @param iterable<mixed> $rows
     * @param string $rowsVariable
     * @param callable(string): void $writer
     * @param \Qoliber\Tsuku\ProcessingContext $context
     * @param \Qoliber\Tsuku\Function\FunctionRegistry $functionRegistry
     * @return void
     */
    public function process(
        string $template,
        array $contextData,
        iterable $rows,
        string $rowsVariable,
        callable $writer,
        ProcessingContext $context,
        FunctionRegistry $functionRegistry
    ): void {
        $tokens = (new Lexer($template))->tokenize();
        $ast = (new Parser($tokens))->parse();

        [$forIndex, $forNode] = $this->findStreamFor($ast, $rowsVariable);

        $headerChildren = array_slice($ast->children, 0, $forIndex);
        $footerChildren = array_slice($ast->children, $forIndex + 1);

        $this->renderAndWrite($headerChildren, $contextData, $context, $functionRegistry, $writer);

        foreach ($rows as $key => $row) {
            $iterationData = $contextData;
            $iterationData[$forNode->itemVar] = $row;
            if ($forNode->keyVar !== null) {
                $iterationData[$forNode->keyVar] = $key;
            }
            $this->renderAndWrite($forNode->children, $iterationData, $context, $functionRegistry, $writer);
        }

        $this->renderAndWrite($footerChildren, $contextData, $context, $functionRegistry, $writer);
    }

    /**
     * Locate the unique top-level @for over $rowsVariable, returning its index and node.
     *
     * @param \Qoliber\Tsuku\Ast\TemplateNode $ast
     * @param string $rowsVariable
     * @return array{0: int, 1: \Qoliber\Tsuku\Ast\ForNode}
     */
    private function findStreamFor(TemplateNode $ast, string $rowsVariable): array
    {
        /** @var array<int, array{0: int, 1: ForNode}> $matches */
        $matches = [];
        foreach ($ast->children as $i => $child) {
            if ($child instanceof ForNode && $child->collection === $rowsVariable) {
                $matches[] = [$i, $child];
            }
        }

        if (count($matches) === 0) {
            throw new \RuntimeException(
                sprintf('Streaming requires a top-level @for over "%s" in the template.', $rowsVariable)
            );
        }

        if (count($matches) > 1) {
            throw new \RuntimeException(sprintf(
                'Streaming requires exactly one top-level @for over "%s"; found %d.',
                $rowsVariable,
                count($matches)
            ));
        }

        return $matches[0];
    }

    /**
     * @param array<\Qoliber\Tsuku\Ast\Node> $children
     * @param array<mixed> $data
     * @param \Qoliber\Tsuku\ProcessingContext $context
     * @param \Qoliber\Tsuku\Function\FunctionRegistry $functionRegistry
     * @param callable(string): void $writer
     * @return void
     */
    private function renderAndWrite(
        array $children,
        array $data,
        ProcessingContext $context,
        FunctionRegistry $functionRegistry,
        callable $writer
    ): void {
        if ($children === []) {
            return;
        }

        $subTree = new TemplateNode($children);
        $compiler = new Compiler($data, $context, $functionRegistry);
        $rendered = $compiler->compile($subTree);

        if ($rendered !== '') {
            $writer($rendered);
        }
    }
}

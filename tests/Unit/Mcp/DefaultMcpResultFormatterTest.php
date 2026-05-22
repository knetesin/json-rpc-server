<?php

declare(strict_types=1);

namespace JsonRpcServer\Tests\Unit\Mcp;

use JsonRpcServer\Attribute\McpFormat;
use JsonRpcServer\Mcp\DefaultMcpResultFormatter;
use JsonRpcServer\Registry\MethodMetadata;
use PHPUnit\Framework\TestCase;

final class DefaultMcpResultFormatterTest extends TestCase
{
    private DefaultMcpResultFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new DefaultMcpResultFormatter();
    }

    // ---------- Json (default) ----------

    public function testJsonModeWrapsStringsInQuotes(): void
    {
        $this->assertSame('"hello"', $this->format('hello', McpFormat::Json));
    }

    public function testJsonModeEmitsInteger(): void
    {
        $this->assertSame('42', $this->format(42, McpFormat::Json));
    }

    public function testJsonModeEmitsNullLiteral(): void
    {
        $this->assertSame('null', $this->format(null, McpFormat::Json));
    }

    public function testJsonModeEmitsBoolLiteral(): void
    {
        $this->assertSame('true', $this->format(true, McpFormat::Json));
        $this->assertSame('false', $this->format(false, McpFormat::Json));
    }

    public function testJsonModeProducesCompactJsonForObjects(): void
    {
        $text = $this->format(['id' => 1, 'name' => 'Alice'], McpFormat::Json);
        $this->assertSame('{"id":1,"name":"Alice"}', $text);
    }

    public function testJsonModeProducesCompactJsonForLists(): void
    {
        $text = $this->format([['id' => 1], ['id' => 2]], McpFormat::Json);
        $this->assertSame('[{"id":1},{"id":2}]', $text);
    }

    public function testJsonModeKeepsUnicode(): void
    {
        $text = $this->format(['ru' => 'привет'], McpFormat::Json);
        $this->assertStringContainsString('привет', $text);
    }

    // ---------- PrettyJson ----------

    public function testPrettyJsonHasNewlines(): void
    {
        $text = $this->format(['id' => 1, 'name' => 'Alice'], McpFormat::PrettyJson);
        $this->assertStringContainsString("\n", $text);
        $this->assertStringContainsString('"id": 1', $text);
    }

    // ---------- Markdown ----------

    public function testMarkdownEmitsPlainTextForScalars(): void
    {
        $this->assertSame('hello', $this->format('hello', McpFormat::Markdown));
        $this->assertSame('42', $this->format(42, McpFormat::Markdown));
        $this->assertSame('true', $this->format(true, McpFormat::Markdown));
    }

    public function testMarkdownEmitsNoResultMarkerForNull(): void
    {
        $this->assertSame('(no result)', $this->format(null, McpFormat::Markdown));
    }

    public function testMarkdownRendersHomogeneousListAsTable(): void
    {
        $text = $this->format([
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'name' => 'Bob'],
        ], McpFormat::Markdown);

        $this->assertStringContainsString('| id | name |', $text);
        $this->assertStringContainsString('| --- | --- |', $text);
        $this->assertStringContainsString('| 1 | Alice |', $text);
    }

    public function testMarkdownHeterogeneousListFallsBackToJson(): void
    {
        $text = $this->format([
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'email' => 'bob@example.com'],
        ], McpFormat::Markdown);
        $this->assertStringNotContainsString('| --- |', $text);
    }

    public function testMarkdownLargeListFallsBackToJson(): void
    {
        $rows = array_map(static fn ($i) => ['id' => $i, 'name' => "u$i"], range(0, 30));
        $text = $this->format($rows, McpFormat::Markdown);
        $this->assertStringNotContainsString('| --- |', $text);
    }

    public function testMarkdownNestedObjectInListBypassesTable(): void
    {
        $text = $this->format([
            ['id' => 1, 'address' => ['city' => 'NYC']],
        ], McpFormat::Markdown);
        $this->assertStringNotContainsString('| --- |', $text);
    }

    // ---------- Plain ----------

    public function testPlainKeepsStringsUnquoted(): void
    {
        $this->assertSame('hello', $this->format('hello', McpFormat::Plain));
    }

    public function testPlainSerializesObjectAsPrettyJson(): void
    {
        $text = $this->format(['a' => 1], McpFormat::Plain);
        $this->assertStringContainsString("\n", $text);
    }

    // ---------- Default applies when no attribute is set ----------

    public function testDefaultFormatIsCompactJson(): void
    {
        $content = $this->formatter->format(['id' => 1], McpFormat::Json, $this->meta());
        $this->assertSame('{"id":1}', $content[0]['text']);
    }

    private function format(mixed $result, McpFormat $format): string
    {
        return $this->formatter->format($result, $format, $this->meta())[0]['text'];
    }

    private function meta(): MethodMetadata
    {
        return new MethodMetadata(
            name: 'test.example',
            serviceClass: 'X',
            roles: [],
            description: null,
            parameters: [],
            returnType: null,
            isStreaming: false,
            streamFormat: null,
        );
    }
}

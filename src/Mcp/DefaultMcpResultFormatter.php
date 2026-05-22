<?php

declare(strict_types=1);

namespace JsonRpcServer\Mcp;

use JsonRpcServer\Attribute\McpFormat;
use JsonRpcServer\Registry\MethodMetadata;

/**
 * Renders RPC results into MCP content blocks. The format is picked per-method
 * via `#[Rpc\Mcp(format: ...)]`; default is compact JSON, which is the
 * conventional shape for structured tool output in MCP.
 *
 * The output also includes a `structuredContent` companion (a plain object
 * mirror of the result) in the response payload — handled by the controller,
 * not here. This formatter is only responsible for the text rendering.
 */
final class DefaultMcpResultFormatter implements McpResultFormatter
{
    private const int DEFAULT_TABLE_THRESHOLD_ROWS = 25;
    private const int DEFAULT_TABLE_THRESHOLD_COLS = 6;

    private readonly int $tableMaxRows;
    private readonly int $tableMaxCols;
    private readonly int $jsonFlags;

    public function __construct(
        private readonly ToonEncoder $toon = new ToonEncoder(),
        ?int $tableMaxRows = null,
        ?int $tableMaxCols = null,
        ?int $jsonEncodeFlags = null,
    ) {
        $this->tableMaxRows = $tableMaxRows ?? self::DEFAULT_TABLE_THRESHOLD_ROWS;
        $this->tableMaxCols = $tableMaxCols ?? self::DEFAULT_TABLE_THRESHOLD_COLS;
        // JSON_THROW_ON_ERROR is forced regardless — silent false returns are
        // an MCP-client-visible failure we never want to ship.
        $this->jsonFlags = ($jsonEncodeFlags ?? (\JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)) | \JSON_THROW_ON_ERROR;
    }

    public function format(mixed $result, McpFormat $format, MethodMetadata $method): array
    {
        $text = match ($format) {
            McpFormat::Json => $this->json($result, pretty: false),
            McpFormat::PrettyJson => $this->json($result, pretty: true),
            McpFormat::Markdown => $this->markdown($result),
            McpFormat::Plain => $this->plain($result),
            McpFormat::Toon => $this->toon->encode($result),
        };

        return [['type' => 'text', 'text' => $text]];
    }

    private function json(mixed $value, bool $pretty): string
    {
        $flags = $this->jsonFlags;
        if ($pretty) {
            $flags |= \JSON_PRETTY_PRINT;
        }

        // JSON_THROW_ON_ERROR is forced in the constructor, so json_encode()
        // either returns string or throws — never false. The cast tells
        // PHPStan that.
        return (string) json_encode($value, $flags);
    }

    private function plain(mixed $value): string
    {
        if (null === $value) {
            return '(no result)';
        }
        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (\is_string($value)) {
            return $value;
        }
        if (\is_scalar($value)) {
            return (string) $value;
        }

        return $this->json($value, pretty: true);
    }

    private function markdown(mixed $value): string
    {
        if (null === $value) {
            return '(no result)';
        }
        if (\is_scalar($value)) {
            return $this->plain($value);
        }
        if (\is_array($value) && $this->isHomogeneousObjectList($value)) {
            return $this->renderTable($value);
        }

        return $this->json($value, pretty: true);
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private function isHomogeneousObjectList(array $value): bool
    {
        if ([] === $value || !array_is_list($value)) {
            return false;
        }
        if (\count($value) > $this->tableMaxRows) {
            return false;
        }
        $keys = null;
        foreach ($value as $row) {
            if (!\is_array($row) || array_is_list($row)) {
                return false;
            }
            $rowKeys = array_keys($row);
            if (\count($rowKeys) > $this->tableMaxCols) {
                return false;
            }
            $keys ??= $rowKeys;
            if ($rowKeys !== $keys) {
                return false;
            }
            foreach ($row as $cell) {
                if (!\is_scalar($cell) && null !== $cell) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param array<int, array<string, bool|float|int|string|null>> $rows
     */
    private function renderTable(array $rows): string
    {
        $headers = array_keys($rows[0]);
        $lines = [];
        $lines[] = '| '.implode(' | ', $headers).' |';
        $lines[] = '|'.str_repeat(' --- |', \count($headers));
        foreach ($rows as $row) {
            $cells = array_map(
                static fn ($v) => null === $v ? '' : (\is_bool($v) ? ($v ? 'true' : 'false') : (string) $v),
                array_values($row),
            );
            $lines[] = '| '.implode(' | ', $cells).' |';
        }

        return implode("\n", $lines);
    }
}

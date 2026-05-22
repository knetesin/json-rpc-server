<?php

declare(strict_types=1);

namespace JsonRpcServer\Mcp;

/**
 * Encodes a normalized PHP value into TOON — a token-efficient,
 * indentation-based serialization aimed at LLM contexts.
 *
 * The implemented subset:
 *   - scalars: `null`, `true`/`false`, integers and floats as literals, strings
 *     bare when unambiguous, otherwise double-quoted with `\` and `"` escaped;
 *   - objects: `key: value` per line, nested objects indented by two spaces;
 *   - empty arrays: `key[0]:`;
 *   - lists of scalars: inline as `key[N]: a,b,c`;
 *   - lists of flat homogeneous objects: tabular form
 *       `key[N]{field1,field2}:` followed by indented `value1,value2` rows —
 *     the main token win versus JSON;
 *   - any other list (heterogeneous, nested, mixed): block form `key[N]:`
 *     with each item prefixed by `- ` and continuation lines aligned with
 *     the first field.
 *
 * Strings that look like literals (`true`, `42`, `null`) are quoted so they
 * round-trip unambiguously. Keys are quoted only if they contain characters
 * outside `[A-Za-z0-9_-]`.
 */
final class ToonEncoder
{
    private const INDENT = '  ';

    public function encode(mixed $value): string
    {
        $lines = [];
        $this->write($value, '', null, $lines);

        return implode("\n", $lines);
    }

    /**
     * @param list<string> $lines
     */
    private function write(mixed $value, string $indent, ?string $key, array &$lines): void
    {
        if (!\is_array($value)) {
            $scalar = $this->renderScalar($value);
            $lines[] = $indent.(null !== $key ? $this->renderKey($key).': '.$scalar : $scalar);

            return;
        }

        if ([] === $value) {
            $head = '[0]:';
            $lines[] = $indent.(null !== $key ? $this->renderKey($key).$head : $head);

            return;
        }

        if (!array_is_list($value)) {
            if (null !== $key) {
                $lines[] = $indent.$this->renderKey($key).':';
                $childIndent = $indent.self::INDENT;
            } else {
                $childIndent = $indent;
            }
            foreach ($value as $k => $v) {
                $this->write($v, $childIndent, (string) $k, $lines);
            }

            return;
        }

        $count = \count($value);

        if ($this->allScalar($value)) {
            $items = implode(',', array_map($this->renderScalar(...), $value));
            $head = '['.$count.']: '.$items;
            $lines[] = $indent.(null !== $key ? $this->renderKey($key).$head : $head);

            return;
        }

        if ($this->isHomogeneousFlatObjectList($value)) {
            /** @var list<array<string, scalar|null>> $value */
            $fields = array_keys($value[0]);
            $head = '['.$count.']{'.implode(',', array_map($this->renderKey(...), $fields)).'}:';
            $lines[] = $indent.(null !== $key ? $this->renderKey($key).$head : $head);
            $rowIndent = $indent.self::INDENT;
            foreach ($value as $row) {
                $cells = [];
                foreach ($fields as $f) {
                    $cells[] = $this->renderScalar($row[$f]);
                }
                $lines[] = $rowIndent.implode(',', $cells);
            }

            return;
        }

        $head = '['.$count.']:';
        $lines[] = $indent.(null !== $key ? $this->renderKey($key).$head : $head);
        $itemIndent = $indent.self::INDENT;
        foreach ($value as $item) {
            $this->writeListItem($item, $itemIndent, $lines);
        }
    }

    /**
     * @param list<string> $lines
     */
    private function writeListItem(mixed $item, string $indent, array &$lines): void
    {
        if (!\is_array($item)) {
            $lines[] = $indent.'- '.$this->renderScalar($item);

            return;
        }
        if ([] === $item) {
            $lines[] = $indent.'- [0]:';

            return;
        }

        if (array_is_list($item)) {
            $sub = [];
            $this->write($item, $indent.self::INDENT, null, $sub);
            if ([] !== $sub) {
                $sub[0] = $indent.'- '.ltrim($sub[0]);
                foreach ($sub as $line) {
                    $lines[] = $line;
                }
            }

            return;
        }

        $first = true;
        foreach ($item as $k => $v) {
            $prefix = $first ? '- ' : '  ';
            $keyName = $this->renderKey((string) $k);

            if (!\is_array($v)) {
                $lines[] = $indent.$prefix.$keyName.': '.$this->renderScalar($v);
                $first = false;
                continue;
            }

            $sub = [];
            $this->write($v, $indent.self::INDENT, (string) $k, $sub);
            if ([] === $sub) {
                $first = false;
                continue;
            }
            $sub[0] = $indent.$prefix.ltrim($sub[0]);
            foreach ($sub as $line) {
                $lines[] = $line;
            }
            $first = false;
        }
    }

    private function renderScalar(mixed $value): string
    {
        if (null === $value) {
            return 'null';
        }
        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (\is_int($value)) {
            return (string) $value;
        }
        if (\is_float($value)) {
            if (!is_finite($value)) {
                return 'null';
            }
            $repr = (string) $value;

            return str_contains($repr, '.') || str_contains($repr, 'e') || str_contains($repr, 'E')
                ? $repr
                : $repr.'.0';
        }
        if (\is_string($value)) {
            return $this->renderString($value);
        }

        return $this->renderString((string) json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES));
    }

    private function renderString(string $value): string
    {
        if ($this->stringNeedsQuoting($value)) {
            return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
        }

        return $value;
    }

    private function renderKey(string $key): string
    {
        if ('' === $key || 1 === preg_match('/[^A-Za-z0-9_\-]/', $key)) {
            return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $key).'"';
        }

        return $key;
    }

    private function stringNeedsQuoting(string $value): bool
    {
        if ('' === $value) {
            return true;
        }
        if (1 === preg_match('/[,:\n\r\t"\\\\]/', $value)) {
            return true;
        }
        if ($value !== trim($value)) {
            return true;
        }
        if (\in_array(strtolower($value), ['true', 'false', 'null'], true)) {
            return true;
        }
        if (is_numeric($value)) {
            return true;
        }

        return false;
    }

    /**
     * @param array<array-key, mixed> $list
     */
    private function allScalar(array $list): bool
    {
        foreach ($list as $item) {
            if (\is_array($item)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<array-key, mixed> $list
     */
    private function isHomogeneousFlatObjectList(array $list): bool
    {
        if ([] === $list) {
            return false;
        }
        $keys = null;
        foreach ($list as $item) {
            if (!\is_array($item) || array_is_list($item)) {
                return false;
            }
            $itemKeys = array_keys($item);
            if (null === $keys) {
                $keys = $itemKeys;
            } elseif ($itemKeys !== $keys) {
                return false;
            }
            foreach ($item as $v) {
                if (\is_array($v)) {
                    return false;
                }
            }
        }

        return true;
    }
}

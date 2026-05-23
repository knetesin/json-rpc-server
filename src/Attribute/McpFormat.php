<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Attribute;

/**
 * How a method's result should be rendered into the MCP `content[]` text block.
 *
 *  - Json        — compact single-line JSON. The MCP convention for structured
 *                  data. Bundle default unless overridden via `json_rpc_server.mcp.default_format`.
 *  - PrettyJson  — JSON with 4-space indentation and newlines. Bigger, but
 *                  more readable in chat UIs.
 *  - Markdown    — scalars as plain text, list of flat objects as a markdown
 *                  table, anything else as pretty JSON. Most LLM-friendly for
 *                  human-style answers.
 *  - Plain       — scalars unquoted, everything else pretty JSON. Useful for
 *                  text-heavy outputs (translations, summaries, etc.).
 *  - Toon        — token-oriented object notation: indentation-based, scalar
 *                  arrays inline, flat homogeneous object arrays as tabular
 *                  rows. Substantially fewer tokens than JSON for list-of-
 *                  object payloads — pick it when the consumer is an LLM.
 */
enum McpFormat: string
{
    case Json = 'json';
    case PrettyJson = 'pretty_json';
    case Markdown = 'markdown';
    case Plain = 'plain';
    case Toon = 'toon';
}

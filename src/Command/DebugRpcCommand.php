<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Command;

use Knetesin\JsonRpcServerBundle\Exception\MethodNotFoundException;
use Knetesin\JsonRpcServerBundle\Mcp\JsonSchemaBuilder;
use Knetesin\JsonRpcServerBundle\Mcp\McpToolFilter;
use Knetesin\JsonRpcServerBundle\OpenRpc\OpenRpcDocumentBuilder;
use Knetesin\JsonRpcServerBundle\Registry\MethodMetadata;
use Knetesin\JsonRpcServerBundle\Registry\MethodRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Symfony-style introspection for the RPC bundle. Mirrors `debug:router` /
 * `debug:event-dispatcher`: one table of all methods, drill-down on a name.
 *
 *   bin/console debug:rpc                  # table of every registered method
 *   bin/console debug:rpc user.update      # full details for one method
 */
#[AsCommand(
    name: 'debug:rpc',
    description: 'Lists every registered JSON-RPC method (or shows full details for one).',
)]
final class DebugRpcCommand extends Command
{
    public function __construct(
        private readonly MethodRegistry $registry,
        private readonly ?McpToolFilter $mcpFilter = null,
        private readonly ?JsonSchemaBuilder $schemaBuilder = null,
        private readonly ?OpenRpcDocumentBuilder $openRpcBuilder = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('method', InputArgument::OPTIONAL, 'Show details for a specific method name (e.g. "user.update").')
            ->addOption(
                'openrpc',
                null,
                InputOption::VALUE_NONE,
                'Print the registered methods as an OpenRPC 1.3.2 document on stdout (machine-readable, JSON).',
            )
            ->addOption(
                'title',
                null,
                InputOption::VALUE_REQUIRED,
                'OpenRPC info.title (used with --openrpc). Defaults to "JSON-RPC API".',
                'JSON-RPC API',
            )
            ->addOption(
                'api-version',
                null,
                InputOption::VALUE_REQUIRED,
                'OpenRPC info.version (used with --openrpc). Defaults to "1.0.0".',
                '1.0.0',
            )
            ->setHelp(<<<'HELP'
                Examples:

                  <info>%command.full_name%</info>
                    List every method as a compact table.

                  <info>%command.full_name% user.update</info>
                    Full details: parameters, constraints, cache, rate limit, MCP, JSON schema preview.

                  <info>%command.full_name% --openrpc --title="Billing" --api-version=2.4.0 > openrpc.json</info>
                    Emit an OpenRPC document covering every registered method — feed it into a
                    client-SDK generator or doc renderer.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('openrpc')) {
            return $this->emitOpenRpc($io, $output, (string) $input->getOption('title'), (string) $input->getOption('api-version'));
        }

        $name = $input->getArgument('method');

        if (null !== $name) {
            return $this->describe($io, $name);
        }

        return $this->listAll($io);
    }

    private function emitOpenRpc(SymfonyStyle $io, OutputInterface $output, string $title, string $apiVersion): int
    {
        if (null === $this->openRpcBuilder) {
            $io->error('OpenRpcDocumentBuilder is not available — JsonSchemaBuilder may have been removed from the container.');

            return Command::FAILURE;
        }

        $doc = $this->openRpcBuilder->build($title, $apiVersion);
        // Write directly to OutputInterface (bypassing SymfonyStyle wrappers)
        // so `--openrpc > file.json` produces a clean JSON document without
        // any console-style prefixes / blank lines.
        $output->writeln((string) json_encode($doc, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));

        return Command::SUCCESS;
    }

    private function listAll(SymfonyStyle $io): int
    {
        $methods = $this->registry->all();
        if ([] === $methods) {
            $io->warning('No RPC methods registered. Did you forget to tag your handler classes with #[Rpc\\Method]?');

            return Command::SUCCESS;
        }
        ksort($methods);

        $rows = [];
        foreach ($methods as $meta) {
            $rows[] = [
                $meta->name,
                $this->shortClass($meta->serviceClass),
                $this->formatRoles($meta),
                $this->formatCache($meta),
                $this->formatRateLimit($meta),
                $this->formatMcp($meta),
                $meta->isDeprecated() ? '<comment>yes</comment>' : '—',
            ];
        }

        $io->title(\sprintf('RPC methods (%d)', \count($methods)));
        $io->table(
            ['Name', 'Class', 'Roles', 'Cache', 'RateLimit', 'MCP', 'Deprecated'],
            $rows,
        );

        return Command::SUCCESS;
    }

    private function describe(SymfonyStyle $io, string $name): int
    {
        try {
            $meta = $this->registry->get($name);
        } catch (MethodNotFoundException) {
            $io->error(\sprintf('Method "%s" is not registered.', $name));

            return Command::FAILURE;
        }

        $io->title($meta->name);

        $rows = [
            ['Class', $meta->serviceClass],
            ['Description', $meta->description ?? '—'],
            ['Deprecated', $meta->isDeprecated() ? '<comment>'.$meta->deprecated.'</comment>' : '—'],
            ['Return type', $meta->returnType ?? '—'],
            ['Roles', $this->formatRoles($meta)],
            ['Streaming', $meta->isStreaming ? ($meta->streamFormat->value ?? 'yes') : '—'],
            ['Cache', $this->formatCache($meta)],
            ['Rate limit', $this->formatRateLimit($meta)],
            ['MCP exposure', $this->formatMcp($meta)],
            ['Positional DTO', $meta->allowPositionalDto ? 'allowed' : 'rejected'],
            ['Reject unknown', $meta->rejectUnknown ? 'yes' : 'no'],
            ['Max request', $meta->maxRequestSize ? $meta->maxRequestSize.' bytes' : 'default'],
        ];
        $io->table(['Property', 'Value'], $rows);

        if ([] !== $meta->parameters) {
            $paramRows = [];
            foreach ($meta->parameters as $p) {
                $kind = match (true) {
                    $p->isContext => 'Context',
                    $p->isHttpRequest => 'HttpRequest',
                    $p->isRpcRequest => 'RpcRequest',
                    $p->isDto => 'DTO',
                    default => 'scalar',
                };
                $paramRows[] = [
                    '$'.$p->name,
                    $p->type ?? 'mixed',
                    $kind,
                    $p->hasDefault ? var_export($p->default, true) : ($p->allowsNull ? 'null' : 'required'),
                    [] === $p->constraints ? '—' : implode(', ', array_map(
                        static fn (object $c): string => (new \ReflectionClass($c))->getShortName(),
                        $p->constraints,
                    )),
                ];
            }
            $io->section('Parameters');
            $io->table(['Name', 'Type', 'Kind', 'Default', 'Constraints'], $paramRows);
        }

        if (null !== $this->mcpFilter && $this->mcpFilter->isExposed($meta)) {
            $io->section('MCP input schema');
            // Prefer the precomputed schema from MethodCompilerPass — it's
            // what /mcp/tools actually serves. Fall back to a live build only
            // when metadata was created without going through the compiler
            // (e.g. dynamic registration in a test harness).
            $schema = [] !== $meta->inputSchema
                ? $meta->inputSchema
                : $this->schemaBuilder?->fromMethod($meta);
            if (null !== $schema) {
                $io->writeln((string) json_encode($schema, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES));
            }
        }

        return Command::SUCCESS;
    }

    private function shortClass(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return false === $pos ? $fqcn : substr($fqcn, $pos + 1);
    }

    private function formatRoles(MethodMetadata $m): string
    {
        if ([] === $m->roles) {
            return '<fg=gray>public</>';
        }

        return implode(', ', $m->roles).' ['.$m->rolesMatch->value.']';
    }

    private function formatCache(MethodMetadata $m): string
    {
        if (null === $m->cache) {
            return '—';
        }
        $parts = [$m->cache->ttl.'s'];
        if (null !== $m->cache->scope) {
            $parts[] = 'scope='.$this->shortClass($m->cache->scope);
        }
        if (null !== $m->cache->pool) {
            $parts[] = 'pool='.$m->cache->pool;
        }
        if ([] !== $m->cache->tags) {
            $parts[] = 'tags='.implode(',', $m->cache->tags);
        }

        return implode(' ', $parts);
    }

    private function formatRateLimit(MethodMetadata $m): string
    {
        if (null === $m->rateLimit) {
            return '—';
        }

        return \sprintf(
            '%d / %ds (%s, %s)',
            $m->rateLimit->limit,
            $m->rateLimit->intervalSec,
            $m->rateLimit->scope->value,
            $m->rateLimit->policy->value,
        );
    }

    private function formatMcp(MethodMetadata $m): string
    {
        if (null === $this->mcpFilter) {
            return '<fg=gray>disabled</>';
        }

        return $this->mcpFilter->isExposed($m) ? 'yes' : 'no';
    }
}

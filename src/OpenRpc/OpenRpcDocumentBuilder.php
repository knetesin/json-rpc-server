<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\OpenRpc;

use Knetesin\JsonRpcServerBundle\Mcp\JsonSchemaBuilder;
use Knetesin\JsonRpcServerBundle\Registry\MethodMetadata;
use Knetesin\JsonRpcServerBundle\Registry\MethodRegistry;
use Knetesin\JsonRpcServerBundle\Registry\ParameterMetadata;

/**
 * Builds an OpenRPC 1.3.2 document from the bundle's MethodRegistry.
 *
 * The output is a portable, machine-readable contract that any OpenRPC
 * consumer (client-SDK generators, doc renderers, schema validators) can
 * read. Reuses the same JSON Schema fragments the MCP `inputSchema` is
 * built from, so the two stay consistent.
 *
 * Per OpenRPC: each top-level method has its parameters listed individually
 * with name/required/schema. For methods that take a single DTO we surface
 * each constructor property as its own parameter — that's the shape JSON-RPC
 * clients actually send, and it's friendlier to SDK generators than a single
 * opaque "params" object.
 *
 * @see https://spec.open-rpc.org/
 */
final class OpenRpcDocumentBuilder
{
    public function __construct(
        private readonly MethodRegistry $registry,
        private readonly JsonSchemaBuilder $schemaBuilder,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(string $title, string $version, ?string $description = null): array
    {
        $methods = [];
        foreach ($this->registry->all() as $meta) {
            $methods[] = $this->buildMethod($meta);
        }

        $info = ['title' => $title, 'version' => $version];
        if (null !== $description && '' !== $description) {
            $info['description'] = $description;
        }

        return [
            'openrpc' => '1.3.2',
            'info' => $info,
            'methods' => $methods,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildMethod(MethodMetadata $meta): array
    {
        $entry = [
            'name' => $meta->name,
            'params' => $this->buildParams($meta),
            'result' => $this->buildResult($meta),
        ];
        if (null !== $meta->description && '' !== $meta->description) {
            $entry['description'] = $meta->description;
        }
        if ($meta->isDeprecated()) {
            $entry['deprecated'] = true;
            // OpenRPC has no first-class "reason" field — stash the message
            // inside `x-deprecation-reason` so consumers can still surface it.
            $entry['x-deprecation-reason'] = $meta->deprecated;
        }
        if ([] !== $meta->roles) {
            // Custom extension. OpenRPC clients ignore unknown `x-` fields;
            // any auth-aware doc renderer (or our own SDK generator later)
            // can pick this up to gate methods behind roles.
            $entry['x-rpc-roles'] = $meta->roles;
            $entry['x-rpc-roles-match'] = $meta->rolesMatch->value;
        }
        if ($meta->isStreaming) {
            $entry['x-rpc-streaming'] = true;
            if (null !== $meta->streamFormat) {
                $entry['x-rpc-stream-format'] = $meta->streamFormat->value;
            }
        }

        return $entry;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildParams(MethodMetadata $meta): array
    {
        // DTO methods: lift the DTO's properties into top-level params so
        // SDK generators emit a flat call signature instead of "(struct)".
        $dto = $meta->getDtoParameter();
        if (null !== $dto && null !== $dto->type && class_exists($dto->type)) {
            return $this->paramsFromDtoConstructor($dto->type);
        }

        // #[Rpc\Param] methods: each annotated business param becomes one
        // OpenRPC param. Injected ones (Context/HttpRequest/RpcRequest) are
        // server-side and don't belong in the public contract.
        $out = [];
        foreach ($meta->parameters as $p) {
            if ($p->isInjected() || $p->isDto) {
                continue;
            }
            $out[] = $this->paramFromParameter($p);
        }

        return $out;
    }

    /**
     * @param class-string $class
     *
     * @return list<array<string, mixed>>
     */
    private function paramsFromDtoConstructor(string $class): array
    {
        $reflection = new \ReflectionClass($class);
        $ctor = $reflection->getConstructor();
        if (null === $ctor) {
            return [];
        }

        // Re-use the JSON Schema we already build for MCP so the OpenRPC and
        // MCP descriptions of the same method line up byte-for-byte.
        $schema = $this->schemaBuilder->fromClass($class);
        $properties = \is_array($schema['properties'] ?? null)
            ? $schema['properties']
            : (array) ($schema['properties'] ?? []);
        $required = \is_array($schema['required'] ?? null) ? $schema['required'] : [];

        $out = [];
        foreach ($ctor->getParameters() as $p) {
            $name = $p->getName();
            $entry = [
                'name' => $name,
                'required' => \in_array($name, $required, true),
                'schema' => $properties[$name] ?? ['type' => 'object'],
            ];
            $out[] = $entry;
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function paramFromParameter(ParameterMetadata $p): array
    {
        $name = $p->lookupKey();
        $schema = $this->schemaForScalarParam($p);

        return [
            'name' => $name,
            'required' => $p->paramRequired && !$p->hasDefault && !$p->allowsNull,
            'schema' => $schema,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function schemaForScalarParam(ParameterMetadata $p): array
    {
        // For scalar #[Rpc\Param] handlers the precomputed inputSchema already
        // contains the right per-property entry; pull it out directly so
        // OpenRPC and MCP cannot drift apart.
        // Cheap path: re-run JsonSchemaBuilder for the single param via a
        // synthetic call. Avoiding code duplication > saving a few microseconds
        // during a one-shot `debug:rpc --openrpc` run.
        $synthetic = new MethodMetadata(
            name: '_synthetic',
            serviceClass: 'object',
            roles: [],
            description: null,
            parameters: [$p],
            returnType: null,
            isStreaming: false,
            streamFormat: null,
        );
        $schema = $this->schemaBuilder->fromMethod($synthetic);
        $props = \is_array($schema['properties'] ?? null)
            ? $schema['properties']
            : (array) ($schema['properties'] ?? []);
        $entry = $props[$p->lookupKey()] ?? null;

        return \is_array($entry) ? $entry : ['type' => $p->type ?? 'object'];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildResult(MethodMetadata $meta): array
    {
        $schema = match ($meta->returnType) {
            'string' => ['type' => 'string'],
            'int' => ['type' => 'integer'],
            'float' => ['type' => 'number'],
            'bool' => ['type' => 'boolean'],
            'array' => ['type' => 'array'],
            null, 'void', 'mixed' => [],
            default => $this->schemaBuilder->fromClass($meta->returnType),
        };

        return ['name' => $meta->name.'_result', 'schema' => $schema];
    }
}

<?php

declare(strict_types=1);

namespace JsonRpcServer\Tests\Unit\Mcp;

use JsonRpcServer\Mcp\McpToolFilter;
use JsonRpcServer\Registry\MethodMetadata;
use PHPUnit\Framework\TestCase;

final class McpToolFilterTest extends TestCase
{
    public function testDefaultModeRequiresAttribute(): void
    {
        $f = $this->filter();

        $this->assertTrue($f->isExposed($this->meta('foo.bar', hasAttr: true)));
        $this->assertFalse($f->isExposed($this->meta('foo.bar', hasAttr: false)));
    }

    public function testExposeAllExposesEverythingWithoutAttribute(): void
    {
        $f = $this->filter(exposeAll: true);
        $this->assertTrue($f->isExposed($this->meta('anything', hasAttr: false)));
    }

    public function testExcludePrefixesHideMatchingMethodsEvenWithAttribute(): void
    {
        $f = $this->filter(exposeAll: true, excludePrefixes: ['auth.', 'admin.']);

        $this->assertFalse($f->isExposed($this->meta('auth.login', hasAttr: true)));
        $this->assertFalse($f->isExposed($this->meta('admin.purge', hasAttr: true)));
        $this->assertTrue($f->isExposed($this->meta('public.ping', hasAttr: false)));
    }

    public function testExcludeMethodsHidesExactName(): void
    {
        $f = $this->filter(exposeAll: true, excludeMethods: ['user.delete']);

        $this->assertFalse($f->isExposed($this->meta('user.delete', hasAttr: true)));
        $this->assertTrue($f->isExposed($this->meta('user.deleteAll', hasAttr: false)));
        $this->assertTrue($f->isExposed($this->meta('user.create', hasAttr: false)));
    }

    public function testExcludeMethodsOverridesWhitelist(): void
    {
        $f = $this->filter(
            exposeAll: true,
            excludeMethods: ['user.delete'],
            whitelistMethods: ['user.delete'],
        );
        $this->assertFalse($f->isExposed($this->meta('user.delete', hasAttr: true)));
    }

    public function testAttributeEnabledFalseHidesEvenWithExposeAll(): void
    {
        $f = $this->filter(exposeAll: true);

        $this->assertFalse($f->isExposed($this->meta('user.internal', hasAttr: true, enabled: false)));
    }

    public function testAttributeEnabledFalseLosesToWhitelist(): void
    {
        // Sometimes you want a method opted-out by attribute, but enabled in a specific deployment.
        $f = $this->filter(exposeAll: false, whitelistMethods: ['user.internal']);

        $this->assertTrue(
            $f->isExposed($this->meta('user.internal', hasAttr: true, enabled: false)),
            'whitelist beats attribute opt-out — explicit project override wins'
        );
    }

    public function testWhitelistOverridesExcludePrefixes(): void
    {
        $f = $this->filter(
            exposeAll: true,
            excludePrefixes: ['auth.'],
            whitelistMethods: ['auth.getSession'],
        );

        $this->assertTrue($f->isExposed($this->meta('auth.getSession', hasAttr: false)));
        $this->assertFalse($f->isExposed($this->meta('auth.logout', hasAttr: false)));
    }

    public function testWhitelistAlsoBypassesAttributeRequirement(): void
    {
        $f = $this->filter(exposeAll: false, whitelistMethods: ['plain.method']);

        $this->assertTrue($f->isExposed($this->meta('plain.method', hasAttr: false)));
    }

    /**
     * @param list<string> $excludePrefixes
     * @param list<string> $excludeMethods
     * @param list<string> $whitelistMethods
     */
    private function filter(
        bool $exposeAll = false,
        array $excludePrefixes = [],
        array $excludeMethods = [],
        array $whitelistMethods = [],
    ): McpToolFilter {
        return new McpToolFilter($exposeAll, $excludePrefixes, $excludeMethods, $whitelistMethods);
    }

    private function meta(string $name, bool $hasAttr, bool $enabled = true): MethodMetadata
    {
        return new MethodMetadata(
            name: $name,
            serviceClass: 'Some\\Class',
            roles: [],
            description: null,
            parameters: [],
            returnType: null,
            isStreaming: false,
            streamFormat: null,
            hasMcpAttribute: $hasAttr,
            mcpEnabled: $enabled,
            mcpDescription: null,
        );
    }
}

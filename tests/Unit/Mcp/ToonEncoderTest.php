<?php

declare(strict_types=1);

namespace JsonRpcServer\Tests\Unit\Mcp;

use JsonRpcServer\Mcp\ToonEncoder;
use PHPUnit\Framework\TestCase;

final class ToonEncoderTest extends TestCase
{
    private ToonEncoder $encoder;

    protected function setUp(): void
    {
        $this->encoder = new ToonEncoder();
    }

    public function testNullScalar(): void
    {
        $this->assertSame('null', $this->encoder->encode(null));
    }

    public function testBoolScalars(): void
    {
        $this->assertSame('true', $this->encoder->encode(true));
        $this->assertSame('false', $this->encoder->encode(false));
    }

    public function testIntegerScalar(): void
    {
        $this->assertSame('42', $this->encoder->encode(42));
        $this->assertSame('-7', $this->encoder->encode(-7));
    }

    public function testFloatScalarKeepsDecimal(): void
    {
        $this->assertSame('1.5', $this->encoder->encode(1.5));
        // whole-number float still rendered as float so it round-trips
        $this->assertSame('2.0', $this->encoder->encode(2.0));
    }

    public function testStringBareWhenSafe(): void
    {
        $this->assertSame('hello', $this->encoder->encode('hello'));
    }

    public function testStringQuotedWhenAmbiguous(): void
    {
        $this->assertSame('"true"', $this->encoder->encode('true'));
        $this->assertSame('"42"', $this->encoder->encode('42'));
        $this->assertSame('"null"', $this->encoder->encode('null'));
    }

    public function testStringQuotedWithCommaOrColon(): void
    {
        $this->assertSame('"a,b"', $this->encoder->encode('a,b'));
        $this->assertSame('"a: b"', $this->encoder->encode('a: b'));
    }

    public function testStringEscapesQuotesAndBackslashes(): void
    {
        $this->assertSame('"he said \"hi\""', $this->encoder->encode('he said "hi"'));
        $this->assertSame('"a\\\\b"', $this->encoder->encode('a\\b'));
    }

    public function testEmptyArray(): void
    {
        $this->assertSame('[0]:', $this->encoder->encode([]));
    }

    public function testObjectFlat(): void
    {
        $out = $this->encoder->encode(['name' => 'alice', 'age' => 30]);
        $this->assertSame("name: alice\nage: 30", $out);
    }

    public function testObjectNested(): void
    {
        $out = $this->encoder->encode([
            'user' => ['name' => 'alice', 'age' => 30],
            'active' => true,
        ]);
        $this->assertSame("user:\n  name: alice\n  age: 30\nactive: true", $out);
    }

    public function testScalarListInline(): void
    {
        $out = $this->encoder->encode(['tags' => ['a', 'b', 'c']]);
        $this->assertSame('tags[3]: a,b,c', $out);
    }

    public function testTabularListForHomogeneousFlatObjects(): void
    {
        $out = $this->encoder->encode([
            'users' => [
                ['id' => 1, 'name' => 'alice'],
                ['id' => 2, 'name' => 'bob'],
            ],
        ]);
        $this->assertSame("users[2]{id,name}:\n  1,alice\n  2,bob", $out);
    }

    public function testBlockListForHeterogeneousObjects(): void
    {
        $out = $this->encoder->encode([
            'items' => [
                ['name' => 'a', 'qty' => 1],
                ['name' => 'b', 'qty' => 2, 'note' => 'special'],
            ],
        ]);
        // shapes differ → falls back to '- ' prefixed block
        $expected = "items[2]:\n  - name: a\n    qty: 1\n  - name: b\n    qty: 2\n    note: special";
        $this->assertSame($expected, $out);
    }

    public function testTopLevelTabularList(): void
    {
        $out = $this->encoder->encode([
            ['id' => 1, 'name' => 'alice'],
            ['id' => 2, 'name' => 'bob'],
        ]);
        $this->assertSame("[2]{id,name}:\n  1,alice\n  2,bob", $out);
    }

    public function testNestedListsInsideObjects(): void
    {
        $out = $this->encoder->encode([
            'group' => [
                'name' => 'admins',
                'members' => ['alice', 'bob'],
            ],
        ]);
        $this->assertSame("group:\n  name: admins\n  members[2]: alice,bob", $out);
    }

    public function testKeyWithSpecialCharacterIsQuoted(): void
    {
        $out = $this->encoder->encode(['weird key' => 1]);
        $this->assertSame('"weird key": 1', $out);
    }

    public function testCellValueWithCommaIsQuotedInsideTable(): void
    {
        $out = $this->encoder->encode([
            ['id' => 1, 'name' => 'a,b'],
            ['id' => 2, 'name' => 'c'],
        ]);
        $this->assertSame("[2]{id,name}:\n  1,\"a,b\"\n  2,c", $out);
    }

    public function testTokenCountIsLowerThanPrettyJsonForLists(): void
    {
        $data = [
            'rows' => [
                ['id' => 1, 'name' => 'alice', 'email' => 'a@x.test'],
                ['id' => 2, 'name' => 'bob', 'email' => 'b@x.test'],
                ['id' => 3, 'name' => 'carol', 'email' => 'c@x.test'],
            ],
        ];
        $toon = $this->encoder->encode($data);
        $json = json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);

        $this->assertLessThan(\strlen((string) $json), \strlen($toon), 'TOON output must be shorter than pretty JSON for tabular data');
    }
}

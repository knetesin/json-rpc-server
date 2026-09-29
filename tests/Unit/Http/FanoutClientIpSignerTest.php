<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Unit\Http;

use Knetesin\JsonRpcServerBundle\Batch\ParallelBatchExecutor;
use Knetesin\JsonRpcServerBundle\Http\ClientIpResolver;
use Knetesin\JsonRpcServerBundle\Http\FanoutClientIpSigner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class FanoutClientIpSignerTest extends TestCase
{
    private const string BODY = '{"jsonrpc":"2.0","method":"a","id":1}';

    public function testEmptySecretFailsLoudly(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('kernel.secret');

        new FanoutClientIpSigner('');
    }

    public function testValidSignatureYieldsForwardedIp(): void
    {
        $signer = new FanoutClientIpSigner('secret');

        $this->assertSame('203.0.113.7', $signer->verifiedClientIp($this->subCall($signer->sign('203.0.113.7', 1, self::BODY))));
    }

    public function testSignatureIsBoundToIpDepthBodyAndKey(): void
    {
        $signer = new FanoutClientIpSigner('secret');

        $otherIp = $this->subCall($signer->sign('198.51.100.1', 1, self::BODY));
        $otherDepth = $this->subCall($signer->sign('203.0.113.7', 2, self::BODY));
        $otherBody = $this->subCall($signer->sign('203.0.113.7', 1, '{"jsonrpc":"2.0","method":"b","id":1}'));
        $otherKey = $this->subCall((new FanoutClientIpSigner('other'))->sign('203.0.113.7', 1, self::BODY));

        $this->assertNull($signer->verifiedClientIp($otherIp));
        $this->assertNull($signer->verifiedClientIp($otherDepth));
        $this->assertNull($signer->verifiedClientIp($otherBody));
        $this->assertNull($signer->verifiedClientIp($otherKey));
    }

    public function testMissingHeadersOrInvalidIpAreIgnored(): void
    {
        $signer = new FanoutClientIpSigner('secret');

        $noSignature = $this->subCall(null);
        $noDepth = $this->subCall($signer->sign('203.0.113.7', 1, self::BODY));
        $noDepth->headers->remove(ParallelBatchExecutor::DEPTH_HEADER);
        $notAnIp = $this->subCall($signer->sign('not-an-ip', 1, self::BODY), ip: 'not-an-ip');

        $this->assertNull($signer->verifiedClientIp($noSignature));
        $this->assertNull($signer->verifiedClientIp($noDepth));
        $this->assertNull($signer->verifiedClientIp($notAnIp));
    }

    public function testResolverPrefersVerifiedIpAndFallsBackToConnectionIp(): void
    {
        $signer = new FanoutClientIpSigner('secret');

        $signed = new RequestStack();
        $signed->push($this->subCall($signer->sign('203.0.113.7', 1, self::BODY)));
        $forged = new RequestStack();
        $forged->push($this->subCall(str_repeat('a', 64)));

        $this->assertSame('203.0.113.7', (new ClientIpResolver($signed, $signer))->clientIp());
        $this->assertSame('127.0.0.1', (new ClientIpResolver($forged, $signer))->clientIp());
        // Without a signer (parallel batch off) the header is never read.
        $this->assertSame('127.0.0.1', (new ClientIpResolver($signed))->clientIp());
        $this->assertNull((new ClientIpResolver(new RequestStack(), $signer))->clientIp());
    }

    private function subCall(?string $signature, string $ip = '203.0.113.7'): Request
    {
        $request = Request::create('/rpc', 'POST', server: ['REMOTE_ADDR' => '127.0.0.1'], content: self::BODY);
        $request->headers->set(ParallelBatchExecutor::DEPTH_HEADER, '1');
        $request->headers->set(FanoutClientIpSigner::IP_HEADER, $ip);
        if (null !== $signature) {
            $request->headers->set(FanoutClientIpSigner::SIGNATURE_HEADER, $signature);
        }

        return $request;
    }
}

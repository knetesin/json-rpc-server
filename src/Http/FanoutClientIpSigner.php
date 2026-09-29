<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Http;

use Knetesin\JsonRpcServerBundle\Batch\ParallelBatchExecutor;
use Symfony\Component\HttpFoundation\Request as HttpRequest;

/**
 * Authenticates the original client IP that a parallel-batch parent passes
 * to its loopback sub-calls.
 *
 * The signature is an HMAC-SHA256 over the client IP, the fan-out depth
 * header and a SHA-256 of the exact sub-call body, keyed with a key derived
 * from `kernel.secret`. A header pair that does not verify is ignored, so a
 * client cannot choose the IP its calls are rate-limited or cached under.
 */
final class FanoutClientIpSigner
{
    /** Original client IP, as seen by the top-level request. */
    public const string IP_HEADER = 'X-Rpc-Fanout-Client-Ip';

    /** Hex HMAC-SHA256 authenticating {@see self::IP_HEADER}. */
    public const string SIGNATURE_HEADER = 'X-Rpc-Fanout-Signature';

    private readonly string $key;

    public function __construct(#[\SensitiveParameter] string $secret)
    {
        if ('' === $secret) {
            throw new \LogicException('json_rpc_server.parallel_batch requires a non-empty "kernel.secret" (framework.secret) to sign the client IP forwarded to fan-out sub-calls.');
        }

        // Dedicated key so the raw kernel secret is never used for this MAC directly.
        $this->key = hash_hmac('sha256', 'json_rpc_server.parallel_batch.client_ip', $secret, true);
    }

    public function sign(string $clientIp, int $depth, string $body): string
    {
        return hash_hmac('sha256', $this->payload($clientIp, (string) $depth, $body), $this->key);
    }

    /**
     * Returns the forwarded client IP when the request carries a valid
     * signature for its own depth header and body, null otherwise.
     */
    public function verifiedClientIp(HttpRequest $request): ?string
    {
        $ip = $request->headers->get(self::IP_HEADER);
        $signature = $request->headers->get(self::SIGNATURE_HEADER);
        $depth = $request->headers->get(ParallelBatchExecutor::DEPTH_HEADER);
        if (!\is_string($ip) || !\is_string($signature) || !\is_string($depth) || !ctype_digit($depth)) {
            return null;
        }
        if (false === filter_var($ip, \FILTER_VALIDATE_IP)) {
            return null;
        }

        $expected = hash_hmac('sha256', $this->payload($ip, $depth, $request->getContent()), $this->key);

        return hash_equals($expected, $signature) ? $ip : null;
    }

    private function payload(string $clientIp, string $depth, string $body): string
    {
        return 'v1|'.$clientIp.'|'.$depth.'|'.hash('sha256', $body);
    }
}

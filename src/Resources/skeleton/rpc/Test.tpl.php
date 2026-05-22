<?= "<?php\n" ?>

declare(strict_types=1);

namespace <?= $namespace ?>;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;

final class <?= $class_name ?> extends KernelTestCase
{
    public function testHandlesValidRequest(): void
    {
        $kernel = self::bootKernel();

        $request = Request::create(
            '/rpc',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'jsonrpc' => '2.0',
                'method' => '<?= $method_name ?>',
                'params' => [
                    // TODO: fill in valid params for your DTO.
                ],
                'id' => 1,
            ], \JSON_THROW_ON_ERROR),
        );

        $response = $kernel->handle($request);
        $this->assertSame(200, $response->getStatusCode());

        $payload = json_decode((string) $response->getContent(), true, 32, \JSON_THROW_ON_ERROR);
        $this->assertSame(1, $payload['id']);
        $this->assertArrayHasKey('result', $payload);
        // TODO: assert on $payload['result'] shape.
    }
}

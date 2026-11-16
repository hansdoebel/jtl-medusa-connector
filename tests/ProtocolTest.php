<?php
declare(strict_types=1);

namespace JtlMedusa\Tests;

use PHPUnit\Framework\TestCase;

final class ProtocolTest extends TestCase
{
    public function testOfficialJtlProtocolAuthenticatesAndResolvesControllers(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertIsResource($socket);
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $root = dirname(__DIR__);
        $log = tempnam(sys_get_temp_dir(), 'jtl-server-');
        $process = proc_open([PHP_BINARY, '-S', $address, '-t', $root . '/public'], [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $root, array_replace(getenv(), [
            'JTL_TOKEN' => str_repeat('t', 32),
            'MEDUSA_ADMIN_URL' => 'https://medusa.example.com',
            'MEDUSA_ADMIN_API_KEY' => 'test-only-key',
            'MEDUSA_SHIPPING_PROFILE_ID' => 'sp_test',
            'SHOP_LANGUAGE' => 'de',
            'SHOP_CURRENCY' => 'eur',
        ]));
        self::assertIsResource($process);
        try {
            $ready = false;
            for ($attempt = 0; $attempt < 50; $attempt++) {
                $probe = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
                if (is_resource($probe)) { fclose($probe); $ready = true; break; }
                usleep(100000);
            }
            self::assertTrue($ready, 'PHP test server did not start.');
            $rpc = static function (string $method, array $params = [], ?string $session = null) use ($address): array {
                $form = ['jtlrpc' => json_encode(['jtlrpc' => '2.0', 'id' => '1', 'method' => $method, 'params' => $params], JSON_THROW_ON_ERROR)];
                if ($session !== null) { $form['jtlauth'] = $session; }
                $context = stream_context_create(['http' => ['method' => 'POST', 'header' => 'Content-Type: application/x-www-form-urlencoded', 'content' => http_build_query($form), 'ignore_errors' => true, 'timeout' => 5]]);
                $body = file_get_contents('http://' . $address . '/', false, $context);
                return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            };
            $unauthenticated = $rpc('core.connector.features');
            self::assertNotEmpty($unauthenticated['error']);
            $invalid = $rpc('core.connector.auth', ['token' => 'incorrect']);
            self::assertNotEmpty($invalid['error']);
            $auth = $rpc('core.connector.auth', ['token' => str_repeat('t', 32)]);
            self::assertEmpty($auth['error'] ?? null, json_encode($auth));
            $session = $auth['result']['sessionId'] ?? null;
            self::assertIsString($session);
            self::assertNotSame('', $session);
            $features = $rpc('core.connector.features', [], $session);
            self::assertEmpty($features['error'] ?? null, json_encode($features));
            self::assertNotEmpty($features['result']);
            $global = $rpc('global_data.pull', [], $session);
            self::assertEmpty($global['error'] ?? null, json_encode($global));
            self::assertSame('EUR', $global['result'][0]['currencies'][0]['iso']);
            // Exercise actual controller resolution and variadic deserialization without a remote write.
            $category = $rpc('category.push', [], $session);
            self::assertEmpty($category['error'] ?? null, json_encode($category));
            $product = $rpc('product.push', [], $session);
            self::assertEmpty($product['error'] ?? null, json_encode($product));
        } finally {
            proc_terminate($process);
            fclose($pipes[0]);
            proc_close($process);
            unlink($log);
        }
    }
}

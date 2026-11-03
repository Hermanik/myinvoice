<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Databox;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Repository\DataboxRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Databox\DataboxException;
use MyInvoice\Service\Databox\DataboxService;
use MyInvoice\Service\Databox\GuzzleIsdsTransport;
use MyInvoice\Service\Databox\IsdsClient;
use MyInvoice\Service\Databox\IsdsCredentials;
use MyInvoice\Service\Databox\IsdsException;
use MyInvoice\Service\Databox\IsdsTransport;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;

/**
 * An uncaught exception or a logged trace prints the arguments of every frame (zend.exception_ignore_args=0 in
 * development). A password must never be one of them: every parameter that can carry one is #[\SensitiveParameter].
 */
final class SensitiveParametersTest extends TestCase
{
    private const SECRET = 'S3cret-pass!-0123456789';

    private string|false $ignoreArgs;

    protected function setUp(): void
    {
        $this->ignoreArgs = ini_set('zend.exception_ignore_args', '0');
    }

    protected function tearDown(): void
    {
        if ($this->ignoreArgs !== false) {
            ini_set('zend.exception_ignore_args', $this->ignoreArgs);
        }
    }

    /**
     * What a log handler prints of the exception chain: the messages and the arguments of every frame down to the test
     * method (objects as their class name only, which is how a trace renders them).
     */
    private static function dump(\Throwable $e): string
    {
        $render = static function (mixed $v) use (&$render): string {
            return match (true) {
                is_object($v) => $v::class,
                is_array($v) => '[' . implode(', ', array_map($render, $v)) . ']',
                default => var_export($v, true),
            };
        };
        $out = '';
        for ($t = $e; $t !== null; $t = $t->getPrevious()) {
            $out .= $t->getMessage() . "\n";
            foreach ($t->getTrace() as $frame) {
                $out .= ($frame['class'] ?? '') . '::' . $frame['function'] . '(' . implode(', ', array_map($render, $frame['args'] ?? [])) . ")\n";
                if (str_starts_with($frame['function'], 'test')) {
                    break;
                }
            }
        }
        return $out;
    }

    private function secrets(): SecretEncryption
    {
        return new SecretEncryption(new Config(['app' => ['pepper' => 'test-pepper']]));
    }

    public function testASecretNeverReachesTheTraceOfAFailedDecryption(): void
    {
        try {
            $this->secrets()->decrypt('enc:v1:' . base64_encode(self::SECRET));
            self::fail('expected the ciphertext to be refused');
        } catch (\RuntimeException $e) {
            $dump = self::dump($e);
            self::assertStringNotContainsString(self::SECRET, $dump);
            self::assertStringNotContainsString(base64_encode(self::SECRET), $dump);
            self::assertStringContainsString('SensitiveParameterValue', $dump, 'precondition: the frame is in the trace, redacted');
        }
    }

    public function testThePasswordIsNotInTheTraceWhenALoginIsRefusedForItsLength(): void
    {
        $service = new DataboxService($this->createStub(DataboxRepository::class), $this->secrets(), new IsdsClient($this->createStub(IsdsTransport::class)), new NullLogger());
        $login = ['username' => 'user', 'password' => str_repeat(self::SECRET, 8)];
        try {
            \Closure::bind(fn () => $this->requireLogin($login, 'Přihlášení'), $service, DataboxService::class)();
            self::fail('expected DataboxException');
        } catch (DataboxException $e) {
            self::assertStringNotContainsString(self::SECRET, self::dump($e));
        }
    }

    public function testTheBasicAuthPairOfAFailedRequestDoesNotSurviveInTheChainedException(): void
    {
        // A client that fails the way Guzzle does: its own frame (request options with the auth pair) is in the trace.
        $client = new class implements ClientInterface {
            public function send(\Psr\Http\Message\RequestInterface $request, array $options = []): ResponseInterface { throw new \LogicException('unused'); }
            public function sendAsync(\Psr\Http\Message\RequestInterface $request, array $options = []): \GuzzleHttp\Promise\PromiseInterface { throw new \LogicException('unused'); }
            public function request(string $method, $uri, array $options = []): ResponseInterface { throw new ConnectException('cURL error 28: timed out', new Request($method, $uri)); }
            public function requestAsync(string $method, $uri, array $options = []): \GuzzleHttp\Promise\PromiseInterface { throw new \LogicException('unused'); }
            public function getConfig(?string $option = null) { return null; }
        };
        $transport = new GuzzleIsdsTransport($client);
        try {
            $transport->post('https://ws1.datovka.gov.cz/DS/dx', new IsdsCredentials('user', self::SECRET), '<soap/>');
            self::fail('expected IsdsException');
        } catch (IsdsException $e) {
            self::assertSame('network', $e->reason);
            self::assertInstanceOf(\RuntimeException::class, $e->getPrevious());
            self::assertStringContainsString('timed out', $e->getPrevious()->getMessage(), 'the cause is kept for diagnosis');
            self::assertStringNotContainsString(self::SECRET, self::dump($e));
        }
    }

    /** @return list<class-string> */
    private static function credentialCarryingClasses(): array
    {
        $classes = [SecretEncryption::class];
        $roots = [
            dirname(__DIR__, 3) . '/../src/Service/Databox' => 'MyInvoice\\Service\\Databox\\',
            dirname(__DIR__, 3) . '/../src/Action/Databox' => 'MyInvoice\\Action\\Databox\\',
        ];
        foreach ($roots as $dir => $namespace) {
            foreach (glob($dir . '/*.php') ?: [] as $file) {
                $classes[] = $namespace . basename($file, '.php');
            }
        }
        $classes[] = DataboxRepository::class;
        return array_values(array_filter($classes, static fn (string $c): bool => class_exists($c) || interface_exists($c)));
    }

    public function testEveryParameterThatCanCarryAPasswordIsMarkedSensitive(): void
    {
        $checked = [];
        foreach (self::credentialCarryingClasses() as $class) {
            foreach ((new \ReflectionClass($class))->getMethods() as $method) {
                foreach ($method->getParameters() as $parameter) {
                    $type = $parameter->getType();
                    $isValue = $type === null || ($type instanceof \ReflectionNamedType && ($type->isBuiltin()));
                    if (!$isValue || preg_match('/pass|secret|plaintext|cipher|login$/i', $parameter->getName()) !== 1) {
                        continue;
                    }
                    $name = "{$method->class}::{$method->getName()}(\${$parameter->getName()})";
                    $checked[] = $name;
                    self::assertNotSame([], $parameter->getAttributes(\SensitiveParameter::class), "{$name} can carry a password but is not #[\\SensitiveParameter]");
                }
            }
        }
        // The ones that exist today (and DataboxService::add(), whose array parameter does not match the name scan).
        foreach ([
            [SecretEncryption::class, 'encrypt', 'plaintext'],
            [SecretEncryption::class, 'decrypt', 'stored'],
            [DataboxService::class, 'add', 'input'],
            [DataboxService::class, 'setCredential', 'password'],
            [DataboxService::class, 'requireLogin', 'login'],
            [DataboxRepository::class, 'saveCredential', 'passwordEnc'],
            [IsdsCredentials::class, '__construct', 'password'],
        ] as [$class, $method, $name]) {
            $parameter = array_values(array_filter((new \ReflectionMethod($class, $method))->getParameters(), static fn (\ReflectionParameter $p): bool => $p->getName() === $name))[0] ?? null;
            self::assertNotNull($parameter, "{$class}::{$method}(\${$name})");
            self::assertNotSame([], $parameter->getAttributes(\SensitiveParameter::class), "{$class}::{$method}(\${$name})");
        }
        self::assertGreaterThanOrEqual(4, count($checked), 'the name scan found the credential parameters');
    }
}

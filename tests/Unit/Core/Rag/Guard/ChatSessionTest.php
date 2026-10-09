<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Rag\Guard;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use TAW\Core\Rag\Guard\ChatSession;
use TAW\Tests\TestCase;

final class ChatSessionTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $transients = [];

    private string $salt = 'test-salt';

    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('wp_json_encode')->alias(static fn ($v) => json_encode($v));
        Functions\when('wp_salt')->alias(fn (): string => $this->salt);
        Functions\when('get_transient')->alias(fn (string $key): mixed => $this->transients[$key] ?? false);
        Functions\when('set_transient')->alias(function (string $key, mixed $value): bool {
            $this->transients[$key] = $value;
            return true;
        });
    }

    public function test_an_issued_token_is_accepted(): void
    {
        $session = ChatSession::issue();

        $this->assertSame(ChatSession::TTL_SECONDS, $session['expires_in']);
        $this->assertSame(ChatSession::OK, ChatSession::consume($session['token']));
    }

    public function test_each_message_spends_one_until_the_cap(): void
    {
        $token = ChatSession::issue()['token'];

        for ($i = 0; $i < ChatSession::MAX_MESSAGES; $i++) {
            $this->assertSame(ChatSession::OK, ChatSession::consume($token));
        }

        $this->assertSame(ChatSession::EXHAUSTED, ChatSession::consume($token));
    }

    public function test_a_tampered_payload_is_rejected(): void
    {
        [$payload, $signature] = explode('.', ChatSession::issue()['token']);
        $claims = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
        $claims['exp'] += 86400;
        $forged = rtrim(strtr(base64_encode((string) json_encode($claims)), '+/', '-_'), '=');

        $this->assertSame(ChatSession::INVALID, ChatSession::consume($forged . '.' . $signature));
    }

    public function test_a_token_signed_with_other_salts_is_rejected(): void
    {
        $token = ChatSession::issue()['token'];
        $this->salt = 'another-site';

        $this->assertSame(ChatSession::INVALID, ChatSession::consume($token));
    }

    public function test_an_expired_token_is_reported_as_expired(): void
    {
        $payload = rtrim(strtr(base64_encode((string) json_encode([
            'sid' => str_repeat('a', 32),
            'exp' => time() - 1,
        ])), '+/', '-_'), '=');
        $signature = rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, $this->salt, true)), '+/', '-_'), '=');

        $this->assertSame(ChatSession::EXPIRED, ChatSession::consume($payload . '.' . $signature));
    }

    #[DataProvider('malformedTokens')]
    public function test_malformed_tokens_are_invalid(string $token): void
    {
        $this->assertSame(ChatSession::INVALID, ChatSession::consume($token));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedTokens(): array
    {
        return [
            'empty' => [''],
            'no separator' => ['abc'],
            'too many parts' => ['a.b.c'],
            'empty signature' => ['abc.'],
            'garbage' => ['!!!.???'],
        ];
    }
}

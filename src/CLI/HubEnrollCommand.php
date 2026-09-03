<?php

declare(strict_types=1);

namespace TAW\CLI;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Enrol this site into a TAW Hub fleet in one shot — the automated
 * counterpart to the manual "register the site's key with the Hub" step
 * {@see HubInstallCommand} and the `hub-connect` skill leave to the operator.
 *
 * It reads the values the `taw-hub-companion` plugin generated on activation
 * (`taw_hub_companion_public_key` / `_key_id`), plus the Hub URL and a
 * one-time enrolment token from `wp-config.php`, and `POST`s them to the
 * Hub's frozen enrolment endpoint:
 *
 *   POST {TAW_HUB_URL}/api/fleet/enroll
 *   { "name", "base_url", "site_public_key", "site_key_id", "enrolment_token" }
 *
 * The endpoint is deliberately NOT signature-guarded (the Hub does not know
 * this site yet), so the *token* is the credential — single-use, 30-minute
 * TTL, hashed at rest on the Hub. Every response, however, IS RESPONSE-signed
 * with the Hub's Ed25519 identity (taw-hub ADR-0003 / ADR-0011), and this
 * command verifies that signature against `TAW_HUB_PUBLIC_KEY` before trusting
 * any reply — including a `409 site_already_enrolled`, which a *signed* reply
 * lets us treat as idempotent success (a dropped connection after the Hub
 * consumed the token).
 *
 *   php bin/taw hub:enroll                       # everything from wp-config + companion options
 *   php bin/taw hub:enroll --token=enrol_…       # token on the CLI instead of TAW_HUB_ENROLMENT_TOKEN
 *   php bin/taw hub:enroll --base-url=https://…  # the Hub-reachable URL (home_url() is often wrong behind a proxy / Herd)
 *   php bin/taw hub:enroll --dry-run             # print the request, send nothing
 *
 * Contract reference: taw-hub `docs/ADR/0011-site-enrolment.md`, golden vector
 * `response_enrol_ed25519` in `docs/reference/hub-signing-vectors.json`.
 */
class HubEnrollCommand extends Command
{
    /** ADR-0003 canonical-string scheme prefix. */
    private const SCHEME = 'TAW-HUB-v1';

    /** The bare request path the Hub signs its enrolment responses over (no query, no host). */
    private const SIGNED_PATH = '/api/fleet/enroll';

    /** ADR-0003 max clock drift between the Hub's response timestamp and now. */
    private const MAX_DRIFT_SECONDS = 60;

    private string $themeDir;

    public function __construct(string $themeDir)
    {
        parent::__construct();
        $this->themeDir = $themeDir;
    }

    protected function configure(): void
    {
        $this
            ->setName('hub:enroll')
            ->setDescription('Enrol this site into a TAW Hub fleet (POST /api/fleet/enroll, verifies the signed reply)')
            ->setHelp(<<<'HELP'
                Registers this site with a TAW Hub in one step, using the key the
                taw-hub-companion plugin generated on activation and a one-time
                enrolment token minted on the Hub.

                Required (from wp-config.php unless overridden):
                  TAW_HUB_URL              the Hub's base URL
                  TAW_HUB_PUBLIC_KEY       the Hub's base64 Ed25519 key (to verify the reply)
                  TAW_HUB_ENROLMENT_TOKEN  a fresh `enrol_…` token  (or pass --token)

                The companion plugin must be installed and active — this command
                reads `taw_hub_companion_public_key` / `_key_id` from the options table.

                Examples:
                  <info>php bin/taw hub:enroll</info>
                  <info>php bin/taw hub:enroll --token=enrol_xxxxxxxx --base-url=https://site.example</info>
                  <info>php bin/taw hub:enroll --dry-run --json</info>
                HELP)
            ->addOption('hub-url', null, InputOption::VALUE_REQUIRED, 'Hub base URL (default: the TAW_HUB_URL constant)')
            ->addOption('base-url', null, InputOption::VALUE_REQUIRED, 'The Hub-reachable URL for this site (default: home_url())')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Display name to register (default: the site title)')
            ->addOption('token', null, InputOption::VALUE_REQUIRED, 'Enrolment token (default: the TAW_HUB_ENROLMENT_TOKEN constant)')
            ->addOption('insecure', null, InputOption::VALUE_NONE, 'Skip TLS verification on the enrol request (local self-signed Hubs only)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the request that would be sent, then stop')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit the result as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $asJson = (bool) $input->getOption('json');

        $wpLoad = WpLoader::locate($this->themeDir);
        if ($wpLoad === null) {
            $io->error('Could not locate wp-load.php by walking up from the theme directory. Is this theme installed inside a WordPress site (wp-content/themes/<theme>)?');

            return Command::FAILURE;
        }

        if (!defined('WP_USE_THEMES')) {
            define('WP_USE_THEMES', false);
        }
        WpLoader::autoConfigureLocalSocket($this->themeDir);
        require $wpLoad;

        // --- Gather everything the enrol call needs -------------------------

        $hubUrl = self::stringOption($input, 'hub-url') ?? self::constantString('TAW_HUB_URL');
        if ($hubUrl === null) {
            $io->error('No Hub URL. Define TAW_HUB_URL in wp-config.php or pass --hub-url.');

            return Command::FAILURE;
        }

        $hubPublicKeyB64 = self::constantString('TAW_HUB_PUBLIC_KEY');
        if ($hubPublicKeyB64 === null) {
            $io->error('TAW_HUB_PUBLIC_KEY is not defined in wp-config.php — required to verify the Hub\'s signed reply. Run the hub-connect skill / add the constant first.');

            return Command::FAILURE;
        }
        $hubPublicKeyRaw = self::decodeEd25519Key($hubPublicKeyB64);
        if ($hubPublicKeyRaw === null) {
            $io->error('TAW_HUB_PUBLIC_KEY is not a valid base64 Ed25519 public key (must decode to 32 bytes).');

            return Command::FAILURE;
        }

        $hubKeyId = self::constantString('TAW_HUB_KEY_ID') ?? 'hub-local';

        $token = self::stringOption($input, 'token') ?? self::constantString('TAW_HUB_ENROLMENT_TOKEN');
        if ($token === null) {
            $io->error('No enrolment token. Mint one on the Hub (fleet:enrol-token / the Fleet UI), then pass --token or define TAW_HUB_ENROLMENT_TOKEN.');

            return Command::FAILURE;
        }

        $sitePublicKey = self::optionString('taw_hub_companion_public_key');
        $siteKeyId     = self::optionString('taw_hub_companion_key_id');
        if ($sitePublicKey === null || $siteKeyId === null) {
            $io->error('This site has no companion identity (taw_hub_companion_public_key / _key_id). Install and activate taw-hub-companion first: php bin/taw hub:install --activate');

            return Command::FAILURE;
        }

        $name    = self::stringOption($input, 'name') ?? self::nonEmpty((string) get_bloginfo('name')) ?? 'TAW site';
        $baseUrl = self::stringOption($input, 'base-url') ?? (string) home_url();

        $endpoint = rtrim($hubUrl, '/') . self::SIGNED_PATH;
        $body     = self::buildRequestBody([
            'name'            => $name,
            'base_url'        => $baseUrl,
            'site_public_key' => $sitePublicKey,
            'site_key_id'     => $siteKeyId,
            'enrolment_token' => $token,
        ]);

        // --- Dry run -------------------------------------------------------

        if ($input->getOption('dry-run')) {
            $preview = self::redactToken($body);
            if ($asJson) {
                $output->writeln((string) json_encode(
                    ['dry_run' => true, 'method' => 'POST', 'endpoint' => $endpoint, 'body' => json_decode($preview, true)],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
                ));

                return Command::SUCCESS;
            }
            $io->section('Would POST');
            $io->writeln("  <info>POST</info> {$endpoint}");
            $io->writeln('  ' . str_replace("\n", "\n  ", (string) json_encode(json_decode($preview), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)));
            $io->note('Nothing was sent (--dry-run). The token is redacted above; the real request sends it in full.');

            return Command::SUCCESS;
        }

        // --- Send --------------------------------------------------------

        $response = wp_remote_post($endpoint, [
            'timeout'     => 30,
            'redirection' => 0,
            'headers'     => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ],
            'body'        => $body,
            'sslverify'   => !$input->getOption('insecure'),
        ]);

        if (is_wp_error($response)) {
            $io->error("Could not reach the Hub at {$endpoint}: " . $response->get_error_message());

            return Command::FAILURE;
        }

        $status  = (int) wp_remote_retrieve_response_code($response);
        $rawBody = (string) wp_remote_retrieve_body($response);
        $header  = self::headerLookup(wp_remote_retrieve_headers($response));

        // --- 429 is the one unsigned reply (ADR-0011) --------------------

        if ($status === 429) {
            $io->error('The Hub rate-limited this request (HTTP 429). Wait a minute and retry — the token is still valid.');

            return Command::FAILURE;
        }

        // --- Verify the Hub's signature before trusting anything ---------

        try {
            self::verifyResponseSignature($rawBody, $header, $hubPublicKeyRaw, $hubKeyId, time());
        } catch (\RuntimeException $e) {
            $io->error(implode("\n", [
                "The Hub's reply failed signature verification: {$e->getMessage()}",
                'Not trusting this response. Possible causes: a MITM, the wrong TAW_HUB_PUBLIC_KEY / TAW_HUB_KEY_ID,',
                'or a Hub that is not signing enrolment responses. Enrolment NOT confirmed.',
            ]));

            return Command::FAILURE;
        }

        /** @var array<string, mixed> $data */
        $data = is_array($decoded = json_decode($rawBody, true)) ? $decoded : [];

        return $this->report($io, $asJson, $output, $status, $data);
    }

    /**
     * Map a verified Hub reply to console output + an exit code.
     *
     * @param array<string, mixed> $data
     */
    private function report(SymfonyStyle $io, bool $asJson, OutputInterface $output, int $status, array $data): int
    {
        $emit = static function (array $payload) use ($asJson, $output, $io): void {
            if ($asJson) {
                $output->writeln((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                return;
            }
            unset($payload['ok']);
            $io->definitionList(...array_map(
                static fn ($k, $v): array => [(string) $k => is_scalar($v) ? (string) $v : json_encode($v)],
                array_keys($payload),
                array_values($payload),
            ));
        };

        switch ($status) {
            case 201:
                $io->success('Enrolled with the Hub (signature verified).');
                $emit([
                    'ok'         => true,
                    'result'     => 'enrolled',
                    'site_id'    => $data['site_id']     ?? null,
                    'key_id'     => $data['key_id']      ?? null,
                    'hub_key_id' => $data['hub_key_id']  ?? null,
                    'status'     => $data['status']      ?? null,
                ]);
                $io->note('The site is registered as "pending". The Hub grants no trust until its first signed GET /health against this site succeeds.');

                return Command::SUCCESS;

            case 409:
                // A *signed* 409 means the Hub already has this site (dup base_url
                // or key_id). ADR-0011: treat as idempotent success — the token
                // stays redeemable, so a genuine mistake is still recoverable.
                $io->success('Already enrolled — the Hub already knows this site (signed 409, treated as success).');
                $emit([
                    'ok'      => true,
                    'result'  => 'already_enrolled',
                    'site_id' => $data['site_id'] ?? null,
                ]);

                return Command::SUCCESS;

            case 401:
                $reason = is_string($data['reason'] ?? null) ? $data['reason'] : 'unknown';
                $io->error("The enrolment token was rejected ({$reason}). Mint a fresh one on the Hub and retry.");
                $emit(['ok' => false, 'result' => 'invalid_token', 'reason' => $reason]);

                return Command::FAILURE;

            case 422:
                $message = is_string($data['message'] ?? null) ? $data['message'] : 'invalid payload';
                $io->error("The Hub rejected the request payload: {$message}");
                $emit(['ok' => false, 'result' => 'invalid_payload', 'message' => $message]);

                return Command::FAILURE;

            default:
                $io->error("Unexpected HTTP {$status} from the Hub (signature verified). Body: " . json_encode($data));
                $emit(['ok' => false, 'result' => 'unexpected', 'http_status' => $status]);

                return Command::FAILURE;
        }
    }

    // -- Pure helpers (unit-tested directly) --------------------------------

    /**
     * The exact JSON bytes to send. `JSON_UNESCAPED_SLASHES` matches how the
     * Hub encodes its signed replies — kept consistent on both sides so the
     * `sha256(body)` in the canonical string lines up.
     *
     * @param array<string, string> $fields
     */
    public static function buildRequestBody(array $fields): string
    {
        return (string) json_encode($fields, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * Decode a base64 (standard or URL-safe) Ed25519 public key to its 32 raw
     * bytes, or null if it is not a well-formed key.
     */
    public static function decodeEd25519Key(string $b64): ?string
    {
        $raw = base64_decode(strtr($b64, '-_', '+/'), true);

        return is_string($raw) && strlen($raw) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ? $raw : null;
    }

    /**
     * Verify an ADR-0003 RESPONSE signature over the enrolment endpoint.
     * Throws {@see \RuntimeException} with a human reason on any failure;
     * returns nothing on success.
     *
     * @param callable(string): string $header Case-insensitive header lookup; '' when absent.
     * @param non-empty-string         $hubPublicKeyRaw 32 raw bytes.
     */
    public static function verifyResponseSignature(
        string $rawBody,
        callable $header,
        string $hubPublicKeyRaw,
        string $expectedKeyId,
        int $now,
        int $maxDriftSeconds = self::MAX_DRIFT_SECONDS,
    ): void {
        $algo      = strtolower(trim($header('X-Taw-Hub-Algo')));
        $keyId     = trim($header('X-Taw-Hub-Key-Id'));
        $timestamp = trim($header('X-Taw-Hub-Timestamp'));
        $nonce     = trim($header('X-Taw-Hub-Nonce'));
        $signature = trim($header('X-Taw-Hub-Signature'));

        if ($algo === '' && $keyId === '' && $timestamp === '' && $nonce === '' && $signature === '') {
            throw new \RuntimeException('the response carried no X-Taw-Hub-* signature headers');
        }
        if ($algo !== 'ed25519') {
            throw new \RuntimeException("unexpected signature algorithm '{$algo}' (want ed25519)");
        }
        if ($keyId === '' || !hash_equals($expectedKeyId, $keyId)) {
            throw new \RuntimeException("response key id '{$keyId}' does not match the expected '{$expectedKeyId}'");
        }
        if (preg_match('/^\d{1,20}$/', $timestamp) !== 1) {
            throw new \RuntimeException('malformed timestamp header');
        }
        if (preg_match('/^[A-Za-z0-9_\-]{8,128}$/', $nonce) !== 1) {
            throw new \RuntimeException('malformed nonce header');
        }
        if (abs($now - (int) $timestamp) > $maxDriftSeconds) {
            throw new \RuntimeException('response timestamp is outside the allowed clock-drift window');
        }

        $rawSignature = base64_decode(strtr($signature, '-_', '+/'), true);
        if (!is_string($rawSignature) || strlen($rawSignature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw new \RuntimeException('signature is not 64 bytes of base64');
        }

        $canonical = implode("\n", [
            self::SCHEME,
            'RESPONSE',
            self::SIGNED_PATH,
            $timestamp,
            $nonce,
            hash('sha256', $rawBody),
        ]);

        try {
            $ok = sodium_crypto_sign_verify_detached($rawSignature, $canonical, $hubPublicKeyRaw);
        } catch (\SodiumException $e) {
            throw new \RuntimeException('libsodium rejected the signature: ' . $e->getMessage());
        }
        if (!$ok) {
            throw new \RuntimeException('cryptographic verification failed');
        }
    }

    /**
     * Replace the `enrolment_token` value in a JSON body with a redacted stub,
     * for printing in `--dry-run` output.
     */
    public static function redactToken(string $jsonBody): string
    {
        $decoded = json_decode($jsonBody, true);
        if (is_array($decoded) && isset($decoded['enrolment_token']) && is_string($decoded['enrolment_token'])) {
            $tok = $decoded['enrolment_token'];
            $decoded['enrolment_token'] = strlen($tok) > 12
                ? substr($tok, 0, 6) . '…' . substr($tok, -2)
                : 'enrol_…';
        }

        return (string) json_encode($decoded, JSON_UNESCAPED_SLASHES);
    }

    // -- IO-bound helpers -------------------------------------------------

    /**
     * Normalise WP's header bag (array or `CaseInsensitiveDictionary`) into a
     * lowercase-keyed lookup closure.
     *
     * @param mixed $headers
     * @return callable(string): string
     */
    private static function headerLookup(mixed $headers): callable
    {
        $map = [];

        if (is_object($headers) && method_exists($headers, 'getAll')) {
            /** @var array<string, mixed> $all */
            $all = $headers->getAll();
        } elseif (is_array($headers)) {
            $all = $headers;
        } else {
            $all = [];
        }

        foreach ($all as $key => $value) {
            $map[strtolower((string) $key)] = is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value;
        }

        return static fn (string $name): string => $map[strtolower($name)] ?? '';
    }

    private static function stringOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function constantString(string $name): ?string
    {
        if (!defined($name)) {
            return null;
        }
        $value = constant($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function optionString(string $name): ?string
    {
        $value = get_option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function nonEmpty(string $value): ?string
    {
        return $value !== '' ? $value : null;
    }
}

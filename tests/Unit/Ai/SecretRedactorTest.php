<?php

namespace Tests\Unit\Ai;

use App\Domain\Ai\SecretRedactor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SecretRedactorTest extends TestCase
{
    /** @return array<string, list<string>> code line, then each secret it holds */
    public static function secrets(): array
    {
        return [
            'sk- prefix' => ['$client = new Client(\'sk-proj-abc\');', 'sk-proj-abc'],
            'sk-ant- prefix' => ['$client = new Client("sk-ant-api03-xyz");', 'sk-ant-api03-xyz'],
            'ghp_ prefix' => ['$gh->auth(\'ghp_16CharsOfStuff\');', 'ghp_16CharsOfStuff'],
            'github_pat_ prefix' => ['$gh->auth(\'github_pat_11AAA\');', 'github_pat_11AAA'],
            'AKIA prefix' => ['$s3->login(\'AKIAIOSFODNN7EXAMPLE\');', 'AKIAIOSFODNN7EXAMPLE'],
            'xox prefix' => ['$slack->use(\'xoxb-123-456\');', 'xoxb-123-456'],
            'AIza prefix' => ['$maps->use(\'AIzaSyD-shortkey\');', 'AIzaSyD-shortkey'],
            'prefix inside an interpolated string' => ['$header = "Bearer ghp_abcdef {$suffix}";', 'ghp_abcdef'],
            'high entropy literal' => ['$hmac = hash_hmac(\'sha256\', $body, \'q8Zr2Lx9Vb4Nc7Mw1Kp6Jt3Hy5Gd0Fs\');', 'q8Zr2Lx9Vb4Nc7Mw1Kp6Jt3Hy5Gd0Fs'],
            'array key password' => ['$config = [\'db_password\' => \'hunter2\'];', 'hunter2'],
            'array key api_key' => ['$config = ["api_key" => "plainvalue"];', 'plainvalue'],
            'array element password' => ['$this->config[\'password\'] = \'hunter2\';', 'hunter2'],
            'variable token' => ['$accessToken = \'letmein\';', 'letmein'],
            'property secret' => ['$this->clientSecret = \'s3cr3t\';', 's3cr3t'],
            'private key variable' => ['$privatePem = \'shh\';', 'shh'],
            'env default' => ['$key = env(\'STRIPE_KEY\', \'pk_live_default\');', 'pk_live_default'],
            'env default after a nested first argument' => ['$url = env(strtoupper(\'a\'), \'fallback-value\');', 'fallback-value'],
            'pem block' => ["\$pem = '-----BEGIN RSA PRIVATE KEY-----\nMIIEowIBAAKCAQEA\n-----END RSA PRIVATE KEY-----';", 'MIIEowIBAAKCAQEA'],
            // Review round 1 probes
            'Bearer prefix inside a literal' => ['Http::withHeaders([\'Authorization\' => \'Bearer sk-live-abcdef123\']);', 'sk-live-abcdef123'],
            'Bearer prefix under a neutral key' => ['Http::withHeaders([\'X-Upstream\' => \'Bearer sk-live-abcdef123\']);', 'sk-live-abcdef123'],
            'token prefix inside a literal' => ['$gh->send(\'token ghp_abcdefABCDEF1234\');', 'ghp_abcdefABCDEF1234'],
            'named arguments' => ['$c = new Client(apiKey: \'plainvalue1\', password: \'hunter2\');', 'plainvalue1', 'hunter2'],
            'ternary assigned to a secret name' => ['$password = $x ? \'hunter2\' : \'otherpw1\';', 'hunter2', 'otherpw1'],
            'null coalescing default' => ['$token = $in ?? \'fallbacktok\';', 'fallbacktok'],
            'coalescing assignment' => ['$token ??= \'fallbacktok2\';', 'fallbacktok2'],
            'concatenating assignment' => ['$token .= \'appendedtok\';', 'appendedtok'],
            'define' => ['define(\'API_KEY\', \'definedval\');', 'definedval'],
            'nowdoc assigned to a secret name' => ["\$secret = <<<'EOT'\nnowdocvalue\nEOT;", 'nowdocvalue'],
            'heredoc assigned to a secret name' => ["\$secret = <<<EOT\nheredocvalue {\$x} tail\nEOT;", 'heredocvalue', 'tail'],
            'hex key as an argument' => ['$t = new Twilio(\'AC123\', \'3f9a0c7e1b2d4f6a8c0e2b4d6f8a1c3e\');', '3f9a0c7e1b2d4f6a8c0e2b4d6f8a1c3e'],
            'hex key after a short prefix' => ['$mg->use(\'key-' . '3f9a0c7e1b2d4f6a8c0e2b4d6f8a1c3e\');', '3f9a0c7e1b2d4f6a8c0e2b4d6f8a1c3e'],
            'stripe_key' => ['$c = [\'stripe_key\' => \'plainvalue2\'];', 'plainvalue2'],
            'access_key' => ['$c = [\'access_key\' => \'plain3\'];', 'plain3'],
            'key variable' => ['$key = \'plainvalue4\';', 'plainvalue4'],
            'credential name' => ['$this->credentials = \'plain5\';', 'plain5'],
            'auth name' => ['$c = [\'basic_auth\' => \'plain6\'];', 'plain6'],
            'pgp block' => ["\$pgp = '-----BEGIN PGP PRIVATE KEY BLOCK-----\nlQOYBFabc\n-----END PGP PRIVATE KEY BLOCK-----';", 'lQOYBFabc'],
            'certificate block' => ["\$c = '-----BEGIN CERTIFICATE-----\nMIIBszCCabc\n-----END CERTIFICATE-----';", 'MIIBszCCabc'],
            'lone BEGIN line' => ["\$c = '-----BEGIN RSA PRIVATE KEY-----\nMIIEtruncated';", 'MIIEtruncated'],
            // Review round 2: a PEM split across literals must not unbalance the quotes for the code after it
            'mixed-quote PEM followed by more secrets' => ["\$pem = \"-----BEGIN PRIVATE KEY-----\\n\" . \$body . '-----END PRIVATE KEY-----';\n\$c = new Client('q8Zr2Lx9Vb4Nc7Mw1Kp6Jt3Hy5Gd0Fs');\n\$password = 'hunter2';\n\$s = new Stripe('sk-live-abc');", 'q8Zr2Lx9Vb4Nc7Mw1Kp6Jt3Hy5Gd0Fs', 'hunter2', 'sk-live-abc', 'BEGIN PRIVATE KEY'],
            // Final review probes
            'token passed to withToken' => ['Http::withToken(\'plainSecretValue2024\')->get($url);', 'plainSecretValue2024'],
            'basic auth arguments' => ['Http::withBasicAuth(\'admin\', \'Hunter2Password!\')->get($url);', 'Hunter2Password!'],
            'key passed to a setter' => ['$client->setApiKey(\'live_abcdefghij\');', 'live_abcdefghij'],
            'PEM split across two literals' => ['$pem = "-----BEGIN PRIVATE KEY-----\\nMIIEvQIBADAN" . "BgkqhkiG9w0BAQEF\\n-----END PRIVATE KEY-----";', 'MIIEvQIBADAN', 'BgkqhkiG9w0BAQEF'],
            'PEM end inside an interpolated string' => ['$pem = $head . "BgkqhkiG9w0BAQEF{$x}\\n-----END PRIVATE KEY-----";', 'BgkqhkiG9w0BAQEF'],
        ];
    }

    #[DataProvider('secrets')]
    public function test_each_secret_pattern_is_redacted_and_ordinary_code_is_kept(string $line, string ...$secrets): void
    {
        $code = "\$order->save();\n{$line}\n\$order->save();";

        $redacted = SecretRedactor::redact($code);

        foreach ($secrets as $secret) {
            $this->assertStringNotContainsString($secret, $redacted);
        }
        $this->assertStringContainsString('[redacted]', $redacted);
        $this->assertStringStartsWith("\$order->save();\n", $redacted);
        $this->assertStringEndsWith("\n\$order->save();", $redacted);
    }

    public function test_code_between_unrelated_begin_and_end_literals_is_kept(): void
    {
        $redacted = SecretRedactor::redact("\$a = '-----BEGIN X-----'; \$mid = 'keepme'; \$b = '-----END X-----';\n\$order->save();");

        $this->assertStringContainsString("\$mid = 'keepme';", $redacted);
        $this->assertStringContainsString("\$a = '[redacted]';", $redacted);
        $this->assertStringEndsWith("\n\$order->save();", $redacted);
    }

    public function test_a_block_inside_one_interpolated_string_is_redacted(): void
    {
        $redacted = SecretRedactor::redact("\$pem = \"-----BEGIN RSA PRIVATE KEY-----\nMIIEsecret {\$x}\n-----END RSA PRIVATE KEY-----\";\n\$order->save();");

        $this->assertStringNotContainsString('MIIEsecret', $redacted);
        $this->assertStringEndsWith("\n\$order->save();", $redacted);
    }

    public function test_ordinary_strings_are_kept(): void
    {
        $code = "\$order->status = 'paid';\nreturn env('APP_NAME');\n\$items = ['name' => 'Widget', 'note' => 'a short message with spaces in it'];\n\$msg = \"risk-free {\$order->id}\";";

        $this->assertSame($code, SecretRedactor::redact($code));
    }
}

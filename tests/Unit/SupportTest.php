<?php

declare(strict_types=1);

namespace Vau\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vau\Config;
use Vau\Database\Schema;
use Vau\Support\Crypto;
use Vau\Support\Text;

final class SupportTest extends TestCase
{
    public function testCryptoRoundTripAndTamper(): void
    {
        $crypto = new Crypto(str_repeat('x', 32));
        $box = $crypto->encrypt('ghp_segredo');

        self::assertStringStartsWith('v1:', $box);
        self::assertNotSame($box, $crypto->encrypt('ghp_segredo'), 'nonce deve variar');
        self::assertSame('ghp_segredo', $crypto->decrypt($box));
        self::assertNull($crypto->decrypt(null));

        $this->expectException(\RuntimeException::class);
        (new Crypto(str_repeat('y', 32)))->decrypt($box);
    }

    public function testBranchPatterns(): void
    {
        self::assertTrue(Text::matchesPatterns('qualquer', ''));
        self::assertTrue(Text::matchesPatterns('main', 'main, develop'));
        self::assertTrue(Text::matchesPatterns('release/1.2', 'release/*'));
        self::assertFalse(Text::matchesPatterns('feature/x', 'main, release/*'));
        self::assertFalse(Text::matchesPatterns('wip/x', '!wip/*'));
        self::assertTrue(Text::matchesPatterns('feature/x', '!wip/*'));
        self::assertFalse(Text::matchesPatterns(null, 'main'));
    }

    public function testFormatting(): void
    {
        self::assertSame('api-de-pagamentos', Text::slug('API de Pagamentos!'));
        self::assertSame('acao-rapida', Text::slug('Ação rápida'));
        self::assertSame('45 s', Text::duration(45_000));
        self::assertSame('2 min 05 s', Text::duration(125_000));
        self::assertSame('1 h 01 min', Text::duration(3_660_000));
        self::assertSame('vermelho', Text::stripAnsi("\e[31mvermelho\e[0m"));
        self::assertSame('https://•••@github.com/a/b', Text::maskUrlCredentials('https://x:tok@github.com/a/b'));
    }

    public function testEnvParser(): void
    {
        $env = Config::parseEnv("# c\nA=1\nB=\"dois # três\"\nC=quatro # comentário\nexport D='cinco'\n");

        self::assertSame(['A' => '1', 'B' => 'dois # três', 'C' => 'quatro', 'D' => 'cinco'], $env);
    }

    public function testSchemaDumpCoversAllDrivers(): void
    {
        foreach (['sqlite' => 'AUTOINCREMENT', 'mysql' => 'AUTO_INCREMENT', 'pgsql' => 'BIGSERIAL'] as $driver => $marker) {
            $ddl = Schema::dump($driver);
            self::assertStringContainsString($marker, $ddl);
            foreach (array_keys(Schema::tables()) as $table) {
                self::assertStringContainsString("CREATE TABLE IF NOT EXISTS {$table}", $ddl);
            }
        }
    }
}

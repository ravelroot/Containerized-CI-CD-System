<?php

declare(strict_types=1);

namespace Vau\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vau\Support\Yaml;
use Vau\Support\YamlException;

final class YamlTest extends TestCase
{
    public function testParsesMapsListsAndScalars(): void
    {
        $data = Yaml::parse(<<<'YAML'
# comentário
image: php:8.3-cli   # imagem
timeout: 20
ratio: 0.5
debug: false
nada: ~
env:
  APP_ENV: test
  URL: "https://x.test/a#b"
steps:
  - name: Testes
    run: vendor/bin/phpunit
  - echo solto
branches: [main, "release/*", 3]
YAML);

        self::assertSame('php:8.3-cli', $data['image']);
        self::assertSame(20, $data['timeout']);
        self::assertSame(0.5, $data['ratio']);
        self::assertFalse($data['debug']);
        self::assertNull($data['nada']);
        self::assertSame(['APP_ENV' => 'test', 'URL' => 'https://x.test/a#b'], $data['env']);
        self::assertSame(['name' => 'Testes', 'run' => 'vendor/bin/phpunit'], $data['steps'][0]);
        self::assertSame('echo solto', $data['steps'][1]);
        self::assertSame(['main', 'release/*', 3], $data['branches']);
    }

    public function testBlockScalars(): void
    {
        $data = Yaml::parse("a: |\n  linha 1\n    recuada\n  linha 3\nb: >\n  junta\n  as linhas\nc: |-\n  sem quebra\n");

        self::assertSame("linha 1\n  recuada\nlinha 3\n", $data['a']);
        self::assertSame("junta as linhas\n", $data['b']);
        self::assertSame('sem quebra', $data['c']);
    }

    public function testSequenceAtSameIndentAsKey(): void
    {
        $data = Yaml::parse("steps:\n- name: a\n  run: x\n- name: b\n  run: y\n");

        self::assertCount(2, $data['steps']);
        self::assertSame('y', $data['steps'][1]['run']);
    }

    public function testQuotedStringsKeepSpecialCharacters(): void
    {
        $data = Yaml::parse("a: 'it''s # not comment'\nb: \"tab\\tquebra\"\nc: '123'\n");

        self::assertSame("it's # not comment", $data['a']);
        self::assertSame("tab\tquebra", $data['b']);
        self::assertSame('123', $data['c']);
    }

    public function testRejectsTabs(): void
    {
        $this->expectException(YamlException::class);
        Yaml::parse("a:\n\tb: 1\n");
    }

    public function testRejectsDuplicatedKeys(): void
    {
        $this->expectException(YamlException::class);
        Yaml::parse("a: 1\na: 2\n");
    }

    public function testRejectsBrokenIndentation(): void
    {
        $this->expectException(YamlException::class);
        Yaml::parse("a: 1\n   b: 2\n");
    }
}

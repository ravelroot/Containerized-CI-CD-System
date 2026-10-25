<?php

declare(strict_types=1);

namespace Vau\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vau\Pipeline\Pipeline;
use Vau\Pipeline\PipelineException;

final class PipelineTest extends TestCase
{
    private const YAML = <<<'YAML'
image: node:22-alpine
timeout: 12
env:
  NODE_ENV: test
  CACHE: true
steps:
  - name: Instalar
    run: npm ci
  - name: Testes
    run:
      - npm test
      - npm run lint
  - name: Só na main
    run: echo main
    branches: [main]
  - name: Opcional
    run: exit 1
    allow_failure: true
deploy:
  branches: [main, "tag:v*"]
  image: alpine:3.20
  run: ./deploy.sh
YAML;

    public function testBuildsStepsWithDefaults(): void
    {
        $p = Pipeline::fromString(self::YAML, '.vau.yml', 'alpine:3.20');

        self::assertSame('node:22-alpine', $p->image);
        self::assertSame(12, $p->timeoutMinutes);
        self::assertSame(['NODE_ENV' => 'test', 'CACHE' => 'true'], $p->env);
        self::assertCount(4, $p->steps);
        self::assertSame('node:22-alpine', $p->steps[0]->image);
        self::assertSame("npm test\nnpm run lint", $p->steps[1]->run);
        self::assertTrue($p->steps[2]->runsOn('main'));
        self::assertFalse($p->steps[2]->runsOn('develop'));
        self::assertTrue($p->steps[3]->allowFailure);
        self::assertSame('alpine:3.20', $p->deploy?->image);
    }

    public function testDeployRules(): void
    {
        $p = Pipeline::fromString(self::YAML, '.vau.yml', 'alpine:3.20');

        self::assertTrue($p->deployAllowed('main', null, 'push'));
        self::assertFalse($p->deployAllowed('develop', null, 'push'));
        self::assertFalse($p->deployAllowed('main', null, 'pull_request'));
        self::assertTrue($p->deployAllowed(null, 'v1.2.0', 'tag'));
        self::assertFalse($p->deployAllowed(null, 'beta-1', 'tag'));
    }

    public function testAcceptsJson(): void
    {
        $p = Pipeline::fromString('{"steps": ["make test"]}', '.vau.json', 'alpine:3.20');

        self::assertSame('make test', $p->steps[0]->run);
        self::assertSame('alpine:3.20', $p->steps[0]->image);
    }

    /** @return iterable<string, array{0: string}> */
    public static function invalidPipelines(): iterable
    {
        yield 'sem etapas' => ["image: alpine\n"];
        yield 'chave desconhecida' => ["stages: [a]\nsteps: [x]\n"];
        yield 'imagem inválida' => ["image: 'rm -rf /'\nsteps: [x]\n"];
        yield 'run vazio' => ["steps:\n  - name: a\n    run: ''\n"];
        yield 'env em lista' => ["env: [A]\nsteps: [x]\n"];
        yield 'variável inválida' => ["env:\n  1A: x\nsteps: [x]\n"];
        yield 'timeout absurdo' => ["timeout: 9999\nsteps: [x]\n"];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidPipelines')]
    public function testRejectsInvalidPipelines(string $yaml): void
    {
        $this->expectException(PipelineException::class);
        Pipeline::fromString($yaml, '.vau.yml', 'alpine:3.20');
    }
}

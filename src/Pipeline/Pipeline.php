<?php

declare(strict_types=1);

namespace Vau\Pipeline;

use Vau\Support\Text;
use Vau\Support\Yaml;
use Vau\Support\YamlException;

/**
 * Pipeline validado, lido de .vau.yml (ou .vau.json) no repositório ou
 * do pipeline padrão cadastrado no projeto.
 *
 *   image: php:8.3-cli
 *   timeout: 20
 *   env: { APP_ENV: test }
 *   steps:
 *     - name: Dependências
 *       run: composer install
 *     - name: Testes
 *       run: vendor/bin/phpunit
 *   deploy:
 *     branches: [main]
 *     image: alpine:3.20
 *     run: ./scripts/deploy.sh
 */
final class Pipeline
{
    public const FILES = ['.vau.yml', '.vau.yaml', '.vau.json'];
    public const MAX_STEPS = 30;

    /**
     * @param list<Step> $steps
     * @param array<string, string> $env
     * @param list<string> $deployBranches
     */
    public function __construct(
        public readonly string $image,
        public readonly ?int $timeoutMinutes,
        public readonly array $env,
        public readonly array $steps,
        public readonly ?Step $deploy,
        public readonly array $deployBranches,
    ) {}

    /** @throws PipelineException */
    public static function fromString(string $content, string $file, string $defaultImage): self
    {
        try {
            $data = str_ends_with($file, '.json')
                ? json_decode($content, true, 32, JSON_THROW_ON_ERROR)
                : Yaml::parse($content);
        } catch (\JsonException $e) {
            throw new PipelineException("{$file}: JSON inválido ({$e->getMessage()}).");
        } catch (YamlException $e) {
            throw new PipelineException("{$file}: {$e->getMessage()}.");
        }
        if (!is_array($data)) {
            throw new PipelineException("{$file}: o conteúdo precisa ser um mapa.");
        }

        return self::fromArray($data, $defaultImage);
    }

    /**
     * @param array<mixed> $data
     * @throws PipelineException
     */
    public static function fromArray(array $data, string $defaultImage): self
    {
        $unknown = array_diff(array_keys($data), ['image', 'timeout', 'env', 'steps', 'deploy']);
        if ($unknown !== []) {
            throw new PipelineException('Chaves desconhecidas no pipeline: ' . implode(', ', $unknown) . '.');
        }
        $image = self::image($data['image'] ?? $defaultImage, 'image');
        $timeout = null;
        if (isset($data['timeout'])) {
            if (!is_int($data['timeout']) || $data['timeout'] < 1 || $data['timeout'] > 360) {
                throw new PipelineException('timeout deve ser um número inteiro de minutos entre 1 e 360.');
            }
            $timeout = $data['timeout'];
        }
        $env = self::env($data['env'] ?? [], 'env');

        $rawSteps = $data['steps'] ?? null;
        if (!is_array($rawSteps) || $rawSteps === [] || !array_is_list($rawSteps)) {
            throw new PipelineException('O pipeline precisa de uma lista "steps" com pelo menos uma etapa.');
        }
        if (count($rawSteps) > self::MAX_STEPS) {
            throw new PipelineException('O pipeline aceita no máximo ' . self::MAX_STEPS . ' etapas.');
        }
        $steps = [];
        foreach ($rawSteps as $index => $raw) {
            $steps[] = self::step($raw, $index + 1, $image);
        }

        $deploy = null;
        $branches = [];
        if (isset($data['deploy'])) {
            if (!is_array($data['deploy'])) {
                throw new PipelineException('deploy deve ser um mapa com branches e run.');
            }
            $rawBranches = $data['deploy']['branches'] ?? ['main'];
            $rawBranches = is_string($rawBranches) ? array_map('trim', explode(',', $rawBranches)) : $rawBranches;
            if (!is_array($rawBranches) || $rawBranches === []) {
                throw new PipelineException('deploy.branches deve ser uma lista, como [main].');
            }
            foreach ($rawBranches as $b) {
                if (!is_string($b) || $b === '') {
                    throw new PipelineException('deploy.branches aceita só nomes de branch.');
                }
                $branches[] = $b;
            }
            $deployData = $data['deploy'];
            unset($deployData['branches']);
            $deployData['name'] ??= 'Deploy';
            $deploy = self::step($deployData, 0, $image, true);
        }

        return new self($image, $timeout, $env, $steps, $deploy, $branches);
    }

    /** O deploy roda neste build? Só em push/tag/manual, numa branch liberada. */
    public function deployAllowed(?string $branch, ?string $tag, string $trigger): bool
    {
        if ($this->deploy === null || in_array($trigger, ['pull_request'], true)) {
            return false;
        }

        // tags entram nos padrões com o prefixo "tag:", como em [main, "tag:v*"]
        $name = $branch ?? ($tag !== null ? 'tag:' . $tag : null);

        return $name !== null && Text::matchesPatterns($name, $this->deployBranches);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'image' => $this->image,
            'timeout' => $this->timeoutMinutes,
            'env' => $this->env,
            'steps' => array_map(static fn(Step $s) => $s->toArray(), $this->steps),
            'deploy' => $this->deploy !== null ? $this->deploy->toArray() + ['branches' => $this->deployBranches] : null,
        ];
    }

    /** @throws PipelineException */
    private static function step(mixed $raw, int $position, string $defaultImage, bool $isDeploy = false): Step
    {
        $label = $isDeploy ? 'deploy' : "steps[{$position}]";
        if (is_string($raw)) {
            $raw = ['name' => Text::truncate(Text::firstLine($raw), 60), 'run' => $raw];
        }
        if (!is_array($raw)) {
            throw new PipelineException("{$label} deve ser um comando ou um mapa com name e run.");
        }
        $unknown = array_diff(array_keys($raw), ['name', 'run', 'image', 'env', 'branches', 'allow_failure']);
        if ($unknown !== []) {
            throw new PipelineException("{$label}: chaves desconhecidas: " . implode(', ', $unknown) . '.');
        }
        $run = $raw['run'] ?? null;
        if (is_array($run) && array_is_list($run)) {
            $run = implode("\n", array_map('strval', $run));
        }
        if (!is_string($run) || trim($run) === '') {
            throw new PipelineException("{$label}: informe o comando em run.");
        }
        if (strlen($run) > 20000) {
            throw new PipelineException("{$label}: o script passa de 20 mil caracteres.");
        }
        $name = trim((string) ($raw['name'] ?? '')) ?: "Etapa {$position}";
        if (mb_strlen($name) > 80) {
            throw new PipelineException("{$label}: o nome passa de 80 caracteres.");
        }
        $branches = [];
        if (!$isDeploy && isset($raw['branches'])) {
            $branches = is_string($raw['branches']) ? array_map('trim', explode(',', $raw['branches'])) : (array) $raw['branches'];
        }

        return new Step(
            name: $name,
            run: rtrim($run),
            image: self::image($raw['image'] ?? $defaultImage, "{$label}.image"),
            env: self::env($raw['env'] ?? [], "{$label}.env"),
            branches: array_values(array_map('strval', $branches)),
            allowFailure: (bool) ($raw['allow_failure'] ?? false),
        );
    }

    /** @throws PipelineException */
    private static function image(mixed $value, string $label): string
    {
        if (!is_string($value) || preg_match('#^[a-z0-9][a-z0-9._/-]*(:[A-Za-z0-9._-]+)?(@sha256:[a-f0-9]{64})?$#', $value) !== 1 || strlen($value) > 255) {
            throw new PipelineException("{$label}: nome de imagem Docker inválido.");
        }

        return $value;
    }

    /**
     * @return array<string, string>
     * @throws PipelineException
     */
    private static function env(mixed $value, string $label): array
    {
        if ($value === null || $value === []) {
            return [];
        }
        if (!is_array($value) || array_is_list($value)) {
            throw new PipelineException("{$label} deve ser um mapa NOME: valor.");
        }
        $out = [];
        foreach ($value as $key => $v) {
            $key = (string) $key;
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,79}$/', $key) !== 1) {
                throw new PipelineException("{$label}: nome de variável inválido ({$key}).");
            }
            if (is_array($v)) {
                throw new PipelineException("{$label}.{$key} deve ser um valor simples.");
            }
            $out[$key] = is_bool($v) ? ($v ? 'true' : 'false') : (string) $v;
        }

        return $out;
    }
}

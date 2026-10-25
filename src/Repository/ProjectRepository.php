<?php

declare(strict_types=1);

namespace Vau\Repository;

use Vau\Database\Connection;
use Vau\Support\Crypto;
use Vau\Support\Text;
use Vau\Support\Time;

final class ProjectRepository
{
    public const PROVIDERS = ['github' => 'GitHub', 'gitlab' => 'GitLab', 'generic' => 'Git genérico'];
    public const EVENTS = ['push' => 'Push', 'pull_request' => 'Pull/Merge request', 'tag' => 'Tag'];
    public const NOTIFY = ['falhas' => 'Falhas e recuperações', 'todas' => 'Todos os builds', 'nunca' => 'Nunca'];

    public function __construct(private readonly Connection $db, private readonly Crypto $crypto) {}

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM va_project WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed>|null */
    public function findBySlug(string $slug): ?array
    {
        return $this->db->one('SELECT * FROM va_project WHERE slug = ?', [$slug]);
    }

    /**
     * Projetos com o último build e a taxa de sucesso dos últimos 30 dias.
     *
     * @return list<array<string, mixed>>
     */
    public function allWithStats(): array
    {
        $projects = $this->db->all('SELECT * FROM va_project ORDER BY active DESC, name');
        if ($projects === []) {
            return [];
        }
        $since = Time::at(Time::ts() - 30 * 86400);
        $stats = [];
        foreach ($this->db->all(
            "SELECT project_id, COUNT(*) AS total, SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) AS ok,
                    SUM(CASE WHEN status IN ('failed', 'error') THEN 1 ELSE 0 END) AS bad
             FROM va_build WHERE queued_at >= ? GROUP BY project_id",
            [$since],
        ) as $row) {
            $stats[(int) $row['project_id']] = $row;
        }
        $last = [];
        foreach ($this->db->all(
            'SELECT b.* FROM va_build b JOIN (SELECT project_id, MAX(id) AS id FROM va_build GROUP BY project_id) m ON m.id = b.id',
        ) as $row) {
            $last[(int) $row['project_id']] = $row;
        }
        foreach ($projects as &$project) {
            $id = (int) $project['id'];
            $s = $stats[$id] ?? ['total' => 0, 'ok' => 0, 'bad' => 0];
            $finished = (int) $s['ok'] + (int) $s['bad'];
            $project['builds_30d'] = (int) $s['total'];
            $project['success_rate'] = $finished > 0 ? (int) round(100 * (int) $s['ok'] / $finished) : null;
            $project['last_build'] = $last[$id] ?? null;
        }

        return $projects;
    }

    /**
     * @param array<string, mixed> $data
     * @return array{id: int, secret: string}
     */
    public function create(array $data, ?int $userId): array
    {
        $clean = $this->validate($data);
        $slug = Text::slug((string) ($data['slug'] ?? '') ?: $clean['name']);
        if ($slug === '') {
            throw new \InvalidArgumentException('Informe um nome com letras ou números.');
        }
        $base = $slug;
        for ($n = 2; $this->findBySlug($slug) !== null; $n++) {
            $slug = $base . '-' . $n;
        }
        $secret = Crypto::token(32);
        $now = Time::now();
        $clean += [
            'slug' => $slug,
            'webhook_secret_enc' => $this->crypto->encrypt($secret),
            'created_by' => $userId,
            'created_at' => $now,
            'updated_at' => $now,
            'active' => 1,
        ];
        if (isset($data['access_token']) && trim((string) $data['access_token']) !== '') {
            $clean['access_token_enc'] = $this->crypto->encrypt(trim((string) $data['access_token']));
        }

        return ['id' => $this->db->insert('va_project', $clean), 'secret' => $secret];
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void
    {
        $clean = $this->validate($data);
        if (array_key_exists('access_token', $data)) {
            $token = trim((string) $data['access_token']);
            if ($token === '-') {
                $clean['access_token_enc'] = null;
            } elseif ($token !== '') {
                $clean['access_token_enc'] = $this->crypto->encrypt($token);
            }
        }
        if (isset($data['active'])) {
            $clean['active'] = (int) (bool) $data['active'];
        }
        $clean['updated_at'] = Time::now();
        $this->db->update('va_project', $clean, ['id' => $id]);
    }

    public function rotateSecret(int $id): string
    {
        $secret = Crypto::token(32);
        $this->db->update('va_project', [
            'webhook_secret_enc' => $this->crypto->encrypt($secret),
            'updated_at' => Time::now(),
        ], ['id' => $id]);

        return $secret;
    }

    /** @param array<string, mixed> $project */
    public function webhookSecret(array $project): string
    {
        return (string) $this->crypto->decrypt((string) $project['webhook_secret_enc']);
    }

    /** @param array<string, mixed> $project */
    public function accessToken(array $project): ?string
    {
        return $this->crypto->decrypt($project['access_token_enc'] ?? null);
    }

    public function delete(int $id): void
    {
        $this->db->run('DELETE FROM va_project WHERE id = ?', [$id]);
    }

    /** @return list<array<string, mixed>> */
    public function secrets(int $projectId): array
    {
        return $this->db->all(
            'SELECT id, name, deploy_only, created_at, updated_at FROM va_secret WHERE project_id = ? ORDER BY name',
            [$projectId],
        );
    }

    /**
     * Segredos decifrados para injetar no build.
     *
     * @return array<string, array{value: string, deploy_only: bool}>
     */
    public function secretValues(int $projectId): array
    {
        $out = [];
        foreach ($this->db->all('SELECT name, value_enc, deploy_only FROM va_secret WHERE project_id = ?', [$projectId]) as $row) {
            $out[(string) $row['name']] = [
                'value' => (string) $this->crypto->decrypt((string) $row['value_enc']),
                'deploy_only' => (bool) $row['deploy_only'],
            ];
        }

        return $out;
    }

    public function putSecret(int $projectId, string $name, string $value, bool $deployOnly): void
    {
        $name = strtoupper(trim($name));
        if (preg_match('/^[A-Z_][A-Z0-9_]{0,79}$/', $name) !== 1) {
            throw new \InvalidArgumentException('O nome do segredo deve usar letras maiúsculas, números e sublinhado, sem começar com número.');
        }
        if (str_starts_with($name, 'VAU_') || in_array($name, ['CI', 'PATH', 'HOME'], true)) {
            throw new \InvalidArgumentException('Esse nome é reservado.');
        }
        if ($value === '') {
            throw new \InvalidArgumentException('O valor do segredo não pode ficar vazio.');
        }
        $now = Time::now();
        $existing = $this->db->one('SELECT id FROM va_secret WHERE project_id = ? AND name = ?', [$projectId, $name]);
        $data = ['value_enc' => $this->crypto->encrypt($value), 'deploy_only' => (int) $deployOnly, 'updated_at' => $now];
        if ($existing) {
            $this->db->update('va_secret', $data, ['id' => (int) $existing['id']]);

            return;
        }
        $this->db->insert('va_secret', $data + ['project_id' => $projectId, 'name' => $name, 'created_at' => $now]);
    }

    public function deleteSecret(int $projectId, int $secretId): void
    {
        $this->db->run('DELETE FROM va_secret WHERE id = ? AND project_id = ?', [$secretId, $projectId]);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function validate(array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) {
            throw new \InvalidArgumentException('Informe o nome do projeto (até 120 caracteres).');
        }
        $provider = (string) ($data['provider'] ?? 'github');
        if (!isset(self::PROVIDERS[$provider])) {
            throw new \InvalidArgumentException('Provedor inválido.');
        }
        $repo = trim((string) ($data['repo_url'] ?? ''));
        if (!self::isValidRepoUrl($repo)) {
            throw new \InvalidArgumentException('Informe a URL HTTPS de clonagem do repositório, como https://github.com/org/repo.git.');
        }
        $branch = trim((string) ($data['default_branch'] ?? 'main')) ?: 'main';
        if (preg_match('#^[A-Za-z0-9._/-]{1,120}$#', $branch) !== 1) {
            throw new \InvalidArgumentException('Branch padrão inválida.');
        }
        $events = array_values(array_intersect(array_keys(self::EVENTS), (array) ($data['events'] ?? array_keys(self::EVENTS))));
        $timeout = (int) ($data['timeout_minutes'] ?? 30);
        if ($timeout < 1 || $timeout > 360) {
            throw new \InvalidArgumentException('O tempo limite deve ficar entre 1 e 360 minutos.');
        }
        $cpus = trim((string) ($data['cpus'] ?? '1.0')) ?: '1.0';
        if (preg_match('/^(0\.[1-9][0-9]?|[1-9][0-9]?(\.[0-9]{1,2})?)$/', $cpus) !== 1) {
            throw new \InvalidArgumentException('CPUs deve ser um número como 0.5, 1 ou 2.');
        }
        $memory = strtolower(trim((string) ($data['memory'] ?? '1g'))) ?: '1g';
        if (preg_match('/^[1-9][0-9]{0,4}[mg]$/', $memory) !== 1) {
            throw new \InvalidArgumentException('Memória deve ser como 512m ou 2g.');
        }
        $network = (string) ($data['network'] ?? 'bridge');
        if (!in_array($network, ['bridge', 'none'], true)) {
            throw new \InvalidArgumentException('Rede inválida.');
        }
        $notifyUrl = trim((string) ($data['notify_url'] ?? ''));
        if ($notifyUrl !== '' && !filter_var($notifyUrl, FILTER_VALIDATE_URL) || ($notifyUrl !== '' && !str_starts_with($notifyUrl, 'https://'))) {
            throw new \InvalidArgumentException('A URL de notificação precisa começar com https://.');
        }
        $notifyOn = (string) ($data['notify_on'] ?? 'falhas');
        $pipeline = trim(str_replace("\r\n", "\n", (string) ($data['pipeline_yaml'] ?? '')));

        return [
            'name' => $name,
            'provider' => $provider,
            'repo_url' => $repo,
            'default_branch' => $branch,
            'branch_filter' => mb_substr(trim((string) ($data['branch_filter'] ?? '')), 0, 500) ?: null,
            'events' => implode(',', $events),
            'pipeline_yaml' => $pipeline !== '' ? $pipeline : null,
            'timeout_minutes' => $timeout,
            'cpus' => $cpus,
            'memory' => $memory,
            'network' => $network,
            'auto_cancel' => (int) !empty($data['auto_cancel']),
            'allow_deploy' => (int) !empty($data['allow_deploy']),
            'report_status' => (int) !empty($data['report_status']),
            'notify_url' => $notifyUrl !== '' ? $notifyUrl : null,
            'notify_on' => isset(self::NOTIFY[$notifyOn]) ? $notifyOn : 'falhas',
        ];
    }

    public static function isValidRepoUrl(string $url): bool
    {
        if (str_starts_with($url, 'file://') && getenv('VAU_ALLOW_FILE_REPOS') === '1') {
            return true;
        }

        return preg_match('#^https://[A-Za-z0-9.-]+(:[0-9]+)?/[A-Za-z0-9._~/-]+$#', $url) === 1;
    }
}

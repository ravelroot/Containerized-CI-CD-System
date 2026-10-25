<?php

declare(strict_types=1);

namespace Vau\Database;

use Vau\Repository\AuditRepository;
use Vau\Repository\BuildRepository;
use Vau\Repository\DeliveryRepository;
use Vau\Repository\ProjectRepository;
use Vau\Repository\TokenRepository;
use Vau\Repository\UserRepository;
use Vau\Repository\WorkerRepository;
use Vau\Support\Crypto;

/** Ponto único de acesso aos repositórios, criados sob demanda. */
final class Repositories
{
    private ?UserRepository $users = null;
    private ?ProjectRepository $projects = null;
    private ?BuildRepository $builds = null;
    private ?DeliveryRepository $deliveries = null;
    private ?AuditRepository $audit = null;
    private ?WorkerRepository $workers = null;
    private ?TokenRepository $tokens = null;

    public function __construct(public readonly Connection $db, public readonly Crypto $crypto) {}

    public function users(): UserRepository
    {
        return $this->users ??= new UserRepository($this->db);
    }

    public function projects(): ProjectRepository
    {
        return $this->projects ??= new ProjectRepository($this->db, $this->crypto);
    }

    public function builds(): BuildRepository
    {
        return $this->builds ??= new BuildRepository($this->db);
    }

    public function deliveries(): DeliveryRepository
    {
        return $this->deliveries ??= new DeliveryRepository($this->db);
    }

    public function audit(): AuditRepository
    {
        return $this->audit ??= new AuditRepository($this->db);
    }

    public function workers(): WorkerRepository
    {
        return $this->workers ??= new WorkerRepository($this->db);
    }

    public function tokens(): TokenRepository
    {
        return $this->tokens ??= new TokenRepository($this->db);
    }
}

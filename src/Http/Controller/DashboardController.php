<?php

declare(strict_types=1);

namespace Vau\Http\Controller;

use Vau\Http\Charts;
use Vau\Http\Request;
use Vau\Http\Response;
use Vau\Metrics\Stats;

final class DashboardController extends Controller
{
    /** @param array<string, string> $params */
    public function index(Request $request, array $params): Response
    {
        $stats = new Stats($this->app->db());
        $tz = $this->app->config->timezone();
        $stale = $this->app->config->int('VAU_WORKER_STALE_SECONDS', 120);

        return $this->page('dashboard', [
            'summary' => $stats->summary(7),
            'chart' => Charts::dailyBars($stats->daily(14, $tz)),
            'recent' => $this->repos->builds()->list([], 8),
            'running' => $this->repos->builds()->list(['status' => 'running'], 5),
            'workers' => $this->repos->workers()->all(),
            'workersAlive' => $this->repos->workers()->alive($stale),
            'projects' => count($this->repos->projects()->allWithStats()),
            'runtime' => $this->app->config->runtime(),
        ]);
    }
}

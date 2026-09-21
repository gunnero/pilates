<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\TrainingGroup;
use App\Support\SessionMaterializer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Throwable;

class MaterializeSessions extends Command
{
    protected $signature = 'gt:sessions:materialize {--organization=}';

    protected $description = 'Materialize the next 56 local days, independently per group; safe to rerun';

    public function handle(): int
    {
        $input = Validator::make(['organization' => $this->option('organization')], ['organization' => 'nullable|uuid'])->validate();
        $orgs = Organization::query()->where('status', 'active');
        if ($input['organization'] !== null) {
            $orgs->where('public_id', $input['organization']);
        }
        if ($input['organization'] !== null && ! $orgs->exists()) {
            $this->error('organization_unavailable');

            return self::FAILURE;
        }
        $failed = false;
        foreach ($orgs->lazyById(100) as $org) {
            foreach (TrainingGroup::query()->where('organization_id', $org->id)->lazyById(100) as $g) {
                try {
                    $counts = SessionMaterializer::run($org->id, $g->public_id);
                    $this->line(json_encode(['organization' => $org->public_id, 'group' => $g->public_id, ...$counts], JSON_THROW_ON_ERROR));
                    if ($counts['invalid'] + $counts['dst'] + $counts['conflict'] + $counts['limit'] + ($counts['permanent_conflicts'] ?? 0) + ($counts['permanent_ineligible'] ?? 0) > 0) {
                        $failed = true;
                    }
                } catch (Throwable) {
                    // Do not output exception messages: SQL bindings may contain operational names.
                    $this->error(json_encode(['organization' => $org->public_id, 'group' => $g->public_id, 'failed' => 1], JSON_THROW_ON_ERROR));
                    $failed = true;
                }
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}

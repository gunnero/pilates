<?php

namespace App\Http\Controllers;

use App\Http\Requests\GroupSearchRequest;
use App\Http\Requests\GroupStatusRequest;
use App\Http\Requests\TrainingGroupRequest;
use App\Models\TrainingGroup;
use App\Models\User;
use App\Support\GroupDirectory;
use App\Support\GroupLifecycle;
use App\Support\Permission;
use App\Support\ScheduleLifecycle;
use App\Support\TenantContext;
use App\Support\TrainerDirectory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class TrainingGroupController
{
    public function index(GroupSearchRequest $request, TenantContext $context): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $data = $request->validated();
        $query = GroupDirectory::query($context, $actor);
        if (($data['status'] ?? '') !== 'all') {
            $query->whereIn('status', isset($data['status']) ? [$data['status']] : ['active', 'inactive']);
        }
        if (! empty($data['search'])) {
            $query->whereRaw('lower(name) LIKE ?', [addcslashes(mb_strtolower($data['search']), '\\%_').'%']);
        }
        if (isset($data['location_id'])) {
            $query->where('location_id', $context->locations()->where('public_id', $data['location_id'])->firstOrFail()->id);
        }
        if (isset($data['trainer_id'])) {
            $trainer = TrainerDirectory::query($context, $actor)->where('public_id', $data['trainer_id'])->firstOrFail();
            $query->where('primary_trainer_id', $trainer->id);
        }
        $this->summaries($query, $context, $actor);
        $page = $query->orderBy('id')->paginate($data['per_page'] ?? 25, ['*'], 'page', $data['page'] ?? 1);

        return response()->json(['data' => $page->getCollection()->map(fn (TrainingGroup $g) => $this->represent($g, $context)), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]]);
    }

    /** @param Builder<TrainingGroup> $query */
    private function summaries(Builder $query, TenantContext $context, User $actor): void
    {
        if (Gate::forUser($actor)->allows(Permission::ScheduleView->value)) {
            $future = fn ($q) => $q->whereNull('canceled_at')->where(fn (Builder $end) => $end->whereNull('effective_until')->orWhere('effective_until', '>=', GroupLifecycle::today($context)));
            $query->withCount(['scheduleRules as schedule_rule_count' => $future])->with(['scheduleRules' => fn ($q) => $future($q)->orderBy('weekday')->orderBy('start_minute')->orderBy('effective_from')->orderBy('id')->limit(5)]);
        }
    }

    /** @return array<string, mixed> */
    private function represent(TrainingGroup $group, TenantContext $context, bool $detail = false): array
    {
        $data = GroupDirectory::represent($group, $context, $detail);
        if ($group->relationLoaded('scheduleRules')) {
            $data['schedule_summary'] = $group->scheduleRules->map(ScheduleLifecycle::represent(...));
            $data['schedule_rule_count'] = (int) $group->getAttribute('schedule_rule_count');
        }

        return $data;
    }

    public function show(Request $request, TenantContext $context, string $organization, string $group): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $this->profile($context, $actor, $group);
    }

    public function store(TrainingGroupRequest $request, TenantContext $context): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $group = GroupLifecycle::save($context, $actor, $request->validated());

        return $this->profile($context, $actor, $group->public_id, 201);
    }

    public function update(TrainingGroupRequest $request, TenantContext $context, string $organization, string $group): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        GroupLifecycle::save($context, $actor, $request->validated(), $group);

        return $this->profile($context, $actor, $group);
    }

    public function status(GroupStatusRequest $request, TenantContext $context, string $organization, string $group): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        GroupLifecycle::status($context, $actor, $group, $request->validated('status'));

        return $this->profile($context, $actor, $group);
    }

    private function profile(TenantContext $context, User $actor, string $id, int $status = 200): JsonResponse
    {
        $query = GroupDirectory::query($context, $actor)->where('public_id', $id);
        $this->summaries($query, $context, $actor);

        return response()->json(['data' => $this->represent($query->firstOrFail(), $context, true)], $status);
    }
}

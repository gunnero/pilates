<?php

namespace App\Http\Controllers;

use App\Http\Requests\SessionCalendarRequest;
use App\Http\Requests\SessionCancelRequest;
use App\Http\Requests\TrainingSessionRequest;
use App\Models\User;
use App\Support\SessionDirectory;
use App\Support\SessionLifecycle;
use App\Support\SessionMaterializer;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TrainingSessionController
{
    public function index(SessionCalendarRequest $request, TenantContext $context): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $data = $request->validated();
        if (CarbonImmutable::parse($data['from'])->diffInDays(CarbonImmutable::parse($data['until'])) >= 56) {
            throw ValidationException::withMessages(['until' => __('sessions.range')]);
        }
        $q = SessionDirectory::query($context, $actor)->whereBetween('local_date', [$data['from'], $data['until']]);
        foreach (['location_id' => 'location', 'trainer_id' => 'trainer', 'group_id' => 'group'] as $field => $relation) {
            if (isset($data[$field])) {
                $q->whereHas($relation, fn ($r) => $r->where('public_id', $data[$field]));
            }
        }
        if (($data['status'] ?? 'all') !== 'all') {
            $q->where('status', $data['status']);
        }
        $page = $q->orderBy('local_date')->orderBy('starts_at')->orderBy('training_group_id')->orderBy('id')->paginate($data['per_page'] ?? 25);

        return response()->json(['data' => $page->getCollection()->map(SessionDirectory::represent(...)), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'per_page' => $page->perPage()]]);
    }

    public function show(Request $request, TenantContext $context, string $organization, string $session): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $this->profile($context, $actor, $session);
    }

    public function store(TrainingSessionRequest $request, TenantContext $context): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $s = SessionLifecycle::save($context, $actor, $request->validated());

        return $this->profile($context, $actor, $s->public_id, 201);
    }

    public function update(TrainingSessionRequest $request, TenantContext $context, string $organization, string $session): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $s = SessionLifecycle::save($context, $actor, $request->validated(), $session);

        return $this->profile($context, $actor, $s->public_id);
    }

    public function cancel(SessionCancelRequest $request, TenantContext $context, string $organization, string $session): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $s = SessionLifecycle::cancel($context, $actor, $session, $request->validated('reason'));

        return $this->profile($context, $actor, $s->public_id);
    }

    public function materialize(Request $request, TenantContext $context, string $organization, string $group): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        abort_unless($request->all() === [], 422);

        return response()->json(['data' => SessionMaterializer::run($context->organization()->id, $group, $context, $actor)]);
    }

    private function profile(TenantContext $context, User $actor, string $id, int $status = 200): JsonResponse
    {
        return response()->json(['data' => SessionDirectory::represent(SessionDirectory::query($context, $actor)->where('public_id', $id)->firstOrFail())], $status);
    }
}

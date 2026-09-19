<?php

namespace App\Http\Controllers;

use App\Http\Requests\ScheduleCloseRequest;
use App\Http\Requests\ScheduleRuleRequest;
use App\Models\User;
use App\Support\GroupDirectory;
use App\Support\ScheduleLifecycle;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScheduleRuleController
{
    public function index(Request $request, TenantContext $context, string $organization, string $group): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $data = $request->validate(['page' => ['sometimes', 'integer', 'between:1,10000'], 'per_page' => ['sometimes', 'integer', 'between:1,100']]);
        $record = GroupDirectory::query($context, $actor)->where('public_id', $group)->firstOrFail();
        $page = $record->scheduleRules()->orderBy('weekday')->orderBy('start_minute')->orderBy('effective_from')->orderBy('id')->paginate($data['per_page'] ?? 25);

        return response()->json(['data' => $page->getCollection()->map(ScheduleLifecycle::represent(...)), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'timezone' => $context->organization()->timezone, 'today' => now($context->organization()->timezone)->toDateString()]]);
    }

    public function store(ScheduleRuleRequest $request, TenantContext $context, string $organization, string $group): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $rule = ScheduleLifecycle::create($context, $actor, $group, $request->validated());

        return response()->json(['data' => ScheduleLifecycle::represent($rule)], 201);
    }

    public function replace(ScheduleRuleRequest $request, TenantContext $context, string $organization, string $group, string $rule): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $successor = ScheduleLifecycle::create($context, $actor, $group, $request->validated(), $rule);

        return response()->json(['data' => ScheduleLifecycle::represent($successor)], 201);
    }

    public function close(ScheduleCloseRequest $request, TenantContext $context, string $organization, string $group, string $rule): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        ScheduleLifecycle::close($context, $actor, $group, $rule, $request->validated('effective_until'));

        return response()->json(['data' => null]);
    }

    public function cancel(Request $request, TenantContext $context, string $organization, string $group, string $rule): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        abort_unless($request->all() === [], 422);
        ScheduleLifecycle::close($context, $actor, $group, $rule, null);

        return response()->json(['data' => null]);
    }
}

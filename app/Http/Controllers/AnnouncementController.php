<?php

namespace App\Http\Controllers;

use App\Http\Requests\AnnouncementRequest;
use App\Models\StudioAnnouncement;
use App\Models\StudioMember;
use App\Models\TrainingGroup;
use App\Support\Audit;
use App\Support\Lifecycle;
use App\Support\LocationScope;
use App\Support\Permission;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class AnnouncementController
{
    public function __construct(private readonly TenantContext $context) {}

    private function authorize(Request $r): void
    {
        Gate::forUser($r->user())->authorize(Permission::OrganizationsManage->value);
        abort_unless($this->context->membership()->location_scope === LocationScope::All, 403);
    }

    public function store(AnnouncementRequest $r): JsonResponse
    {
        $this->authorize($r);
        $a = Lifecycle::run($this->context, MemberAccountController::actor($r), Permission::OrganizationsManage, function () use ($r) {
            $this->authorize($r);
            $org = $this->context->organization();
            $existing = StudioAnnouncement::where('organization_id', $org->id)->where('request_id', $r->validated('request_id'))->first();
            if ($existing !== null) {
                foreach ($r->safe()->except(['group_id', 'request_id']) as $key => $value) {
                    abort_unless($existing->getAttribute($key) === $value, 409);
                }
                $groupPublic = $existing->group_id === null ? null : TrainingGroup::whereKey($existing->group_id)->value('public_id');
                abort_unless($groupPublic === $r->validated('group_id'), 409);

                return $existing;
            }
            $group = $r->validated('group_id') === null ? null : TrainingGroup::where('organization_id', $org->id)->where('status', 'active')->where('public_id', $r->validated('group_id'))->firstOrFail();
            $a = new StudioAnnouncement;
            $a->forceFill($r->safe()->except('group_id') + ['organization_id' => $org->id, 'group_id' => $group?->id, 'created_by' => $r->user()?->id, 'upper_member_id' => StudioMember::where('organization_id', $org->id)->max('id') ?? 0, 'expires_at' => now()->addDays(30)])->save();
            Audit::record($org, MemberAccountController::actor($r), 'notification.announcement_created', 'organization', $org->public_id, ['announcement_id' => $a->public_id, 'scope' => $group === null ? 'all' : 'group', 'group_id' => $group?->public_id]);

            return $a;
        });

        return response()->json(['data' => ['id' => $a->public_id, 'state' => $a->state ?? 'pending']], 202);
    }

    public function index(Request $r): JsonResponse
    {
        $this->authorize($r);
        $r->validate(['page' => 'sometimes|integer|min:1|max:10000']);
        $page = StudioAnnouncement::where('organization_id', $this->context->organization()->id)->select('studio_announcements.*')->selectSub(DB::table('platform_notifications')->selectRaw('count(*)')->whereColumn('announcement_id', 'studio_announcements.id'), 'recipient_count')->orderByDesc('id')->paginate(25);
        $deliveryCounts = DB::table('notification_deliveries as d')->join('platform_notifications as n', 'n.id', '=', 'd.platform_notification_id')->where('n.organization_id', $this->context->organization()->id)->whereIn('n.announcement_id', $page->getCollection()->pluck('id'))->selectRaw('n.announcement_id, d.channel, d.state, count(*) AS total')->groupBy('n.announcement_id', 'd.channel', 'd.state')->get()->groupBy('announcement_id');

        return response()->json(['data' => $page->getCollection()->map(fn ($a) => ['id' => $a->public_id, 'state' => $a->state, 'title_mk' => $a->getAttribute('title_mk'), 'title_en' => $a->getAttribute('title_en'), 'created_at' => $a->getAttribute('created_at'), 'expires_at' => $a->expires_at, 'recipients' => $a->getAttribute('recipient_count'), 'deliveries' => ($deliveryCounts[$a->id] ?? collect())->map(fn ($d) => ['channel' => $d->channel, 'state' => $d->state, 'count' => $d->total])]), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
    }

    public function cancel(Request $r, string $organization, string $announcement): JsonResponse
    {
        abort_unless($r->all() === [], 422);
        $this->authorize($r);
        Lifecycle::run($this->context, MemberAccountController::actor($r), Permission::OrganizationsManage, function () use ($r, $announcement) {
            $this->authorize($r);
            $org = $this->context->organization();
            $a = StudioAnnouncement::where('organization_id', $org->id)->where('public_id', $announcement)->firstOrFail();
            if ($a->state !== 'cancelled') {
                $a->forceFill(['state' => 'cancelled', 'cancelled_at' => now()])->save();
                Audit::record($org, MemberAccountController::actor($r), 'notification.announcement_cancelled', 'organization', $org->public_id, ['announcement_id' => $a->public_id]);
            }
        });

        return response()->json(['data' => ['cancelled' => true]]);
    }
}

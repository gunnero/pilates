<?php

namespace App\Http\Controllers;

use App\Http\Requests\MemberActivationRequest;
use App\Http\Requests\MemberInvitationRequest;
use App\Http\Requests\ReservationListRequest;
use App\Models\MemberInvitation;
use App\Models\StudioMember;
use App\Models\User;
use App\Support\MemberDirectory;
use App\Support\Permission;
use App\Support\SelfService\Invitations;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class MemberInvitationController
{
    private function actor(Request $r): User
    {
        $u = $r->user();
        abort_unless($u instanceof User, 401);

        return $u;
    }

    public function store(MemberInvitationRequest $r, TenantContext $c, string $organization, string $member): JsonResponse
    {
        return response()->json(['data' => Invitations::issue($c, $this->actor($r), $member, $r->validated('email'))], 201);
    }

    public function index(ReservationListRequest $r, TenantContext $c, string $organization, string $member): JsonResponse
    {
        $u = $this->actor($r);
        Gate::forUser($u)->authorize(Permission::MembersUpdate->value);
        Gate::forUser($u)->authorize(Permission::MembersStatusManage->value);
        MemberDirectory::authorize($c, $u);
        $m = StudioMember::where('organization_id', $c->organization()->id)->where('public_id', $member)->firstOrFail();
        $p = MemberInvitation::where('organization_id', $c->organization()->id)->where('studio_member_id', $m->id)->orderByDesc('id')->paginate($r->validated('per_page', 25));

        return response()->json(['data' => $p->getCollection()->map(fn ($i) => ['id' => $i->public_id, 'status' => $i->status === 'pending' && $i->expires_at->isPast() ? 'expired' : $i->status, 'expires_at' => $i->expires_at->toISOString()]), 'linked' => $m->user_id !== null, 'meta' => ['current_page' => $p->currentPage(), 'last_page' => $p->lastPage()]]);
    }

    public function revoke(Request $r, TenantContext $c, string $organization, string $invitation): JsonResponse
    {
        abort_unless($r->all() === [], 422);
        Invitations::revoke($c, $this->actor($r), $invitation);

        return response()->json(['data' => ['revoked' => true]]);
    }

    public function activate(MemberActivationRequest $r): JsonResponse
    {
        Invitations::activate($r->validated());

        return response()->json(['message' => __('selfservice.activated')]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Http\Requests\NotificationListRequest;
use App\Http\Requests\NotificationPreferenceRequest;
use App\Http\Requests\NotificationReadRequest;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\PlatformNotification;
use App\Models\StudioMember;
use App\Models\User;
use App\Support\Audit;
use App\Support\Notifications\Eligibility;
use App\Support\Notifications\RecipientAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class NotificationController
{
    private function actor(Request $r): User
    {
        $u = $r->user();
        abort_unless($u instanceof User, 401);

        return $u;
    }

    public function contexts(NotificationListRequest $r): JsonResponse
    {
        $u = $this->actor($r);
        RecipientAccess::ability($u);
        $q = Organization::query()->where('status', 'active')->where(function ($q) use ($u) {
            $q->whereIn('id', StudioMember::query()->where('user_id', $u->id)->where('status', 'active')->select('organization_id'));
            if ($u->currentAccessToken() === null || $u->tokenCan('studio.access')) {
                $q->orWhereIn('id', OrganizationMembership::query()->where('user_id', $u->id)->where('status', 'active')->whereJsonContains('permissions', 'studio.access')->select('organization_id'));
            }
        });
        $page = $q->orderBy('id')->paginate($r->validated('per_page', 25));

        return response()->json(['data' => $page->getCollection()->map(fn ($o) => ['id' => $o->public_id, 'name' => $o->name]), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
    }

    public function index(NotificationListRequest $r, string $organization): JsonResponse
    {
        $scope = RecipientAccess::resolve($this->actor($r), $organization);
        $q = $scope->query();
        $unread = (clone $q)->whereNotExists(fn ($q) => $q->selectRaw('1')->from('notification_reads')->whereColumn('platform_notification_id', 'platform_notifications.id'))->count();
        if ($r->validated('unread') === '1') {
            $q->whereNotExists(fn ($q) => $q->selectRaw('1')->from('notification_reads')->whereColumn('platform_notification_id', 'platform_notifications.id'));
        }
        $q->select('platform_notifications.*')->selectSub(DB::table('notification_reads')->select('read_at')->whereColumn('platform_notification_id', 'platform_notifications.id'), 'read_at');
        $q->with(['organization', 'member', 'staff.locations', 'session.group', 'session.location', 'session.trainer.membership.locations', 'reservation', 'membership', 'cash.reversal', 'attendance']);
        $preferences = DB::table('notification_preferences')->where('organization_id', $scope->organization->id)->whereIn('recipient_key', array_filter([$scope->member === null ? null : 'member:'.$scope->member->id, $scope->staff === null ? null : 'staff:'.$scope->staff->id]))->get()->mapWithKeys(fn ($p) => [$p->recipient_key => ['reminders_enabled' => $p->reminders_enabled, 'expiry_reminders_enabled' => $p->expiry_reminders_enabled]])->all();
        $page = $q->orderByDesc('id')->paginate($r->validated('per_page', 25));

        return response()->json(['data' => $page->getCollection()->map(fn ($n) => $this->represent($n, $preferences)), 'unread_count' => $unread, 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'per_page' => $page->perPage()]]);
    }

    public function show(Request $r, string $organization, string $notification): JsonResponse
    {
        $n = RecipientAccess::resolve($this->actor($r), $organization)->query()->where('public_id', $notification)->firstOrFail();
        $n->setAttribute('read_at', DB::table('notification_reads')->where('platform_notification_id', $n->id)->value('read_at'));

        return response()->json(['data' => $this->represent($n)]);
    }

    public function read(NotificationReadRequest $r, string $organization, ?string $notification = null): JsonResponse
    {
        $u = $this->actor($r);
        $ids = $notification === null ? $r->validated('ids') : [$notification];
        $count = DB::transaction(function () use ($u, $organization, $ids) {
            Organization::query()->where('public_id', $organization)->lockForUpdate()->firstOrFail();
            $scope = RecipientAccess::resolve($u, $organization, true);
            $rows = $scope->query()->whereIn('public_id', $ids)->get();
            abort_unless(count($ids) === $rows->count(), 404);
            $count = 0;
            foreach ($rows as $n) {
                $count += DB::table('notification_reads')->insertOrIgnore(['organization_id' => $scope->organization->id, 'platform_notification_id' => $n->id, 'read_at' => now()]);
            }

            return $count;
        }, 3);

        return response()->json(['data' => ['marked_read' => $count]]);
    }

    public function preferences(Request $r, string $organization): JsonResponse
    {
        $scope = RecipientAccess::resolve($this->actor($r), $organization);
        $p = DB::table('notification_preferences')->where($scope->preferenceIdentity())->first();

        return response()->json(['data' => ['reminders_enabled' => $p->reminders_enabled ?? true, 'expiry_reminders_enabled' => $p->expiry_reminders_enabled ?? true]]);
    }

    public function savePreferences(NotificationPreferenceRequest $r, string $organization): JsonResponse
    {
        $u = $this->actor($r);
        DB::transaction(function () use ($r, $u, $organization) {
            Organization::query()->where('public_id', $organization)->lockForUpdate()->firstOrFail();
            $scope = RecipientAccess::resolve($u, $organization, true);
            $identity = $scope->preferenceIdentity();
            $p = DB::table('notification_preferences')->where($identity)->first();
            $before = ['reminders_enabled' => $p->reminders_enabled ?? true, 'expiry_reminders_enabled' => $p->expiry_reminders_enabled ?? true];
            $after = $r->validated();
            if ($before === $after) {
                return;
            }
            $id = $p->public_id ?? (string) Str::uuid7();
            if ($p === null) {
                DB::table('notification_preferences')->insert($identity + $after + ['public_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
            } else {
                DB::table('notification_preferences')->where('id', $p->id)->update($after + ['updated_at' => now()]);
            }
            Audit::record($scope->organization, $u, 'notification.preferences_changed', 'notification_preference', $id, ['before' => $before, 'after' => $after]);
        }, 3);

        return $this->preferences($r, $organization);
    }

    /**
     * @param  array<string,array<string,bool>>|null  $preferences
     * @return array<string,mixed>
     */
    private function represent(PlatformNotification $n, ?array $preferences = null): array
    {
        $locale = app()->getLocale() === 'en' ? 'en' : 'mk';

        return ['id' => $n->public_id, 'type' => $n->type, 'title' => $n->getAttribute('title_'.$locale), 'message' => $n->getAttribute('message_'.$locale), 'metadata' => $n->metadata, 'created_at' => $n->created_at->toISOString(), 'read_at' => $n->getAttribute('read_at'), 'channel' => 'in_app', 'delivery_state' => 'delivered', 'current' => Eligibility::reason($n, $preferences) === null];
    }
}

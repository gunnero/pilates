<?php

namespace App\Support;

use App\Models\Location;
use App\Models\TrainerProfile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

final class TrainerEligibility
{
    /** Fresh database predicates, with no duplicated trainer-location assignments.
     * @param  list<int>  $profileIds
     * @return Builder<Location>
     */
    private static function query(TenantContext $context, array $profileIds): Builder
    {
        return $context->locations()->where('locations.status', 'active')
            ->join('organization_memberships as teaching_staff', function (JoinClause $join): void {
                $join->on('teaching_staff.organization_id', '=', 'locations.organization_id')
                    ->where('teaching_staff.status', '=', 'active')
                    ->whereRaw("(teaching_staff.location_scope = 'all' OR EXISTS (SELECT 1 FROM membership_locations ml WHERE ml.organization_id = locations.organization_id AND ml.organization_membership_id = teaching_staff.id AND ml.location_id = locations.id))");
            })
            ->join('trainer_profiles as teaching_profile', 'teaching_profile.organization_membership_id', '=', 'teaching_staff.id')
            ->join('organizations as teaching_org', 'teaching_org.id', '=', 'locations.organization_id')
            ->where('teaching_org.status', 'active')->where('teaching_profile.status', 'active')
            ->whereIn('teaching_profile.id', $profileIds);
    }

    /** @return Builder<Location> */
    public static function locations(TenantContext $context, TrainerProfile $profile): Builder
    {
        return self::query($context, [$profile->id])->select('locations.*');
    }

    /** At most five visible eligible locations per profile; one batched query.
     * @param  Collection<int, TrainerProfile>  $profiles
     * @return array<int, array{count: int, locations: list<array{id: string, name: string}>}>
     */
    public static function summaries(TenantContext $context, Collection $profiles): array
    {
        if ($profiles->isEmpty()) {
            return [];
        }
        $ids = [];
        foreach ($profiles as $profile) {
            $ids[] = $profile->id;
        }
        $rows = self::query($context, $ids)->selectRaw('teaching_profile.id AS profile_id, locations.public_id, locations.name, count(*) OVER (PARTITION BY teaching_profile.id) AS total, row_number() OVER (PARTITION BY teaching_profile.id ORDER BY locations.id) AS position');
        $result = [];
        foreach (DB::query()->fromSub($rows->toBase(), 'eligible')->where('position', '<=', 5)->orderBy('profile_id')->orderBy('position')->get() as $row) {
            $result[$row->profile_id]['count'] = (int) $row->total;
            $result[$row->profile_id]['locations'][] = ['id' => $row->public_id, 'name' => $row->name];
        }

        return $result;
    }
}

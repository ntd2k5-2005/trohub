<?php

namespace App\Modules\Roommate;

use App\Modules\DataPlatform\Adapters\Neo4jProjection;
use App\Modules\Roommate\Models\RoommateNeed;
use Throwable;

final class RecommendationRepository
{
    public function candidates(RoommateNeed $need): array
    {
        if (config('data_stores.neo4j.enabled')) {
            try {
                $ids = app(Neo4jProjection::class)->compatible($need->id);
            } catch (Throwable $e) {
                return ['available' => false, 'ids' => []];
            }

            return ['available' => true, 'ids' => RoommateNeed::cloudAccessible()->whereIn('id', $ids)->where('district', $need->district)->whereBetween('budget', [$need->budget - config('business.roommate_budget_tolerance', 1000000), $need->budget + config('business.roommate_budget_tolerance', 1000000)])->where('status', 'open')->where('expires_at', '>', now())->where('user_id', '!=', $need->user_id)->pluck('id')->all()];
        }

        return ['available' => true, 'ids' => RoommateNeed::cloudAccessible()->where('district', $need->district)->where('user_id', '!=', $need->user_id)->where('status', 'open')->where('expires_at', '>', today())->whereBetween('budget', [$need->budget - config('business.roommate_budget_tolerance', 1000000), $need->budget + config('business.roommate_budget_tolerance', 1000000)])->limit(20)->pluck('id')->all()];
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\MatchSearchRequest;
use App\Models\BuyerDemand;
use App\Services\MatchingEngineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MatchingController extends Controller
{
    public function __construct(private readonly MatchingEngineService $matcher)
    {
    }

    /**
     * POST /api/v1/matching/search — ad-hoc "find me supply" query.
     */
    public function search(MatchSearchRequest $request): JsonResponse
    {
        $data = $request->validated();
        $limit = (int) ($data['limit'] ?? 10);
        unset($data['limit']);

        return response()->json([
            'data' => $this->matcher->match($data, $request->user()?->id, $limit),
        ]);
    }

    /**
     * GET /api/v1/buyer-demands/{buyerDemand}/matches — ranked supply for a saved demand.
     */
    public function forDemand(Request $request, BuyerDemand $buyerDemand): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isAdmin() || $buyerDemand->buyer_id === $user->id, 403);

        return response()->json([
            'data' => $this->matcher->matchDemand(
                $buyerDemand,
                (int) min(50, max(1, $request->integer('limit', 10))),
                $request->boolean('include_substitutes'),
            ),
        ]);
    }
}

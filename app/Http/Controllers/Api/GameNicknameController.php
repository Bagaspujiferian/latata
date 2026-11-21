<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Services\GameNicknameService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GameNicknameController extends Controller
{
    public function __construct(
        private readonly GameNicknameService $nicknameService,
    ) {}

    public function verify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'game_slug' => ['required', 'string', 'exists:games,slug'],
            'inputs' => ['required', 'array'],
        ]);

        $game = Game::where('slug', $validated['game_slug'])->firstOrFail();
        $result = $this->nicknameService->verify($game, $validated['inputs']);

        return response()->json($result, $result['valid'] ? 200 : 422);
    }
}

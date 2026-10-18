<?php

namespace App\Http\Controllers;

use App\ArcadeSessions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ArcadePlayController extends Controller
{
    public function __construct(private ArcadeSessions $sessions) {}

    public function start(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['assignment_id' => ['nullable', 'integer']]);

        return response()->json($this->sessions->start($id, false, $data['assignment_id'] ?? null));
    }

    public function show(int $id): JsonResponse
    {
        return response()->json($this->sessions->view($this->sessions->session($id)));
    }

    public function action(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['event_key' => ['required', 'string', 'regex:/^[a-zA-Z0-9_-]{8,80}$/'], 'revision' => ['required', 'integer', 'min:0'], 'action' => ['required', 'array:kind,activity_id,item_id,card_id,left_id,right_id,answer,lane,spell_id', 'max:8'], 'action.kind' => ['required', 'in:enter,answer,skip,match,flip,resolve,hint,timeout,defense_sync,defense_pause,defense_resume,defense_deploy,defense_upgrade,defense_ability'], 'action.activity_id' => ['required', 'string', 'max:64'], 'action.lane' => ['sometimes', 'integer', 'between:0,2'], 'action.spell_id' => ['sometimes', 'string', 'max:64'], 'action.item_id' => ['sometimes', 'string', 'max:64'], 'action.card_id' => ['sometimes', 'string', 'max:64'], 'action.left_id' => ['sometimes', 'string', 'max:64'], 'action.right_id' => ['sometimes', 'string', 'max:64']]);

        return response()->json($this->sessions->action($id, $data['event_key'], $data['revision'], $request->input('action')));
    }

    public function draft(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['activity_id' => ['required', 'string', 'max:64'], 'item_id' => ['required', 'string', 'max:64'], 'answer' => ['present']]);

        return response()->json($this->sessions->saveDraft($id, $data['activity_id'], $data['item_id'], $data['answer']));
    }
}

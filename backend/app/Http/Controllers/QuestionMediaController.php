<?php

namespace App\Http\Controllers;

use App\LearningAccess;
use App\QuestionMedia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class QuestionMediaController extends Controller
{
    public function __construct(private LearningAccess $access, private QuestionMedia $media) {}

    public function store(Request $request): JsonResponse
    {
        $this->access->requireRole('teacher', 'admin');
        $data = $request->validate(['file' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'extensions:png,jpg,jpeg,webp', 'max:5120', 'dimensions:min_width=16,min_height=16,max_width=4096,max_height=4096']]);
        $file = $data['file'];
        [$width, $height] = getimagesize($file->getRealPath());
        $attachment = $this->access->storeFile($file, 'question-media', []);
        $id = $this->access->table('question_media')->insertGetId(['owner_id' => $this->access->user()->id, 'attachment_id' => $attachment, 'width' => $width, 'height' => $height, 'created_at' => now(), 'updated_at' => now()]);
        $this->access->audit('uploaded', 'question_media', $id);

        return response()->json(['id' => $id, 'width' => $width, 'height' => $height], 201);
    }

    public function show(int $id): StreamedResponse
    {
        $media = $this->media->readable($id);
        $file = $this->access->table('attachments')->find($media->attachment_id);
        abort_unless($file && $file->path, 404);

        return Storage::disk($file->disk)->response($file->path, $file->name, ['Content-Type' => $file->mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store'], 'inline');
    }
}

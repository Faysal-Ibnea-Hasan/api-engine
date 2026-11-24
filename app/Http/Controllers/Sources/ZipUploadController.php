<?php

namespace App\Http\Controllers\Sources;

use App\Domain\Sources\ChunkedUploads;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sources\StartUploadRequest;
use App\Http\Requests\Sources\UploadSessionRequest;
use App\Models\Project;
use App\Models\UploadSession;
use Illuminate\Http\JsonResponse;

/** JSON endpoints for the resumable uploader. The browser sends the archive in pieces. */
class ZipUploadController extends Controller
{
    public function store(StartUploadRequest $request, Project $project, ChunkedUploads $uploads): JsonResponse
    {
        $session = $uploads->start($request->user(), $project, $request->integer('total_bytes'));

        return response()->json(self::present($session), 201);
    }

    public function show(UploadSessionRequest $request, Project $project, UploadSession $upload): JsonResponse
    {
        return response()->json(self::present($request->session()));
    }

    public function chunk(UploadSessionRequest $request, Project $project, UploadSession $upload, ChunkedUploads $uploads): JsonResponse
    {
        $body = $request->getContent(asResource: true);

        return response()->json(self::present($uploads->append($request->session(), $request->integer('offset'), $body)));
    }

    public function complete(UploadSessionRequest $request, Project $project, UploadSession $upload, ChunkedUploads $uploads): JsonResponse
    {
        $result = $uploads->complete($request->user(), $request->session());

        return response()->json(['runId' => $result->runId, 'alreadyRunning' => $result->alreadyRunning]);
    }

    /** @return array<string, mixed> no paths or storage details */
    private static function present(UploadSession $session): array
    {
        return [
            'id' => $session->id,
            'status' => $session->status,
            'totalBytes' => $session->total_bytes,
            'receivedBytes' => $session->received_bytes,
            'chunkBytes' => (int) config('sources.zip.chunk_bytes'),
            'expiresAt' => $session->expires_at->toIso8601String(),
        ];
    }
}

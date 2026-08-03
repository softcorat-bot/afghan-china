<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Attachment;
use App\Support\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    /**
     * Whitelist of attachable types. Keeps the polymorphic endpoint from being
     * pointed at arbitrary models. alias => Eloquent model class.
     *
     * @var array<string, class-string<Model>>
     */
    private const TYPES = [
        'user' => \App\Models\User::class,
        'company' => \App\Models\Company::class,
        'branch' => \App\Models\Branch::class,
        'product' => \App\Models\Product::class,
    ];

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'string'],
            'id' => ['required', 'integer'],
            'kind' => ['nullable', 'string'],
        ]);

        $parent = $this->resolveParent($data['type'], (int) $data['id']);

        $attachments = $parent->attachments()
            ->when(! empty($data['kind']), fn ($q) => $q->where('kind', $data['kind']))
            ->with('uploader:id,name')
            ->get()
            ->map(fn (Attachment $a) => $this->present($a));

        return response()->json($attachments);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'string'],
            'id' => ['required', 'integer'],
            'kind' => ['nullable', 'string', 'max:40'],
            'caption' => ['nullable', 'string', 'max:255'],
            'file' => ['required', 'file', 'max:25600'], // 25 MB — the file is stored exactly as uploaded
        ]);

        $parent = $this->resolveParent($data['type'], (int) $data['id']);
        $kind = $data['kind'] ?? 'file';

        [$path, $mime, $size] = $this->storeFile(
            $request->file('file'),
            'attachments/'.Tenant::id().'/'.$data['type'].'/'.$parent->getKey()
        );

        // Avatars are 1:1 — replace any previous profile photo.
        if ($kind === 'avatar') {
            foreach ($parent->attachments()->where('kind', 'avatar')->get() as $old) {
                Storage::disk($old->disk)->delete($old->path);
                $old->forceDelete();
            }
        }

        $attachment = $parent->attachments()->create([
            'company_id' => Tenant::id(),
            'kind' => $kind,
            'disk' => 'local',
            'path' => $path,
            'original_name' => $request->file('file')->getClientOriginalName(),
            'mime' => $mime,
            'size' => $size,
            'caption' => $data['caption'] ?? null,
            'uploaded_by' => $request->user()?->id,
        ]);

        ActivityLog::log('created', 'Attachment', "Attached \"{$attachment->original_name}\" to {$data['type']} #{$parent->getKey()}");

        return response()->json($this->present($attachment->load('uploader:id,name')), 201);
    }

    public function view(Attachment $attachment): StreamedResponse
    {
        abort_unless(Storage::disk($attachment->disk)->exists($attachment->path), 404);

        return Storage::disk($attachment->disk)->response(
            $attachment->path,
            $attachment->original_name,
            ['Content-Type' => $attachment->mime ?: 'application/octet-stream']
        );
    }

    public function destroy(Attachment $attachment): JsonResponse
    {
        $name = $attachment->original_name;
        $attachment->delete(); // soft delete; file retained for restore

        ActivityLog::log('deleted', 'Attachment', "Removed attachment \"{$name}\"");

        return response()->json(['message' => 'Deleted.']);
    }

    /** Resolve + authorize the parent record (company scope enforced by its global scope). */
    private function resolveParent(string $type, int $id): Model
    {
        abort_unless(isset(self::TYPES[$type]), 422, 'Unsupported attachment type.');

        /** @var class-string<Model> $class */
        $class = self::TYPES[$type];

        return $class::findOrFail($id);
    }

    private function present(Attachment $a): array
    {
        return [
            'id' => $a->id,
            'kind' => $a->kind,
            'original_name' => $a->original_name,
            'mime' => $a->mime,
            'size' => $a->size,
            'caption' => $a->caption,
            'is_image' => $a->is_image,
            'url' => "/api/attachments/{$a->id}/view",
            'uploaded_by' => $a->uploader?->name,
            'created_at' => $a->created_at,
        ];
    }

    /**
     * Store a file exactly as uploaded — no re-encoding, no resizing.
     *
     * @return array{0:string,1:string,2:int}
     */
    private function storeFile(UploadedFile $file, string $dir): array
    {
        $path = $file->store($dir);

        return [$path, $file->getClientMimeType(), (int) $file->getSize()];
    }
}

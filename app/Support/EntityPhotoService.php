<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** Единая загрузка фото для проектов, задач, документов, курсов и заданий LMS. */
class EntityPhotoService
{
    /** @return array<int, string> */
    public function rules(): array
    {
        return ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120', 'dimensions:max_width=4096,max_height=4096'];
    }

    public function sync(Model $entity, Request $request, string $folder): void
    {
        if ($request->boolean('reset_photo')) {
            $this->delete($entity->photo_path ?? null);
            $entity->forceFill(['photo_path' => null])->saveQuietly();

            return;
        }

        if (! $request->hasFile('photo')) {
            return;
        }

        $file = $request->file('photo');
        if ($file === null || ! $file->isValid()) {
            return;
        }

        $path = $file->store('entity-photos/'.$folder, 'public');
        if ($path === false) {
            return;
        }

        $this->delete($entity->photo_path ?? null);
        $entity->forceFill(['photo_path' => $path])->saveQuietly();
    }

    public function delete(?string $path): void
    {
        if ($path !== null && $path !== '' && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }

    public function url(?string $path): ?string
    {
        return ($path === null || $path === '') ? null : Storage::disk('public')->url($path);
    }
}

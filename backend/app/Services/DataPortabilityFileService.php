<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DataPortabilityFileService
{
    /**
     * @return array<int, array{filename: string, path: string, size_bytes: int, last_modified: string|null}>
     */
    public function listBackupFiles(): array
    {
        $disk = config('data-portability.storage.disk');
        $backupPath = $this->backupPath();
        $storage = Storage::disk($disk);

        $files = $storage->files($backupPath);
        sort($files);

        $result = [];
        foreach ($files as $file) {
            $result[] = [
                'filename' => basename($file),
                'path' => $file,
                'size_bytes' => $storage->size($file),
                'last_modified' => $storage->lastModified($file) ? date(DATE_ATOM, $storage->lastModified($file)) : null,
            ];
        }

        return $result;
    }

    public function storeUploadedFile(UploadedFile $file): string
    {
        $disk = config('data-portability.storage.disk');
        $uploadPath = $this->uploadPath();
        $extension = strtolower($file->getClientOriginalExtension() ?: 'json');

        $allowed = ['json'];
        if (! in_array($extension, $allowed, true)) {
            throw new \InvalidArgumentException('Only .json backup files are supported at this stage.');
        }

        $storedName = sprintf(
            'upload-%s-%s.%s',
            now()->format('Ymd_His'),
            Str::random(8),
            $extension
        );

        return $file->storeAs($uploadPath, $storedName, $disk);
    }

    public function downloadBackupFile(string $filename): StreamedResponse
    {
        $disk = config('data-portability.storage.disk');
        $safeName = $this->sanitizeFilename($filename);
        $path = $this->backupPath() . '/' . $safeName;
        $storage = Storage::disk($disk);

        if (! $storage->exists($path)) {
            abort(404, 'Backup file not found.');
        }

        $stream = $storage->readStream($path);
        if ($stream === false) {
            abort(500, 'Unable to read backup file.');
        }

        return response()->streamDownload(function () use ($stream) {
            fpassthru($stream);
            fclose($stream);
        }, $safeName, [
            'Content-Type' => 'application/json',
        ]);
    }

    private function sanitizeFilename(string $filename): string
    {
        $base = basename($filename);

        if (! preg_match('/^[a-zA-Z0-9._-]+$/', $base)) {
            throw new \InvalidArgumentException('Invalid file name.');
        }

        return $base;
    }

    private function backupPath(): string
    {
        $basePath = trim((string) config('data-portability.storage.base_path', 'data-portability'), '/');
        $backupDir = trim((string) config('data-portability.storage.backup_dir', 'backups'), '/');

        return $basePath . '/' . $backupDir;
    }

    private function uploadPath(): string
    {
        $basePath = trim((string) config('data-portability.storage.base_path', 'data-portability'), '/');
        $uploadDir = trim((string) config('data-portability.storage.upload_dir', 'uploads'), '/');

        return $basePath . '/' . $uploadDir;
    }
}

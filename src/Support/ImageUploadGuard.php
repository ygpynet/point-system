<?php

declare(strict_types=1);

namespace Ramon\PointSystem\Support;

use Psr\Http\Message\UploadedFileInterface;
use Ramon\PointSystem\Exception\UploadValidationException;

/**
 * Shared validation pipeline for every decoration image upload.
 *
 * Gates, in order: PSR-7 upload error, size cap (null-safe for chunked
 * uploads without Content-Length), extension allowlist, magic-byte signature
 * on the actual stream (defeats polyglots named `.gif` that ship PHP), and a
 * finfo MIME re-check on the buffered bytes (fail-closed: when the fileinfo
 * extension is absent nothing is persisted).
 *
 * Callers persist only what this returns. Keeping the pipeline in one place
 * is deliberate: avatar and cover uploads used to carry duplicate copies and
 * any future fix (new format, tighter cap) risks landing in only one of them.
 */
final class ImageUploadGuard
{
    /**
     * @param string[] $allowedExt
     * @param array<string, string[]> $allowedMimes
     * @return array{ext: string, contents: string}
     * @throws UploadValidationException
     */
    public static function inspect(
        UploadedFileInterface $file,
        int $maxBytes,
        array $allowedExt,
        array $allowedMimes,
        string $allowedExtMessage,
    ): array {
        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new UploadValidationException('No image uploaded');
        }

        $size = $file->getSize();
        if ($size === null || $size <= 0 || $size > $maxBytes) {
            $mb = (int) round($maxBytes / 1_000_000);
            throw new UploadValidationException("File too large (max {$mb}MB)", 413);
        }

        $ext = strtolower(pathinfo((string) $file->getClientFilename(), PATHINFO_EXTENSION));
        if (! in_array($ext, $allowedExt, true)) {
            throw new UploadValidationException($allowedExtMessage);
        }

        $stream = $file->getStream();
        $stream->rewind();
        $head = (string) $stream->read(16);
        $stream->rewind();

        if (! self::signatureMatches($head, $ext)) {
            throw new UploadValidationException('File content does not match its extension');
        }

        $stream->rewind();
        $contents = (string) $stream->getContents();

        $detected = '';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $detected = (string) (finfo_buffer($finfo, $contents) ?: '');
                finfo_close($finfo);
            }
        }

        if (! in_array(strtolower($detected), $allowedMimes[$ext] ?? [], true)) {
            throw new UploadValidationException('File MIME does not match its extension');
        }

        return ['ext' => $ext, 'contents' => $contents];
    }

    public static function signatureMatches(string $head, string $ext): bool
    {
        if ($ext === 'png' || $ext === 'apng') {
            return str_starts_with($head, "\x89PNG\r\n\x1a\n");
        }
        if ($ext === 'gif') {
            return str_starts_with($head, 'GIF87a') || str_starts_with($head, 'GIF89a');
        }
        if ($ext === 'webp') {
            return str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP';
        }
        if ($ext === 'jpg' || $ext === 'jpeg') {
            return str_starts_with($head, "\xff\xd8\xff");
        }
        return false;
    }
}

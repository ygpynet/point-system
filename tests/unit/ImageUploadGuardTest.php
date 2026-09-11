<?php

declare(strict_types=1);

namespace Ramon\PointSystem\Tests\unit;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\UploadedFileInterface;
use Ramon\PointSystem\Exception\UploadValidationException;
use Ramon\PointSystem\Support\ImageUploadGuard;

class ImageUploadGuardTest extends TestCase
{
    private const PNG_BYTES_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private function pngBytes(): string
    {
        return (string) base64_decode(self::PNG_BYTES_1X1, true);
    }

    private function upload(string $bytes, string $filename, int $size): UploadedFileInterface
    {
        $stream = new \Laminas\Diactoros\Stream('php://temp', 'wb+');
        $stream->write($bytes);
        $stream->rewind();

        return new \Laminas\Diactoros\UploadedFile($stream, $size, UPLOAD_ERR_OK, $filename, 'image/png');
    }

    private function guardConfig(): array
    {
        return [
            4_000_000,
            ['png', 'gif', 'webp', 'apng'],
            [
                'png' => ['image/png'],
                'apng' => ['image/png', 'image/apng'],
                'gif' => ['image/gif'],
                'webp' => ['image/webp'],
            ],
            'Only PNG, GIF, WebP, APNG allowed',
        ];
    }

    public function test_accepts_valid_png(): void
    {
        $result = ImageUploadGuard::inspect(
            $this->upload($this->pngBytes(), 'avatar.png', strlen($this->pngBytes())),
            ...$this->guardConfig(),
        );

        $this->assertSame('png', $result['ext']);
        $this->assertSame($this->pngBytes(), $result['contents']);
    }

    public function test_rejects_upload_error(): void
    {
        $file = $this->createMock(UploadedFileInterface::class);
        $file->method('getError')->willReturn(UPLOAD_ERR_INI_SIZE);

        try {
            ImageUploadGuard::inspect($file, ...$this->guardConfig());
            $this->fail('Expected UploadValidationException');
        } catch (UploadValidationException $e) {
            $this->assertSame(422, $e->status);
            $this->assertSame('No image uploaded', $e->detail);
        }
    }

    public function test_rejects_null_size_chunked_upload(): void
    {
        $file = $this->createMock(UploadedFileInterface::class);
        $file->method('getError')->willReturn(UPLOAD_ERR_OK);
        $file->method('getSize')->willReturn(null);

        try {
            ImageUploadGuard::inspect($file, ...$this->guardConfig());
            $this->fail('Expected UploadValidationException');
        } catch (UploadValidationException $e) {
            $this->assertSame(413, $e->status);
            $this->assertSame('File too large (max 4MB)', $e->detail);
        }
    }

    public function test_rejects_oversize(): void
    {
        try {
            ImageUploadGuard::inspect(
                $this->upload($this->pngBytes(), 'avatar.png', 5_000_000),
                ...$this->guardConfig(),
            );
            $this->fail('Expected UploadValidationException');
        } catch (UploadValidationException $e) {
            $this->assertSame(413, $e->status);
        }
    }

    public function test_rejects_disallowed_extension(): void
    {
        try {
            ImageUploadGuard::inspect(
                $this->upload($this->pngBytes(), 'shell.php', strlen($this->pngBytes())),
                ...$this->guardConfig(),
            );
            $this->fail('Expected UploadValidationException');
        } catch (UploadValidationException $e) {
            $this->assertSame('Only PNG, GIF, WebP, APNG allowed', $e->detail);
        }
    }

    public function test_rejects_signature_mismatch_polyglot(): void
    {
        try {
            ImageUploadGuard::inspect(
                $this->upload("<?php exit(__FILE__);", 'payload.png', 20),
                ...$this->guardConfig(),
            );
            $this->fail('Expected UploadValidationException');
        } catch (UploadValidationException $e) {
            $this->assertSame('File content does not match its extension', $e->detail);
        }
    }

    public function test_fails_closed_when_mime_allowlist_is_empty(): void
    {
        [$max, $exts,, $message] = $this->guardConfig();

        try {
            ImageUploadGuard::inspect(
                $this->upload($this->pngBytes(), 'avatar.png', strlen($this->pngBytes())),
                $max,
                $exts,
                [],
                $message,
            );
            $this->fail('Expected UploadValidationException');
        } catch (UploadValidationException $e) {
            $this->assertSame('File MIME does not match its extension', $e->detail);
        }
    }

    public function test_signature_matches_known_formats(): void
    {
        $this->assertTrue(ImageUploadGuard::signatureMatches("\x89PNG\r\n\x1a\n", 'png'));
        $this->assertTrue(ImageUploadGuard::signatureMatches('GIF89a...', 'gif'));
        $this->assertTrue(ImageUploadGuard::signatureMatches('GIF87a...', 'gif'));
        $this->assertTrue(ImageUploadGuard::signatureMatches('RIFF1234WEBPVP8 ', 'webp'));
        $this->assertTrue(ImageUploadGuard::signatureMatches("\xff\xd8\xff\xe0", 'jpg'));
        $this->assertTrue(ImageUploadGuard::signatureMatches("\xff\xd8\xff\xe0", 'jpeg'));
        $this->assertFalse(ImageUploadGuard::signatureMatches('GIF89a...', 'png'));
        $this->assertFalse(ImageUploadGuard::signatureMatches('RIFF1234WEBPVP8 ', 'apng'));
        $this->assertFalse(ImageUploadGuard::signatureMatches($this->pngBytes(), 'svg'));
    }
}

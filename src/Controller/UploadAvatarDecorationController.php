<?php

declare(strict_types=1);

namespace Ramon\PointSystem\Controller;

use Flarum\Http\RequestUtil;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\PointSystem\Exception\UploadValidationException;
use Ramon\PointSystem\FeatureGate;
use Ramon\PointSystem\Model\AvatarDecoration;
use Ramon\PointSystem\Model\ShopClaim;
use Ramon\PointSystem\Support\ImageUploadGuard;

/**
 * POST /api/point-system/avatar-decoration/upload
 *
 * Two entry modes share this controller so the upload pipeline (size /
 * extension / magic-byte / finfo / flarum-assets disk write) only lives in
 * one place:
 *
 *   • Manager (pointSystem.manage permission) — full admin upload.
 *     Accepts `replace_id` to swap an existing decoration's file. New rows
 *     are created enabled (`is_enabled=true`, `status=approved`).
 *   • User submission — non-manager actor when `user_submissions_enabled`
 *     is on. `replace_id` is ignored; rows land as
 *     `is_enabled=false / status=pending / creator_id=actor / price=0` and
 *     wait for the moderation queue.
 *
 * Multipart fields:
 *   - image: file (png, gif, webp, apng)
 *   - name:  string
 *   - description: string (optional)
 *   - price: int (manager only; ignored for user submissions)
 *   - replace_id: int (manager only)
 */
class UploadAvatarDecorationController implements RequestHandlerInterface
{
    private const ALLOWED_EXT = ['png', 'gif', 'webp', 'apng'];
    private const MAX_BYTES   = 4_000_000; // 4MB
    private const DEST_DIR    = 'point-system/avatar-decorations';

    private const ALLOWED_MIMES = [
        'png'  => ['image/png'],
        'apng' => ['image/png', 'image/apng'],
        'gif'  => ['image/gif'],
        'webp' => ['image/webp'],
    ];

    public function __construct(
        protected FilesystemFactory $filesystem,
        protected FeatureGate $features,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();
        $this->features->assertEnabled(ShopClaim::TYPE_AVATAR);

        $isManager = $actor->hasPermission('pointSystem.manage');
        if (! $isManager) {
            // Non-manager path is gated behind the user-submissions toggle.
            // When the admin has it off, fall back to the original behavior:
            // upload requires the manage permission.
            $this->features->assertUserSubmissionsEnabled();
        }

        $files = $request->getUploadedFiles();
        $file  = $files['image'] ?? null;
        if (! $file instanceof UploadedFileInterface) {
            return new JsonResponse(['errors' => [['detail' => 'No image uploaded']]], 422);
        }

        try {
            ['ext' => $ext, 'contents' => $contents] = ImageUploadGuard::inspect(
                $file,
                self::MAX_BYTES,
                self::ALLOWED_EXT,
                self::ALLOWED_MIMES,
                'Only PNG, GIF, WebP, APNG allowed',
            );
        } catch (UploadValidationException $e) {
            return new JsonResponse(['errors' => [['detail' => $e->detail]]], $e->status);
        }

        $body = (array) $request->getParsedBody();
        // replace_id is a manager-only privilege: it swaps the file on an
        // existing row in place. Regular submitters can only create new
        // pending rows — anything else would let them tamper with an
        // already-approved decoration.
        $replaceId = $isManager && isset($body['replace_id']) ? (int) $body['replace_id'] : 0;

        // Persist through the flarum-assets disk (rooted at public/assets).
        // The Flysystem local adapter creates the subdirectory, applies
        // public (web-readable) visibility, and prefix-confines the path —
        // a relative path can never escape the assets root (CLAUDE.md §54).
        $disk     = $this->filesystem->disk('flarum-assets');
        $filename = bin2hex(random_bytes(8)).'.'.$ext;
        $relPath  = self::DEST_DIR.'/'.$filename;
        $disk->put($relPath, $contents, 'public');

        // Replace image on an existing decoration — only swap the file fields;
        // name/price/etc are managed via the JSON:API Update endpoint.
        if ($replaceId > 0) {
            $deco = AvatarDecoration::find($replaceId);
            if (! $deco) {
                $disk->delete($relPath);
                return new JsonResponse(['errors' => [['detail' => 'Decoration not found']]], 404);
            }
            // Drop the previous file. delete() is idempotent, and the local
            // adapter rejects a traversal path with an exception rather than
            // escaping the assets root — swallow it so a malformed legacy
            // image_path can never abort the swap.
            $oldPath = (string) $deco->image_path;
            if ($oldPath !== '' && $oldPath !== $relPath) {
                try {
                    $disk->delete($oldPath);
                } catch (\Throwable) {
                }
            }
            $deco->image_path = $relPath;
            $deco->is_animated = in_array($ext, ['gif', 'apng'], true);
            $deco->save();
        } else {
            $name = trim((string) ($body['name'] ?? 'Decoration'));
            $description = isset($body['description']) ? trim((string) $body['description']) : null;

            $attrs = [
                'name' => $name !== '' ? $name : 'Decoration',
                'description' => $description ?: null,
                'image_path' => $relPath,
                'is_animated' => in_array($ext, ['gif', 'apng'], true),
                'sort' => 0,
            ];

            if ($isManager) {
                $attrs['price']      = max(0, (int) ($body['price'] ?? 0));
                $attrs['is_enabled'] = true;
                $attrs['status']     = AvatarDecoration::STATUS_APPROVED;
            } else {
                // User submission: forced into the moderation queue. The
                // actor decides the name/description/image; admin-only
                // fields (price, listing, group restrictions, dates) are
                // server-derived and locked. Reviewer can flip is_enabled
                // once they approve from the admin queue.
                $attrs['price']      = 0;
                $attrs['is_enabled'] = false;
                $attrs['status']     = AvatarDecoration::STATUS_PENDING;
                $attrs['creator_id'] = (int) $actor->id;
            }

            $deco = AvatarDecoration::create($attrs);
        }

        return new JsonResponse(['data' => [
            'type' => 'point-system-avatar-decorations',
            'id' => (string) $deco->id,
            'attributes' => [
                'name' => $deco->name,
                'description' => $deco->description,
                'imagePath' => $deco->image_path,
                'isAnimated' => $deco->is_animated,
                'price' => $deco->price,
                'isEnabled' => $deco->is_enabled,
                'status' => $deco->status,
            ],
        ]], 201);
    }
}

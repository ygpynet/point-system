<?php

declare(strict_types=1);

namespace Ramon\PointSystem\Module;

use Flarum\Extend\ExtenderInterface;
use Flarum\Extend\Routes;
use Ramon\PointSystem\Controller\AcceptTradeController;
use Ramon\PointSystem\Controller\BulkAwardController;
use Ramon\PointSystem\Controller\CancelTradeController;
use Ramon\PointSystem\Controller\CheckInController;
use Ramon\PointSystem\Controller\ClaimItemController;
use Ramon\PointSystem\Controller\ClaimTierController;
use Ramon\PointSystem\Controller\DeleteAvatarDecorationController;
use Ramon\PointSystem\Controller\DeleteCoverDecorationController;
use Ramon\PointSystem\Controller\EquipDecorationController;
use Ramon\PointSystem\Controller\FinalizeTradeController;
use Ramon\PointSystem\Controller\GrantItemController;
use Ramon\PointSystem\Controller\ListAllTradesController;
use Ramon\PointSystem\Controller\ListPendingSubmissionsController;
use Ramon\PointSystem\Controller\ListTradesController;
use Ramon\PointSystem\Controller\ListTransactionsController;
use Ramon\PointSystem\Controller\ListUserTransactionsController;
use Ramon\PointSystem\Controller\MakeUpController;
use Ramon\PointSystem\Controller\ManualAwardController;
use Ramon\PointSystem\Controller\ModerateSubmissionController;
use Ramon\PointSystem\Controller\OpenTradeController;
use Ramon\PointSystem\Controller\RevertTradeController;
use Ramon\PointSystem\Controller\ShowTradeController;
use Ramon\PointSystem\Controller\TipPostController;
use Ramon\PointSystem\Controller\UnequipDecorationController;
use Ramon\PointSystem\Controller\UpdateTradeOfferController;
use Ramon\PointSystem\Controller\UploadAvatarDecorationController;
use Ramon\PointSystem\Controller\UploadCoverDecorationController;

/**
 * The `/api/point-system/*` surface: claiming, equipping, trading, check-ins,
 * manual/bulk awards, tips, the moderation queue and the admin trade dashboard.
 */
class ApiRoutesModule implements ModuleInterface
{
    public function extenders(): array
    {
        return [
            (new Routes('api'))
                ->post('/point-system/claim/{id}', 'pointSystem.claim', ClaimItemController::class)
                ->post('/point-system/tier-claim', 'pointSystem.tierClaim', ClaimTierController::class)
                ->post('/point-system/equip', 'pointSystem.equip', EquipDecorationController::class)
                ->post('/point-system/unequip', 'pointSystem.unequip', UnequipDecorationController::class)
                ->post('/point-system/avatar-decoration/upload', 'pointSystem.avatarDeco.upload', UploadAvatarDecorationController::class)
                ->delete('/point-system/avatar-decoration/{id}', 'pointSystem.avatarDeco.delete', DeleteAvatarDecorationController::class)
                ->post('/point-system/cover-decoration/upload', 'pointSystem.coverDeco.upload', UploadCoverDecorationController::class)
                ->delete('/point-system/cover-decoration/{id}', 'pointSystem.coverDeco.delete', DeleteCoverDecorationController::class)
                ->post('/point-system/award', 'pointSystem.award', ManualAwardController::class)
                ->post('/point-system/checkin', 'pointSystem.checkin', CheckInController::class)
                ->post('/point-system/checkin/makeup', 'pointSystem.checkin.makeup', MakeUpController::class)
                ->post('/point-system/bulk-award', 'pointSystem.bulkAward', BulkAwardController::class)
                ->post('/point-system/grant', 'pointSystem.grant', GrantItemController::class)
                ->post('/point-system/tip', 'pointSystem.tip', TipPostController::class)
                // ── Trades ──────────────────────────────────────────────────────
                ->get('/point-system/trades', 'pointSystem.trades.list', ListTradesController::class)
                ->post('/point-system/trades', 'pointSystem.trades.open', OpenTradeController::class)
                ->get('/point-system/trades/{id:[0-9]+}', 'pointSystem.trades.show', ShowTradeController::class)
                ->patch('/point-system/trades/{id:[0-9]+}', 'pointSystem.trades.update', UpdateTradeOfferController::class)
                ->post('/point-system/trades/{id:[0-9]+}/accept', 'pointSystem.trades.accept', AcceptTradeController::class)
                ->post('/point-system/trades/{id:[0-9]+}/finalize', 'pointSystem.trades.finalize', FinalizeTradeController::class)
                ->post('/point-system/trades/{id:[0-9]+}/cancel', 'pointSystem.trades.cancel', CancelTradeController::class)
                // ── User-submission moderation queue ──────────────────────────
                ->get('/point-system/submissions', 'pointSystem.submissions.list', ListPendingSubmissionsController::class)
                ->post('/point-system/submissions/{type}/{id:[0-9]+}/{action}', 'pointSystem.submissions.moderate', ModerateSubmissionController::class)
                // ── Admin trades dashboard ────────────────────────────────────
                ->get('/point-system/admin/trades', 'pointSystem.admin.trades.list', ListAllTradesController::class)
                ->post('/point-system/admin/trades/{id:[0-9]+}/revert', 'pointSystem.admin.trades.revert', RevertTradeController::class)
                // ── Points ledger (积分流水) ───────────────────────────────────
                ->get('/point-system/admin/transactions', 'pointSystem.admin.transactions.list', ListTransactionsController::class)
                ->get('/point-system/users/{id:[0-9]+}/transactions', 'pointSystem.user.transactions.list', ListUserTransactionsController::class),
        ];
    }
}

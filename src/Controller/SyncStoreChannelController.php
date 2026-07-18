<?php

namespace App\Controller;

use App\Entity\StoreChannel;
use App\Sync\StoreSyncService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Invoked by API Platform for POST /api/store_channels/{id}/sync (see the
 * custom Post operation declared on StoreChannel::class). API Platform
 * resolves {id} into a StoreChannel entity and passes it in via __invoke's
 * parameter, the same convention used for any custom operation controller.
 *
 * The `security: "is_granted('ROLE_ADMIN')"` declared on this operation in
 * StoreChannel::class is NOT relied upon here — API Platform's security
 * attribute has documented, version-dependent gaps with custom operations
 * that use a controller (see api-platform/core issues #1875, #3336, #6142:
 * "security works only on resource level not on operation level" in some
 * configurations). Verified empirically against this exact project: a
 * ROLE_MOBILE_APP token got 200 on this endpoint despite the operation-level
 * security attribute being set. Rather than depend on undocumented-reliable
 * behavior, the permission check is done explicitly here with Symfony's
 * Security service — the same mechanism access_control and Voters use
 * underneath, just invoked directly instead of through API Platform's
 * declarative layer.
 */
final class SyncStoreChannelController
{
    public function __construct(
        private readonly StoreSyncService $syncService,
        private readonly Security $security,
    ) {
    }

    public function __invoke(StoreChannel $data): JsonResponse
    {
        if (!$this->security->isGranted('ROLE_ADMIN')) {
            throw new AccessDeniedException('Only administrators can trigger a store sync.');
        }

        $result = $this->syncService->syncChannel($data);

        return new JsonResponse($result);
    }
}

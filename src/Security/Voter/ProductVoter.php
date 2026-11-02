<?php

namespace App\Security\Voter;

use App\Entity\Product;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\User\UserInterface;

final class ProductVoter extends Voter
{
    public const EDIT = 'PRODUCT_EDIT';
    public const VIEW = 'PRODUCT_VIEW';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::EDIT, self::VIEW])
            && $subject instanceof Product;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        if (!$user instanceof UserInterface) {
            $vote?->addReason('The user must be logged in to access this resource.');

            return false;
        }

        /** @var Product $product */
        $product = $subject;

        // ROLE_ADMIN can always view and edit, regardless of published state.
        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return true;
        }

        switch ($attribute) {
            case self::VIEW:
                // Non-admin roles (e.g. ROLE_MOBILE_APP) can only view published products.
                if ($product->isPublished()) {
                    return true;
                }
                $vote?->addReason('This product is not published.');

                return false;

            case self::EDIT:
                // Only ROLE_ADMIN can edit — already handled above, so anyone
                // reaching this point without that role is denied.
                $vote?->addReason('Only administrators can edit products.');

                return false;
        }

        return false;
    }
}
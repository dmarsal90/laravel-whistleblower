<?php

declare(strict_types=1);

namespace Whistleblower\Tests\Symfony;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/** Grants the management attribute to the user called "manager" only. */
final class ManagerVoter extends Voter
{
    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === 'WHISTLEBLOWER_MANAGE';
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        return $token->getUserIdentifier() === 'manager';
    }
}

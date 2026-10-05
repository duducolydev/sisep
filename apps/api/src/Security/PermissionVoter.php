<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * L'attribut voté est un code de permission (§ 7.2, ex. "activites.modifier").
 * Un garde `denyAccessUnlessGranted('activites.modifier')` vérifie que
 * l'utilisateur possède la permission ; si un sujet est fourni et implémente
 * PorteeParStructureInterface, la portée est vérifiée en plus — jamais un
 * contrôle après lecture, toujours avant d'agir sur l'entité (§ 7.2).
 *
 * @extends Voter<string, object|null>
 */
final class PermissionVoter extends Voter
{
    public function __construct(
        private readonly PermissionsResolver $permissionsResolver,
        private readonly EntityManagerInterface $em,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return str_contains($attribute, '.');
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $utilisateur = $token->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return false;
        }

        $permissions = $this->permissionsResolver->pour($utilisateur);

        if (null === $subject) {
            return $permissions->possede($attribute);
        }

        $structureId = $subject instanceof PorteeParStructureInterface
            ? $this->resoudreStructureId($subject)
            : null;

        return $permissions->estAutorisePour($attribute, $structureId);
    }

    /**
     * Résout la structure du sujet hors du filtre SQL `portee_activite`
     * (§ 7.2, PorteeActiviteFilter) : ce filtre restreint les *listes*
     * d'activités visibles pour un utilisateur scopé, mais ce vote décide
     * précisément si le sujet est dans son périmètre — s'il reste actif ici,
     * un point focal qui tente d'agir sur une sous-activité hors périmètre
     * fait échouer le chargement de l'activité parente (EntityNotFoundException,
     * 500) au lieu d'un refus propre (403). Le vote a besoin de la structure
     * réelle du sujet, indépendamment de ce que l'utilisateur a le droit de
     * lister.
     */
    private function resoudreStructureId(PorteeParStructureInterface $subject): ?int
    {
        $filtres = $this->em->getFilters();
        $etaitActif = $filtres->isEnabled('portee_activite');
        if ($etaitActif) {
            $filtres->disable('portee_activite');
        }

        try {
            return $subject->getStructurePourPortee()?->getId();
        } finally {
            if ($etaitActif) {
                $filtres->enable('portee_activite');
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Activite;
use App\Entity\SousActivite;
use App\Entity\Utilisateur;
use App\Http\ProblemResponseFactory;
use App\Repository\ActiviteRepository;
use App\Repository\SousActiviteRepository;
use App\Security\PermissionsResolver;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/** Petits utilitaires communs aux contrôleurs REST du § 6 du brief, pour éviter de les dupliquer entité par entité. */
trait ApiControllerHelpersTrait
{
    private function uuidOuNull(string $valeur): ?Uuid
    {
        try {
            return Uuid::fromString($valeur);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Portée non applicable à cette action : seule une attribution globale
     * de la permission suffit, jamais une attribution scopée à une
     * structure (§ 7.2). Utilisé pour composantes/sous-composantes, dont la
     * gestion reste hors de portée d'un point focal quelle que soit sa
     * structure.
     */
    private function exigerPermissionGlobale(Security $security, PermissionsResolver $permissionsResolver, string $code): void
    {
        $utilisateur = $security->getUser();
        if (!$utilisateur instanceof Utilisateur || !$permissionsResolver->pour($utilisateur)->estGlobale($code)) {
            throw new AccessDeniedException();
        }
    }

    /**
     * Portée-aware (§ 7.2) : contrairement à exigerPermissionGlobale(), une
     * attribution scopée à la structure du sujet suffit — utilisé pour les
     * entités où le référentiel de rôles § 7.2 autorise une portée non
     * globale (ex. points_focaux.gerer pour point_focal_structure).
     */
    /**
     * Identifiants d'une validation en lot : `{"<cle>": ["uuid", …]}`, 500 au
     * plus par appel.
     *
     * @return list<string>|JsonResponse
     */
    private function uuidsDuLot(Request $request, string $cle): array|JsonResponse
    {
        $corps = json_decode($request->getContent(), true);
        $uuids = \is_array($corps) ? ($corps[$cle] ?? null) : null;
        if (!\is_array($uuids) || [] === $uuids || \count($uuids) > 500) {
            return ProblemResponseFactory::creer(422, 'Données invalides', \sprintf('« %s » doit lister de 1 à 500 identifiants.', $cle));
        }

        return array_values(array_unique(array_filter($uuids, 'is_string')));
    }

    private function exigerPermission(Security $security, string $code, ?object $sujet = null): void
    {
        if (!$security->isGranted($code, $sujet)) {
            throw new AccessDeniedException();
        }
    }

    /**
     * Résout la cible d'une écriture budgétaire (§ 6.2, alignement PTBA
     * PHASAOC) : exactement l'une des deux UUID doit être fournie et
     * correspondre à une entité existante. Utilisé par Dotation/Engagement,
     * chiffrables au niveau activité ou sous-activité selon le PTBA réel.
     */
    private function resoudreCibleBudgetaire(
        ?string $activiteUuid,
        ?string $sousActiviteUuid,
        ActiviteRepository $activites,
        SousActiviteRepository $sousActivites,
    ): Activite|SousActivite|JsonResponse {
        $activiteFournie = null !== $activiteUuid && '' !== $activiteUuid;
        $sousActiviteFournie = null !== $sousActiviteUuid && '' !== $sousActiviteUuid;

        if ($activiteFournie === $sousActiviteFournie) {
            return ProblemResponseFactory::creer(422, 'Données invalides', 'Fournir soit une activité, soit une sous-activité, jamais les deux ni aucune.');
        }

        if ($activiteFournie) {
            $activite = $activites->findOneBy(['uuid' => $this->uuidOuNull($activiteUuid)]);

            return $activite ?? ProblemResponseFactory::creer(422, 'Activité introuvable');
        }

        $sousActivite = $sousActivites->findOneBy(['uuid' => $this->uuidOuNull($sousActiviteUuid)]);

        return $sousActivite ?? ProblemResponseFactory::creer(422, 'Sous-activité introuvable');
    }

    private function validerOuErreur(object $donnees, ValidatorInterface $validator): ?JsonResponse
    {
        $violations = $validator->validate($donnees);
        if (0 === \count($violations)) {
            return null;
        }

        $details = [];
        foreach ($violations as $violation) {
            $details[] = ['champ' => $violation->getPropertyPath(), 'message' => $violation->getMessage()];
        }

        return ProblemResponseFactory::creer(422, 'Données invalides', null, ['violations' => $details]);
    }
}

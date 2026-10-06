<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\CreerSousActiviteRequest;
use App\Entity\SousActivite;
use App\Enum\StatutActivite;
use App\Http\ProblemResponseFactory;
use App\Http\SousActiviteNormalizer;
use App\Repository\ActiviteRepository;
use App\Repository\SousActiviteRepository;
use App\Repository\StructureRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/v1/sous-activites')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class SousActiviteController
{
    use ApiControllerHelpersTrait;

    public function __construct(
        private readonly SousActiviteRepository $sousActivites,
        private readonly ActiviteRepository $activites,
        private readonly StructureRepository $structures,
        private readonly SousActiviteNormalizer $normalizer,
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly SerializerInterface $serializer,
        private readonly ValidatorInterface $validator,
    ) {
    }

    #[Route('/{uuid}', name: 'app_sous_activite_detail', methods: ['GET'])]
    public function detail(string $uuid): JsonResponse
    {
        $sousActivite = $this->trouver($uuid);

        if (!$this->security->isGranted('activites.consulter', $sousActivite)) {
            throw new AccessDeniedException();
        }

        return new JsonResponse($this->normalizer->normaliser($sousActivite));
    }

    #[Route('', name: 'app_sous_activite_creer', methods: ['POST'])]
    public function creer(Request $request): Response
    {
        /** @var CreerSousActiviteRequest $donnees */
        $donnees = $this->serializer->deserialize($request->getContent(), CreerSousActiviteRequest::class, 'json');
        if (($erreur = $this->validerOuErreur($donnees, $this->validator)) !== null) {
            return $erreur;
        }

        $activiteUuid = $this->uuidOuNull($donnees->activite);
        $activite = null !== $activiteUuid ? $this->activites->findOneBy(['uuid' => $activiteUuid]) : null;
        if (null === $activite) {
            return ProblemResponseFactory::creer(422, 'Activité introuvable');
        }

        $sousActivite = new SousActivite($activite, $donnees->libelle);
        $sousActivite->setOrdre($donnees->ordre);
        if (null !== $donnees->dateDebut) {
            $sousActivite->setDateDebut(new \DateTimeImmutable($donnees->dateDebut));
        }
        if (null !== $donnees->echeance) {
            $sousActivite->setEcheance(new \DateTimeImmutable($donnees->echeance));
        }
        if (null !== $donnees->structureResponsable) {
            $structureUuid = $this->uuidOuNull($donnees->structureResponsable);
            $structure = null !== $structureUuid ? $this->structures->findOneBy(['uuid' => $structureUuid]) : null;
            $sousActivite->setStructureResponsable($structure);
        }

        // Découpage réservé à l'UGP (§ 5 du CR du 22/09/2026 : « il revient
        // aux responsables du suivi et de l'évaluation de structurer les
        // activités »). activites.creer, contrairement à activites.modifier,
        // n'est détenue ni par le point focal de structure ni par aucun
        // bénéficiaire : la sous-activité n'est plus un outil de découpage
        // libre, seulement la maille budgétaire du PTBA. Le suivi
        // d'avancement passe désormais par les étapes (EtapeActivite).
        if (!$this->security->isGranted('activites.creer', $activite)) {
            throw new AccessDeniedException();
        }

        $this->em->persist($sousActivite);
        $this->em->flush();

        return new JsonResponse($this->normalizer->normaliser($sousActivite), 201);
    }

    #[Route('/{uuid}', name: 'app_sous_activite_modifier', methods: ['PATCH'])]
    public function modifier(string $uuid, Request $request): JsonResponse
    {
        $sousActivite = $this->trouver($uuid);

        if (!$this->security->isGranted('activites.modifier', $sousActivite)) {
            throw new AccessDeniedException();
        }

        /** @var array<string, mixed> $donnees */
        $donnees = json_decode($request->getContent(), true) ?? [];

        if (\array_key_exists('libelle', $donnees) && \is_string($donnees['libelle'])) {
            $sousActivite->setLibelle($donnees['libelle']);
        }
        if (\array_key_exists('echeance', $donnees) && \is_string($donnees['echeance'])) {
            $sousActivite->setEcheance(new \DateTimeImmutable($donnees['echeance']));
        }
        if (\array_key_exists('statut', $donnees) && \is_string($donnees['statut'])) {
            $statut = StatutActivite::tryFrom($donnees['statut']);
            if (null === $statut) {
                return new JsonResponse(['title' => 'Statut invalide', 'status' => 422], 422);
            }
            // Clôturer une activité est une validation, distincte de sa
            // modification courante (§ 7.2, même séparation que
            // budget.*.valider/indicateurs.valider) : quiconque saisit
            // l'avancement ne peut pas se l'auto-valider.
            if (StatutActivite::TERMINEE === $statut && !$this->security->isGranted('activites.valider', $sousActivite)) {
                throw new AccessDeniedException();
            }
            $sousActivite->setStatut($statut);
        }

        $this->em->flush();

        return new JsonResponse($this->normalizer->normaliser($sousActivite));
    }

    #[Route('/{uuid}', name: 'app_sous_activite_supprimer', methods: ['DELETE'])]
    public function supprimer(string $uuid): Response
    {
        $sousActivite = $this->trouver($uuid);

        if (!$this->security->isGranted('activites.supprimer', $sousActivite)) {
            throw new AccessDeniedException();
        }

        $sousActivite->supprimer();
        $this->em->flush();

        return new Response(status: 204);
    }

    /*
     * La saisie de réalisations a été retirée avec le passage au suivi par
     * étapes (§ 6 du CR du 22/09/2026) : l'avancement d'une activité
     * se déduit désormais de ses EtapeActivite, et la sous-activité n'est
     * plus qu'une maille budgétaire. Les réalisations déjà enregistrées sont
     * conservées telles quelles — ce sont des données historiques, dont la
     * suppression est interdite — mais aucune nouvelle ne peut être saisie.
     */

    private function trouver(string $uuid): SousActivite
    {
        $uuidObjet = $this->uuidOuNull($uuid);
        $sousActivite = null !== $uuidObjet ? $this->sousActivites->findOneBy(['uuid' => $uuidObjet]) : null;

        return $sousActivite ?? throw new NotFoundHttpException('Sous-activité introuvable.');
    }
}

<?php

declare(strict_types=1);

namespace App\Controller;

use App\Budget\DotationRevisionService;
use App\Dto\CreerDotationRequest;
use App\Dto\ReviserDotationRequest;
use App\Entity\Activite;
use App\Entity\Dotation;
use App\Entity\Utilisateur;
use App\Exception\ContrainteBudgetaireException;
use App\Http\DotationNormalizer;
use App\Http\ProblemResponseFactory;
use App\Repository\ActiviteRepository;
use App\Repository\DotationRepository;
use App\Repository\ExerciceRepository;
use App\Repository\SousActiviteRepository;
use App\ValueObject\Montant;
use Doctrine\DBAL\LockMode;
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

/**
 * Le brief ne définit pas de permission dédiée aux dotations (§ 7.2 ne liste
 * que budget.engagement.* et budget.depense.*) : la notification d'une
 * dotation est traitée comme le premier maillon de la chaîne budgétaire et
 * réutilise donc budget.engagement.saisir, avec la même portée par
 * structure que les autres écritures (§ 5.1, § 7.2) — jugée sur la cible
 * réelle (activité ou sous-activité), pas toujours l'activité (alignement
 * PTBA PHASAOC, § 6.2).
 */
#[Route('/api/v1/dotations')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class DotationController
{
    use ApiControllerHelpersTrait;

    public function __construct(
        private readonly DotationRepository $dotations,
        private readonly ActiviteRepository $activites,
        private readonly SousActiviteRepository $sousActivites,
        private readonly ExerciceRepository $exercices,
        private readonly DotationNormalizer $normalizer,
        private readonly DotationRevisionService $revision,
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly SerializerInterface $serializer,
        private readonly ValidatorInterface $validator,
    ) {
    }

    #[Route('', name: 'app_dotation_creer', methods: ['POST'])]
    public function creer(Request $request): Response
    {
        /** @var CreerDotationRequest $donnees */
        $donnees = $this->serializer->deserialize($request->getContent(), CreerDotationRequest::class, 'json');
        if (($erreur = $this->validerOuErreur($donnees, $this->validator)) !== null) {
            return $erreur;
        }

        $cible = $this->resoudreCibleBudgetaire($donnees->activite, $donnees->sousActivite, $this->activites, $this->sousActivites);
        if ($cible instanceof JsonResponse) {
            return $cible;
        }

        if (!$this->security->isGranted('budget.engagement.saisir', $cible)) {
            throw new AccessDeniedException();
        }

        $activiteParente = $cible instanceof Activite ? $cible : $cible->getActivite();
        $projet = $activiteParente->getSousComposante()->getComposante()->getProjet();
        $exercice = $this->exercices->trouverParAnnee($projet, (int) $donnees->exercice);
        if (null === $exercice) {
            return ProblemResponseFactory::creer(422, 'Exercice introuvable pour ce projet');
        }

        if (!$exercice->estOuvert()) {
            return ProblemResponseFactory::creer(422, "Cet exercice est clôturé : aucune écriture budgétaire n'est plus acceptée.");
        }

        if (null !== $this->dotations->trouverPourCibleExercice($cible, $exercice)) {
            throw new ContrainteBudgetaireException('Une dotation existe déjà pour cette cible sur cet exercice : elle se corrige par une révision, pas par une seconde dotation.');
        }

        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            throw new AccessDeniedException();
        }

        $dotation = new Dotation($cible, $exercice, Montant::fromFcfa((int) $donnees->montant), $utilisateur);
        $dotation->setQ1(null !== $donnees->q1 ? Montant::fromFcfa($donnees->q1) : null);
        $dotation->setQ2(null !== $donnees->q2 ? Montant::fromFcfa($donnees->q2) : null);
        $dotation->setQ3(null !== $donnees->q3 ? Montant::fromFcfa($donnees->q3) : null);
        $dotation->setQ4(null !== $donnees->q4 ? Montant::fromFcfa($donnees->q4) : null);
        $dotation->setMontantValideCopil(null !== $donnees->montantValideCopil ? Montant::fromFcfa($donnees->montantValideCopil) : null);
        $dotation->setCommentaireRevision($donnees->commentaireRevision);

        // Les totaux additionnent l'activité et ses lignes : doter les deux
        // compterait deux fois le même argent. Verrou sur l'activité pour que
        // deux saisies simultanées ne passent pas ce contrôle ensemble.
        $this->em->wrapInTransaction(function () use ($dotation, $cible, $activiteParente, $exercice): void {
            $this->em->lock($activiteParente, LockMode::PESSIMISTIC_WRITE);

            if ($cible instanceof Activite && $this->dotations->existeSurSesLignes($cible, $exercice)) {
                throw new ContrainteBudgetaireException('Cette activité est dotée par ses lignes sur cet exercice : notifiez la dotation sur la ligne concernée.');
            }
            if (!$cible instanceof Activite && null !== $this->dotations->trouverPourCibleExercice($activiteParente, $exercice)) {
                throw new ContrainteBudgetaireException('Cette activité porte sa propre dotation sur cet exercice : doter aussi une ligne compterait deux fois le même budget.');
            }

            $this->em->persist($dotation);
        });

        return new JsonResponse($this->normalizer->normaliser($dotation), 201);
    }

    /**
     * Révision du montant notifié (décision du 09/11/2026) : permission dédiée,
     * détenue par le RSE et le coordonnateur. Le gestionnaire qui saisit les
     * engagements ne relève pas lui-même le plafond qu'ils doivent respecter.
     */
    #[Route('/{uuid}/reviser', name: 'app_dotation_reviser', methods: ['POST'])]
    public function reviser(string $uuid, Request $request): Response
    {
        $uuidObjet = $this->uuidOuNull($uuid);
        $dotation = null !== $uuidObjet ? $this->dotations->findOneBy(['uuid' => $uuidObjet]) : null;
        if (null === $dotation) {
            throw new NotFoundHttpException('Dotation introuvable.');
        }

        if (!$this->security->isGranted('budget.dotation.reviser', $dotation->getCible())) {
            throw new AccessDeniedException();
        }

        /** @var ReviserDotationRequest $donnees */
        $donnees = $this->serializer->deserialize($request->getContent(), ReviserDotationRequest::class, 'json');
        if (($erreur = $this->validerOuErreur($donnees, $this->validator)) !== null) {
            return $erreur;
        }

        $this->revision->reviser(
            $dotation,
            Montant::fromFcfa((int) $donnees->montant),
            trim($donnees->motif),
            null !== $donnees->montantValideCopil ? Montant::fromFcfa($donnees->montantValideCopil) : null,
        );

        return new JsonResponse($this->normalizer->normaliser($dotation));
    }
}

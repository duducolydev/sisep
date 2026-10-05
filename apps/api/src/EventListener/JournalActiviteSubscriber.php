<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Activite;
use App\Entity\Depense;
use App\Entity\Dotation;
use App\Entity\Engagement;
use App\Entity\EtapeActivite;
use App\Entity\Indicateur;
use App\Entity\IndicateurValeur;
use App\Entity\Initiative;
use App\Entity\InitiativeActivite;
use App\Entity\InvitationIndicateur;
use App\Entity\JournalActivite;
use App\Entity\Permission;
use App\Entity\Role;
use App\Entity\Structure;
use App\Entity\TacheInitiative;
use App\Entity\Utilisateur;
use App\ValueObject\Montant;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

/**
 * Journalise automatiquement toute écriture sur les entités listées au
 * § 7.3 du brief — interception globale, jamais d'appel manuel dans un
 * service métier.
 *
 * En deux temps, pas seulement onFlush (comme RealisationListener) : à
 * l'onFlush, une entité tout juste insérée n'a pas encore d'id — Doctrine ne
 * l'assigne qu'au moment de l'INSERT effectif. Les entrées sont donc
 * préparées à l'onFlush (changesets sérialisés) et l'entité elle-même est
 * conservée ; entite_id n'est résolu et l'entrée persistée qu'au postFlush,
 * une fois l'id réellement disponible — sinon toute création serait
 * journalisée avec entite_id NULL, rendant le fil d'activité inexploitable.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class JournalActiviteSubscriber
{
    /** @var array<class-string, string> */
    private const TYPES_SUIVIS = [
        Activite::class => 'activite',
        EtapeActivite::class => 'etape_activite',
        Dotation::class => 'dotation',
        Engagement::class => 'engagement',
        Depense::class => 'depense',
        Indicateur::class => 'indicateur',
        IndicateurValeur::class => 'indicateur_valeur',
        Structure::class => 'structure',
        Role::class => 'role',
        Permission::class => 'permission',
        Utilisateur::class => 'utilisateur',
        Initiative::class => 'initiative',
        InitiativeActivite::class => 'initiative_activite',
        TacheInitiative::class => 'tache_initiative',
        InvitationIndicateur::class => 'invitation_indicateur',
    ];

    /** @var list<array{entity: object, type: string, apres: array<string, mixed>}> */
    private array $creationsEnAttente = [];

    public function __construct(
        private readonly Security $security,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();
        $metadataJournal = $em->getClassMetadata(JournalActivite::class);

        // Créations : l'id n'existe pas encore à ce stade (assigné à
        // l'INSERT effectif) — reportées au postFlush plutôt que perdues.
        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            $type = $this->typePour($entity);
            if (null === $type) {
                continue;
            }
            $apres = $this->serialiserApres($uow->getEntityChangeSet($entity));
            $this->creationsEnAttente[] = ['entity' => $entity, 'type' => $type, 'apres' => $apres];
        }

        // Modifications : l'entité existe déjà, son id est connu — la
        // même logique de persistance immédiate qu'avant fonctionne.
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            $type = $this->typePour($entity);
            if (null === $type) {
                continue;
            }
            $entree = $this->construireEntree('modification', $type, $entity, $uow->getEntityChangeSet($entity));
            $em->persist($entree);
            $uow->computeChangeSet($metadataJournal, $entree);
        }

        // Suppressions physiques : en pratique les seuls octrois de droits de
        // type ACL (InvitationIndicateur), les entités budgétaires ou
        // historiques étant en suppression logique (donc journalisées comme
        // des modifications). Le changeset est vide à ce stade : l'entrée
        // retient qui a supprimé quoi (type + id), pas un diff.
        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            $type = $this->typePour($entity);
            if (null === $type) {
                continue;
            }
            $entree = $this->construireEntree('suppression', $type, $entity, []);
            $em->persist($entree);
            $uow->computeChangeSet($metadataJournal, $entree);
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ([] === $this->creationsEnAttente) {
            return;
        }

        $em = $args->getObjectManager();
        $utilisateur = $this->security->getUser();
        $requete = $this->requestStack->getCurrentRequest();

        foreach ($this->creationsEnAttente as $creation) {
            $entiteId = method_exists($creation['entity'], 'getId') ? $creation['entity']->getId() : null;

            $em->persist(new JournalActivite(
                $utilisateur instanceof Utilisateur ? $utilisateur : null,
                'creation',
                $creation['type'],
                \is_int($entiteId) ? $entiteId : null,
                null,
                $creation['apres'],
                $requete?->getClientIp() ?? '0.0.0.0',
                $requete?->headers->get('User-Agent') ?? 'console',
            ));
        }

        $this->creationsEnAttente = [];
        $em->flush();
    }

    private function typePour(object $entity): ?string
    {
        foreach (self::TYPES_SUIVIS as $classe => $type) {
            if ($entity instanceof $classe) {
                return $type;
            }
        }

        return null;
    }

    /** @param array<string, array{0: mixed, 1: mixed}> $changeSet */
    private function construireEntree(string $action, string $type, object $entity, array $changeSet): JournalActivite
    {
        $avant = [];
        foreach ($changeSet as $champ => [$ancien]) {
            $avant[$champ] = $this->serialiser($ancien);
        }
        $apres = $this->serialiserApres($changeSet);

        $entiteId = method_exists($entity, 'getId') ? $entity->getId() : null;
        $utilisateur = $this->security->getUser();
        $requete = $this->requestStack->getCurrentRequest();

        return new JournalActivite(
            $utilisateur instanceof Utilisateur ? $utilisateur : null,
            $action,
            $type,
            \is_int($entiteId) ? $entiteId : null,
            $avant,
            $apres,
            $requete?->getClientIp() ?? '0.0.0.0',
            $requete?->headers->get('User-Agent') ?? 'console',
        );
    }

    /**
     * @param array<string, array{0: mixed, 1: mixed}> $changeSet
     *
     * @return array<string, mixed>
     */
    private function serialiserApres(array $changeSet): array
    {
        $apres = [];
        foreach ($changeSet as $champ => [, $nouveau]) {
            $apres[$champ] = $this->serialiser($nouveau);
        }

        return $apres;
    }

    private function serialiser(mixed $valeur): mixed
    {
        return match (true) {
            $valeur instanceof \DateTimeInterface => $valeur->format(\DateTimeInterface::ATOM),
            $valeur instanceof \BackedEnum => $valeur->value,
            $valeur instanceof Uuid => $valeur->toRfc4122(),
            $valeur instanceof Montant => $valeur->versFcfa(),
            $valeur instanceof Utilisateur => $valeur->getUuid()->toRfc4122(),
            $valeur instanceof Structure => $valeur->getUuid()->toRfc4122(),
            \is_object($valeur) && method_exists($valeur, 'getUuid') => (string) $valeur->getUuid(),
            \is_object($valeur) => $valeur::class,
            default => $valeur,
        };
    }
}

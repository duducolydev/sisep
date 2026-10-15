<?php

declare(strict_types=1);

namespace App\Import;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Conduit un import en deux temps : l'aperçu analyse le fichier sans rien
 * écrire ; la confirmation renvoie le même fichier et applique les lignes
 * retenues, toutes dans une seule transaction — un incident en cours de
 * route n'en laisse aucune à moitié importée. Les lignes en erreur, les
 * doublons et celles déjà présentes sont écartées, jamais forcées.
 */
final class ServiceImport
{
    /** @var array<string, DefinitionImport> */
    private array $definitions = [];

    /** @param iterable<DefinitionImport> $definitions */
    public function __construct(
        #[AutowireIterator('app.definition_import')] iterable $definitions,
        private readonly LecteurFichierImport $lecteur,
        private readonly EntityManagerInterface $em,
    ) {
        foreach ($definitions as $definition) {
            $this->definitions[$definition->type()] = $definition;
        }
    }

    public function definition(string $type): ?DefinitionImport
    {
        return $this->definitions[$type] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function importer(DefinitionImport $definition, UploadedFile $fichier, bool $miseAJour, bool $appliquer): array
    {
        $lecture = $this->lecteur->lire($fichier, $definition->colonnes());
        $verdicts = $definition->analyser($lecture['lignes'], $miseAJour);

        $retenues = array_filter($verdicts, static fn (LigneImport $l) => $l->estRetenue());
        if ($appliquer && [] !== $retenues) {
            $this->em->wrapInTransaction(function () use ($retenues): void {
                foreach ($retenues as $ligne) {
                    if (null !== $ligne->application) {
                        ($ligne->application)();
                    }
                }
                $this->em->flush();
            });
        }

        $compter = static fn (string $statut): int => \count(array_filter($verdicts, static fn (LigneImport $l) => $l->statut === $statut));

        return [
            'type' => $definition->type(),
            'applique' => $appliquer,
            'fichier' => [
                'nom' => $fichier->getClientOriginalName(),
                'tailleOctets' => (int) $fichier->getSize(),
                'format' => $lecture['format'],
                'encodage' => $lecture['encodage'],
                'colonnesIgnorees' => $lecture['colonnesIgnorees'],
            ],
            'resume' => [
                'lignes' => \count($verdicts),
                'creations' => $compter(LigneImport::CREATION),
                'misesAJour' => $compter(LigneImport::MISE_A_JOUR),
                'existantes' => $compter(LigneImport::EXISTANTE),
                'doublons' => $compter(LigneImport::DOUBLON),
                'erreurs' => $compter(LigneImport::ERREUR),
            ],
            'lignes' => array_map(static fn (LigneImport $l) => $l->versTableau(), $verdicts),
        ];
    }
}

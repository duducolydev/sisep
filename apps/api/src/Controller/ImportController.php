<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Utilisateur;
use App\Http\ProblemResponseFactory;
use App\Import\DefinitionImport;
use App\Import\ImportInvalideException;
use App\Import\ImportPointsFocaux;
use App\Import\ModeleImportXlsx;
use App\Import\ServiceImport;
use App\Security\PermissionsResolver;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Import de données par fichier Excel (personnel technique, effectifs
 * d'enquêteurs, points focaux) : modèle téléchargeable, aperçu ligne à
 * ligne, puis confirmation.
 *
 * POST /imports/{type} avec le fichier (`fichier`), `miseAJour` (1 pour
 * mettre à jour les enregistrements déjà présents plutôt que les ignorer)
 * et `confirmer` (1 pour écrire ; sinon simple aperçu).
 */
#[Route('/api/v1/imports')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class ImportController
{
    public function __construct(
        private readonly ServiceImport $service,
        private readonly ModeleImportXlsx $modele,
        private readonly Security $security,
        private readonly PermissionsResolver $permissionsResolver,
    ) {
    }

    #[Route('/{type}/modele.xlsx', name: 'app_import_modele', methods: ['GET'], requirements: ['type' => '[a-z-]+'])]
    public function modele(string $type): Response
    {
        $definition = $this->definitionAutorisee($type);
        $classeur = $this->modele->construire($definition);

        $reponse = new StreamedResponse(static function () use ($classeur): void {
            (new Xlsx($classeur))->save('php://output');
        });
        $reponse->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $reponse->headers->set('Content-Disposition', \sprintf('attachment; filename="modele-import-%s.xlsx"', $definition->type()));

        return $reponse;
    }

    #[Route('/{type}', name: 'app_import_executer', methods: ['POST'], requirements: ['type' => '[a-z-]+'])]
    public function importer(string $type, Request $request): JsonResponse
    {
        $definition = $this->definitionAutorisee($type);

        $fichier = $request->files->get('fichier');
        if (!$fichier instanceof UploadedFile) {
            return ProblemResponseFactory::creer(422, 'Fichier manquant', 'Joignez le classeur à importer.');
        }

        try {
            $rapport = $this->service->importer(
                $definition,
                $fichier,
                '1' === (string) $request->request->get('miseAJour', '0'),
                '1' === (string) $request->request->get('confirmer', '0'),
            );
        } catch (ImportInvalideException $e) {
            return ProblemResponseFactory::creer(422, 'Fichier refusé', $e->getMessage());
        }

        return new JsonResponse($rapport, $rapport['applique'] ? 201 : 200);
    }

    /**
     * La permission de l'import, détenue au moins sur une structure : pour
     * les points focaux, la portée est ensuite vérifiée ligne par ligne.
     */
    private function definitionAutorisee(string $type): DefinitionImport
    {
        $definition = $this->service->definition($type);
        if (null === $definition) {
            throw new NotFoundHttpException('Import inconnu.');
        }

        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            throw new AccessDeniedException();
        }
        $permissions = $this->permissionsResolver->pour($utilisateur);
        // Les données RH relèvent de l'UGP seule (permission globale, comme à
        // la saisie) ; les points focaux admettent une portée par structure,
        // contrôlée ligne par ligne dans ImportPointsFocaux.
        $autorise = $definition instanceof ImportPointsFocaux
            ? $permissions->possede($definition->permission())
            : $permissions->estGlobale($definition->permission());
        if (!$autorise) {
            throw new AccessDeniedException();
        }

        return $definition;
    }
}

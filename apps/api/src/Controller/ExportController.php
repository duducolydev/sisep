<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Exercice;
use App\Entity\Projet;
use App\Enum\PeriodeTrimestre;
use App\Export\BudgetXlsxExporter;
use App\Export\CadreResultatsXlsxExporter;
use App\Export\InitiativePdfExporter;
use App\Export\InitiativeXlsxExporter;
use App\Export\RapportTrimestrielPdfExporter;
use App\Http\ProblemResponseFactory;
use App\Repository\ComposanteRepository;
use App\Repository\ExerciceRepository;
use App\Repository\ProjetRepository;
use App\Security\PermissionsResolver;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * § 6.2, § 10 du brief (P7). Pas de paramètre projet dans la signature des
 * trois points d'entrée : l'application ne gère qu'un seul projet en
 * interface (§ 11), on résout la ligne unique de `projets`.
 */
#[Route('/api/v1/exports')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class ExportController
{
    use ApiControllerHelpersTrait;

    public function __construct(
        private readonly ProjetRepository $projets,
        private readonly ExerciceRepository $exercices,
        private readonly ComposanteRepository $composantes,
        private readonly BudgetXlsxExporter $budgetExporter,
        private readonly CadreResultatsXlsxExporter $cadreResultatsExporter,
        private readonly RapportTrimestrielPdfExporter $rapportExporter,
        private readonly InitiativeXlsxExporter $initiativeXlsxExporter,
        private readonly InitiativePdfExporter $initiativePdfExporter,
        private readonly Security $security,
        private readonly PermissionsResolver $permissionsResolver,
    ) {
    }

    #[Route('/budget.xlsx', name: 'app_export_budget_xlsx', methods: ['GET'])]
    public function budgetXlsx(Request $request): Response
    {
        $this->exigerPermissionGlobale($this->security, $this->permissionsResolver, 'exports.generer');

        $projet = $this->projets->findOneBy([]);
        if (null === $projet) {
            return ProblemResponseFactory::creer(422, 'Projet introuvable');
        }

        $exercice = $this->resoudreExercice($projet, $request);
        if ($exercice instanceof Response) {
            return $exercice;
        }

        $composanteId = null;
        $composanteUuid = $request->query->get('composante');
        if (\is_string($composanteUuid) && '' !== $composanteUuid) {
            $composante = $this->composantes->findOneBy(['uuid' => $this->uuidOuNull($composanteUuid)]);
            if (null === $composante) {
                return ProblemResponseFactory::creer(422, 'Composante introuvable');
            }
            $composanteId = (int) $composante->getId();
        }

        $spreadsheet = $this->budgetExporter->exporter((int) $projet->getId(), (int) $exercice->getId(), $exercice->getAnnee(), $composanteId);

        return $this->reponseXlsx($spreadsheet, "budget-execution-{$exercice->getAnnee()}.xlsx");
    }

    #[Route('/cadre-resultats.xlsx', name: 'app_export_cadre_resultats_xlsx', methods: ['GET'])]
    public function cadreResultatsXlsx(Request $request): Response
    {
        $this->exigerPermissionGlobale($this->security, $this->permissionsResolver, 'exports.generer');

        $projet = $this->projets->findOneBy([]);
        if (null === $projet) {
            return ProblemResponseFactory::creer(422, 'Projet introuvable');
        }

        $exercice = $this->resoudreExercice($projet, $request);
        if ($exercice instanceof Response) {
            return $exercice;
        }

        $spreadsheet = $this->cadreResultatsExporter->exporter($projet, $exercice->getAnnee());

        return $this->reponseXlsx($spreadsheet, "cadre-resultats-{$exercice->getAnnee()}.xlsx");
    }

    #[Route('/rapport-trimestriel.pdf', name: 'app_export_rapport_trimestriel_pdf', methods: ['GET'])]
    public function rapportTrimestrielPdf(Request $request): Response
    {
        $this->exigerPermissionGlobale($this->security, $this->permissionsResolver, 'exports.generer');

        $projet = $this->projets->findOneBy([]);
        if (null === $projet) {
            return ProblemResponseFactory::creer(422, 'Projet introuvable');
        }

        $exercice = $this->resoudreExercice($projet, $request);
        if ($exercice instanceof Response) {
            return $exercice;
        }

        $periodeBrute = $request->query->get('periode');
        $periode = \is_string($periodeBrute) ? PeriodeTrimestre::tryFrom($periodeBrute) : null;
        if (null === $periode) {
            return ProblemResponseFactory::creer(422, 'Paramètre invalide', 'periode doit valoir T1, T2, T3 ou T4.');
        }

        $pdf = $this->rapportExporter->exporter($projet, $exercice, $periode);

        return new Response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => \sprintf('attachment; filename="rapport-trimestriel-%s-%d.pdf"', $periode->value, $exercice->getAnnee()),
        ]);
    }

    #[Route('/initiatives.xlsx', name: 'app_export_initiatives_xlsx', methods: ['GET'])]
    public function initiativesXlsx(): Response
    {
        $this->exigerPermissionGlobale($this->security, $this->permissionsResolver, 'exports.generer');

        return $this->reponseXlsx($this->initiativeXlsxExporter->exporter(), 'suivi-projets.xlsx');
    }

    #[Route('/initiatives.pdf', name: 'app_export_initiatives_pdf', methods: ['GET'])]
    public function initiativesPdf(): Response
    {
        $this->exigerPermissionGlobale($this->security, $this->permissionsResolver, 'exports.generer');

        return new Response($this->initiativePdfExporter->exporter(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="suivi-projets.pdf"',
        ]);
    }

    private function resoudreExercice(Projet $projet, Request $request): Exercice|Response
    {
        $anneeBrute = $request->query->get('exercice');
        $exercice = \is_string($anneeBrute) && '' !== $anneeBrute
            ? $this->exercices->trouverParAnnee($projet, (int) $anneeBrute)
            : $this->exercices->findOneBy(['projet' => $projet], ['annee' => 'DESC']);

        return $exercice ?? ProblemResponseFactory::creer(422, 'Exercice introuvable', 'Aucun exercice ne correspond à ce projet.');
    }

    private function reponseXlsx(Spreadsheet $spreadsheet, string $nomFichier): Response
    {
        $ressource = fopen('php://temp', 'r+b');
        \assert(false !== $ressource);
        (new Xlsx($spreadsheet))->save($ressource);
        rewind($ressource);
        $contenu = stream_get_contents($ressource);
        fclose($ressource);

        $reponse = new Response(false !== $contenu ? $contenu : '');
        $reponse->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $reponse->headers->set('Content-Disposition', \sprintf('attachment; filename="%s"', $nomFichier));

        return $reponse;
    }
}

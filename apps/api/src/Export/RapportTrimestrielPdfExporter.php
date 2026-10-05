<?php

declare(strict_types=1);

namespace App\Export;

use App\Entity\Exercice;
use App\Entity\Projet;
use App\Enum\PeriodeTrimestre;
use App\Http\IndicateurNormalizer;
use App\Query\AlertesReader;
use App\Query\BudgetExecutionReader;
use App\Query\SyntheseReader;
use App\Repository\IndicateurRepository;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * GET /exports/rapport-trimestriel.pdf?exercice=&periode= (§ 6.2, § 10 —
 * critère d'acceptation P7). Rapport consolidé projet — pas de paramètre
 * structure dans le brief, contrairement à /rapports : c'est le document que
 * la coordination transmet au bailleur, pas la soumission d'une structure.
 * Reprend telles quelles les grandeurs déjà exposées au tableau de bord
 * (§ 2.1) et au budget (§ 2.3), jamais recalculées en parallèle.
 */
final class RapportTrimestrielPdfExporter
{
    public function __construct(
        private readonly SyntheseReader $syntheseReader,
        private readonly BudgetExecutionReader $budgetReader,
        private readonly AlertesReader $alertesReader,
        private readonly IndicateurRepository $indicateurs,
        private readonly IndicateurNormalizer $indicateurNormalizer,
    ) {
    }

    public function exporter(Projet $projet, Exercice $exercice, PeriodeTrimestre $periode): string
    {
        $projetId = (int) $projet->getId();
        $exerciceId = (int) $exercice->getId();

        $synthese = $this->syntheseReader->lire($projetId, $exerciceId);
        $composantes = $this->budgetReader->lire($projetId, $exerciceId, 'composante', null);
        $alertes = $this->alertesReader->lire($projetId, $exercice);
        $indicateurs = array_map(
            fn ($i) => $this->indicateurNormalizer->normaliser($i),
            $this->indicateurs->filtrer($projet, null, null),
        );

        $html = $this->construireHtml($projet, $exercice, $periode, $synthese, $composantes, $alertes, $indicateurs);

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    /**
     * @param array<string, mixed>       $synthese
     * @param list<array<string, mixed>> $composantes
     * @param list<array<string, mixed>> $alertes
     * @param list<array<string, mixed>> $indicateurs
     */
    private function construireHtml(
        Projet $projet,
        Exercice $exercice,
        PeriodeTrimestre $periode,
        array $synthese,
        array $composantes,
        array $alertes,
        array $indicateurs,
    ): string {
        $fcfa = static fn (?int $montant): string => null === $montant ? '—' : number_format($montant, 0, ',', ' ').' FCFA';
        $pct = static fn (?float $taux): string => null === $taux ? '—' : number_format($taux * 100, 1, ',', ' ').' %';
        $e = static fn (string $valeur): string => htmlspecialchars($valeur, \ENT_QUOTES, 'UTF-8');

        $lignesComposantes = '';
        foreach ($composantes as $c) {
            $lignesComposantes .= '<tr>'
                .'<td>'.$e((string) $c['code']).'</td>'
                .'<td>'.$e((string) $c['libelle']).'</td>'
                .'<td class="num">'.$fcfa($c['notifie']).'</td>'
                .'<td class="num">'.$fcfa($c['engage']).'</td>'
                .'<td class="num">'.$fcfa($c['depense']).'</td>'
                .'<td class="num">'.$pct($c['tauxExecution']).'</td>'
                .'</tr>';
        }

        $lignesAlertes = '';
        foreach ($alertes as $a) {
            $classe = 'risque_eleve' === $a['vigilance'] ? 'bad' : 'warn';
            $libelleVigilance = 'risque_eleve' === $a['vigilance'] ? 'Risque élevé' : 'En retard';
            $lignesAlertes .= '<tr>'
                .'<td>'.$e((string) $a['code']).'</td>'
                .'<td>'.$e((string) $a['libelle']).'</td>'
                .'<td>'.$e((string) $a['structure']).'</td>'
                .'<td><span class="pastille '.$classe.'">'.$libelleVigilance.'</span></td>'
                .'</tr>';
        }
        if ('' === $lignesAlertes) {
            $lignesAlertes = '<tr><td colspan="4">Aucun point d\'attention pour cette période.</td></tr>';
        }

        $lignesIndicateurs = '';
        foreach ($indicateurs as $i) {
            $tauxAtteinte = null !== $i['tauxAtteinte'] ? number_format($i['tauxAtteinte'] * 100, 1, ',', ' ').' %' : '—';
            $lignesIndicateurs .= '<tr>'
                .'<td>'.$e((string) $i['code']).'</td>'
                .'<td>'.$e((string) $i['libelle']).'</td>'
                .'<td class="num">'.$e((string) ($i['actuel'] ?? '—')).' '.$e((string) $i['unite']).'</td>'
                .'<td class="num">'.$e((string) $i['valeurCible']).' '.$e((string) $i['unite']).'</td>'
                .'<td class="num">'.$tauxAtteinte.'</td>'
                .'</tr>';
        }

        $genereLe = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('d/m/Y H:i');

        return <<<HTML
            <!doctype html>
            <html><head><meta charset="utf-8"><style>
                body { font-family: "DejaVu Sans", sans-serif; font-size: 10px; color: #0E1F2E; }
                h1 { font-size: 18px; margin: 0 0 4px; }
                h2 { font-size: 13px; margin: 18px 0 8px; color: #155E93; border-bottom: 1px solid #E3DCCC; padding-bottom: 4px; }
                .eyebrow { font-size: 9px; letter-spacing: .12em; text-transform: uppercase; color: #B8802E; margin: 0 0 6px; }
                .lede { color: #6E6A60; margin: 0 0 4px; }
                table { width: 100%; border-collapse: collapse; margin-top: 4px; }
                th, td { padding: 5px 6px; border-bottom: 1px solid #E3DCCC; text-align: left; }
                th { background: #FAF7F0; font-size: 9px; text-transform: uppercase; letter-spacing: .05em; color: #6E6A60; }
                td.num, th.num { text-align: right; font-family: "DejaVu Sans Mono", monospace; }
                .kpis { width: 100%; margin-top: 10px; }
                .kpis td { border: 1px solid #E3DCCC; padding: 8px 10px; width: 25%; }
                .kpis .l { font-size: 8px; text-transform: uppercase; color: #6E6A60; }
                .kpis .v { font-size: 14px; font-weight: bold; font-family: "DejaVu Sans Mono", monospace; }
                .pastille { padding: 2px 6px; border-radius: 8px; font-size: 9px; }
                .pastille.bad { background: #F7EAE6; color: #8C3A2B; }
                .pastille.warn { background: #FBF0DE; color: #8A5E15; }
                .pied { margin-top: 24px; font-size: 8px; color: #6E6A60; }
            </style></head>
            <body>
                <p class="eyebrow">Rapport trimestriel — {$e($projet->getLibelle())}</p>
                <h1>{$e($periode->value)} {$exercice->getAnnee()} — Synthèse de l'exécution</h1>
                <p class="lede">Bailleur : {$e($projet->getBailleur())} · Généré le {$genereLe} UTC</p>

                <table class="kpis"><tr>
                    <td><p class="l">Notifié</p><p class="v">{$fcfa($synthese['notifie'])}</p></td>
                    <td><p class="l">Engagé</p><p class="v">{$fcfa($synthese['engage'])}</p></td>
                    <td><p class="l">Exécuté</p><p class="v">{$fcfa($synthese['depense'])}</p></td>
                    <td><p class="l">Disponible</p><p class="v">{$fcfa($synthese['disponible'])}</p></td>
                </tr></table>

                <h2>Exécution par composante</h2>
                <table>
                    <thead><tr><th>Code</th><th>Composante</th><th class="num">Notifié</th><th class="num">Engagé</th><th class="num">Dépensé</th><th class="num">Taux</th></tr></thead>
                    <tbody>{$lignesComposantes}</tbody>
                </table>

                <h2>Points d'attention</h2>
                <table>
                    <thead><tr><th>Code</th><th>Activité</th><th>Structure</th><th>Vigilance</th></tr></thead>
                    <tbody>{$lignesAlertes}</tbody>
                </table>

                <h2>Cadre de résultats</h2>
                <table>
                    <thead><tr><th>Code</th><th>Indicateur</th><th class="num">Actuel</th><th class="num">Cible</th><th class="num">Taux d'atteinte</th></tr></thead>
                    <tbody>{$lignesIndicateurs}</tbody>
                </table>

                <p class="pied">SISEP — Système d'information de suivi-évaluation du PHASAOC. Document généré automatiquement, données à la date d'édition.</p>
            </body></html>
            HTML;
    }
}

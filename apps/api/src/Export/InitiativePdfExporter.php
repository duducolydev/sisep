<?php

declare(strict_types=1);

namespace App\Export;

use App\Http\InitiativeNormalizer;
use App\Repository\InitiativeRepository;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * GET /exports/initiatives.pdf. Même style et mêmes couleurs que
 * RapportTrimestrielPdfExporter (§ maquette de référence).
 */
final class InitiativePdfExporter
{
    private const LIBELLES_STATUT = [
        'planifiee' => 'Planifiée',
        'en_cours' => 'En cours',
        'a_risque' => 'À risque',
        'en_retard' => 'En retard',
        'terminee' => 'Terminée',
    ];

    private const CLASSES_STATUT = [
        'a_risque' => 'bad',
        'en_retard' => 'warn',
    ];

    public function __construct(
        private readonly InitiativeRepository $initiatives,
        private readonly InitiativeNormalizer $normalizer,
    ) {
    }

    public function exporter(): string
    {
        $donnees = array_map($this->normalizer->normaliser(...), $this->initiatives->listerNonSupprimees());
        $html = $this->construireHtml($donnees);

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        return $dompdf->output();
    }

    /** @param list<array<string, mixed>> $donnees */
    private function construireHtml(array $donnees): string
    {
        $e = static fn (string $valeur): string => htmlspecialchars($valeur, \ENT_QUOTES, 'UTF-8');
        $pct = static fn (?float $taux): string => null === $taux ? '—' : number_format($taux * 100, 1, ',', ' ').' %';

        $lignes = '';
        foreach ($donnees as $initiative) {
            $lienPtba = null !== $initiative['lienPtba']
                ? \sprintf('%s — %s', $initiative['lienPtba']['code'], $initiative['lienPtba']['libelle'])
                : 'Indépendant';
            $tauxPtba = null !== $initiative['lienPtba'] ? $pct($initiative['lienPtba']['tauxExecutionPtba']) : '—';

            if ([] === $initiative['activites']) {
                $lignes .= '<tr><td>'.$e((string) $initiative['libelle']).'</td><td>'.$e($lienPtba).'</td>'
                    .'<td colspan="4">Aucune activité.</td><td class="num">'.$tauxPtba.'</td></tr>';
                continue;
            }

            foreach ($initiative['activites'] as $activite) {
                $responsables = implode(', ', array_map(static fn ($r) => $r['nomComplet'], $activite['responsables']));
                $statutCode = (string) $activite['statut'];
                $classeStatut = self::CLASSES_STATUT[$statutCode] ?? null;
                $libelleStatut = self::LIBELLES_STATUT[$statutCode] ?? $statutCode;
                $statut = null !== $classeStatut
                    ? '<span class="pastille '.$classeStatut.'">'.$e($libelleStatut).'</span>'
                    : $e($libelleStatut);

                $lignes .= '<tr>'
                    .'<td>'.$e((string) $initiative['libelle']).'</td>'
                    .'<td>'.$e($lienPtba).'</td>'
                    .'<td>'.$e((string) $activite['libelle']).'</td>'
                    .'<td>'.$e('' !== $responsables ? $responsables : '—').'</td>'
                    .'<td>'.$statut.'</td>'
                    .'<td class="num">'.$pct($activite['avancement']).'</td>'
                    .'<td class="num">'.$tauxPtba.'</td>'
                    .'</tr>';
            }
        }
        if ('' === $lignes) {
            $lignes = '<tr><td colspan="7">Aucun projet.</td></tr>';
        }

        $genereLe = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('d/m/Y H:i');

        return <<<HTML
            <!doctype html>
            <html><head><meta charset="utf-8"><style>
                body { font-family: "DejaVu Sans", sans-serif; font-size: 10px; color: #0E1F2E; }
                h1 { font-size: 18px; margin: 0 0 4px; }
                .eyebrow { font-size: 9px; letter-spacing: .12em; text-transform: uppercase; color: #B8802E; margin: 0 0 6px; }
                .lede { color: #6E6A60; margin: 0 0 10px; }
                table { width: 100%; border-collapse: collapse; }
                th, td { padding: 5px 6px; border-bottom: 1px solid #E3DCCC; text-align: left; }
                th { background: #FAF7F0; font-size: 9px; text-transform: uppercase; letter-spacing: .05em; color: #6E6A60; }
                td.num, th.num { text-align: right; font-family: "DejaVu Sans Mono", monospace; }
                .pastille { padding: 2px 6px; border-radius: 8px; font-size: 9px; }
                .pastille.bad { background: #F7EAE6; color: #8C3A2B; }
                .pastille.warn { background: #FBF0DE; color: #8A5E15; }
                .pied { margin-top: 24px; font-size: 8px; color: #6E6A60; }
            </style></head>
            <body>
                <p class="eyebrow">SISEP — Suivi des projets</p>
                <h1>Suivi des projets</h1>
                <p class="lede">Généré le {$genereLe} UTC</p>

                <table>
                    <thead><tr><th>Projet</th><th>Lien PTBA</th><th>Activité</th><th>Responsable(s)</th><th>Statut</th><th class="num">Avancement</th><th class="num">Taux PTBA</th></tr></thead>
                    <tbody>{$lignes}</tbody>
                </table>

                <p class="pied">SISEP — Système d'information de suivi-évaluation du PHASAOC. Document généré automatiquement, données à la date d'édition.</p>
            </body></html>
            HTML;
    }
}

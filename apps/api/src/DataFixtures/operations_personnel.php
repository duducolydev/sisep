<?php

declare(strict_types=1);

/*
 * Rattachement des opérations du classeur « Situation du personnel » aux
 * activités du PTBA (code d'activité).
 *
 * Le classeur groupe les agents par opération, mais sous l'intitulé d'usage
 * de l'UGP, qui ne reprend pas le libellé du PTBA : « REBASING » d'un côté,
 * « Amélioration des comptes nationaux avec le SCN 2008 (rebasage…) » de
 * l'autre. Aucun rapprochement automatique ne tient sur des libellés aussi
 * éloignés, d'où cette table écrite à la main.
 *
 * N'y figurent que les rattachements que le PTBA permet d'établir sans
 * ambiguïté. Les opérations absentes laissent l'agent sans opération de
 * rattachement : l'import le signale, et l'UGP tranche depuis la fiche de
 * l'agent. Ne jamais compléter cette table par déduction — une mauvaise
 * affectation se propage à tous les comptages du tableau de bord RH.
 *
 * Clé : intitulé du classeur, en majuscules sans accent et espaces
 * normalisés (la forme produite par LecteurClasseurPersonnel).
 *
 * @return array<string, string> intitulé normalisé => code d'activité PTBA
 */

return [
    // « assurance qualité des données » ↔ 2.5.1 « Renforcer l'assurance qualité ».
    "MISE EN PLACE D'UN CADRE NATIONAL D'ASSURANCE QUALITES DES DONNEES DU SYSTEME STATISTIQUE NATIONAL (CNAQ)" => '2.5.1',

    // EHCVM = enquête harmonisée sur les conditions de vie des ménages.
    'ENQUETE HARMONISEE DE CONDITIONS DE VIE DES MENAGES 2E & 3E EDITION (EHCVM 2 & 3)' => '1.2.2',

    // ENES = enquête nationale sur l'emploi ↔ 1.2.3 « population active ».
    "ENQUETE NATIONALE DE L'EMPLOI AU SENEGAL (ENES)" => '1.2.3',

    // Répertoire d'entreprises ↔ 1.3.1 « registre des entreprises ».
    "MODERNISATION DES REPERTOIRES D'ENTREPRISES" => '1.3.1',

    // Rebasage des comptes nationaux ↔ 1.3.6, qui le nomme explicitement.
    "PROJET DE CHANGEMENT D'ANNES DE BASE _ REBASING" => '1.3.6',

    // 2.6.3 s'intitule « Personnel d'appui technique ».
    'APPUI TECHNIQUE' => '2.6.3',
];

<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * POST /indicateurs/:uuid/valeurs { periode, valeur|delta, desagregations[] } —
 * § 6.2 du brief. "desagregations" n'est volontairement pas un champ ici :
 * le Serializer de Symfony ne dénormalise pas un tableau d'objets à partir
 * d'un simple indice PHPDoc (`DesagregationEntree[]`) sans configuration
 * supplémentaire — le contrôleur les décode et les valide à la main.
 *
 * Exactement l'un de "valeur" (absolue) ou "delta" (ajustement +/- depuis
 * la dernière saisie) doit être fourni — vérifié dans le contrôleur, pas
 * ici : Symfony n'exprime pas proprement une contrainte croisée "l'un des
 * deux" sur deux propriétés simples (même motif que la vérification
 * composante-XOR-sous-composante côté Initiative).
 */
final class SaisirValeurIndicateurRequest
{
    #[Assert\NotBlank(message: 'La période est requise.')]
    public string $periode = '';

    public ?string $valeur = null;

    public ?string $delta = null;

    public ?string $commentaire = null;
}

<?php

declare(strict_types=1);

namespace App\Http;

use App\Enum\TypeDocument;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Lit la nature d'un document déposé (`typeDocument`, et
 * `typeDocumentPrecision` pour « Autre ») dans un envoi multipart — commun à
 * tous les points de dépôt : bibliothèque, justificatifs d'étape, pièces des
 * valeurs d'indicateur et des projets. À appeler avant de déplacer le
 * fichier, pour ne jamais stocker un fichier que la requête refusera.
 */
final class TypeDocumentRequete
{
    /** @return array{0: TypeDocument, 1: string|null}|JsonResponse */
    public static function lire(Request $request): array|JsonResponse
    {
        return self::depuis($request->request->all());
    }

    /**
     * Même lecture sur un corps JSON déjà décodé — la requalification d'un
     * document déposé (PATCH /pieces-jointes/{uuid}).
     *
     * @param array<mixed> $donnees
     *
     * @return array{0: TypeDocument, 1: string|null}|JsonResponse
     */
    public static function depuis(array $donnees): array|JsonResponse
    {
        $brut = $donnees['typeDocument'] ?? null;
        $type = \is_string($brut) ? TypeDocument::tryFrom($brut) : null;
        if (null === $type) {
            return ProblemResponseFactory::creer(422, 'Type de document requis', 'Choisissez le type du document (rapport, compte-rendu, manuel…).');
        }

        $precision = $donnees['typeDocumentPrecision'] ?? null;
        $precision = \is_string($precision) ? trim($precision) : null;
        if (TypeDocument::AUTRE === $type && (null === $precision || '' === $precision)) {
            return ProblemResponseFactory::creer(422, 'Précision requise', 'Précisez le type de document lorsque « Autre » est choisi.');
        }
        if (null !== $precision && mb_strlen($precision) > 120) {
            return ProblemResponseFactory::creer(422, 'Précision trop longue', 'La précision du type tient en 120 caractères au plus.');
        }

        return [$type, TypeDocument::AUTRE === $type ? $precision : null];
    }
}

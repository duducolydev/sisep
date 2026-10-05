<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Erreurs au format RFC 7807 (application/problem+json), § 6 du brief.
 */
final class ProblemResponseFactory
{
    /** @param array<string, mixed> $extra */
    public static function creer(int $status, string $title, ?string $detail = null, array $extra = []): JsonResponse
    {
        $corps = array_merge([
            'type' => 'about:blank',
            'title' => $title,
            'status' => $status,
        ], null !== $detail ? ['detail' => $detail] : [], $extra);

        $reponse = new JsonResponse($corps, $status);
        $reponse->headers->set('Content-Type', 'application/problem+json');

        return $reponse;
    }
}

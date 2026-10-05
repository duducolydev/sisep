<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Exception\ContrainteBudgetaireException;
use App\Http\ProblemResponseFactory;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/** Erreurs au format RFC 7807 (application/problem+json) sur toute l'API, § 6 du brief. */
#[AsEventListener(event: KernelEvents::EXCEPTION)]
final class ApiExceptionListener
{
    public function __construct(#[Autowire('%kernel.debug%')] private readonly bool $debug)
    {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api/')) {
            return;
        }

        $exception = $event->getThrowable();

        $reponse = match (true) {
            $exception instanceof ValidationFailedException => $this->depuisValidation($exception),
            $exception instanceof ContrainteBudgetaireException => ProblemResponseFactory::creer(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'Contrainte budgétaire violée',
                $exception->getMessage(),
            ),
            $exception instanceof AuthenticationException => ProblemResponseFactory::creer(
                Response::HTTP_UNAUTHORIZED,
                'Authentification requise',
                $exception->getMessage(),
            ),
            $exception instanceof AccessDeniedException, $exception instanceof AccessDeniedHttpException => ProblemResponseFactory::creer(
                Response::HTTP_FORBIDDEN,
                'Accès refusé',
                "Vous n'avez pas la permission d'effectuer cette action.",
            ),
            $exception instanceof HttpExceptionInterface => ProblemResponseFactory::creer(
                $exception->getStatusCode(),
                $this->titrePour($exception->getStatusCode()),
                $exception->getMessage(),
            ),
            // Contrainte d'unicité en base (ex. une seule valeur d'indicateur
            // par période, § 4.4) : conflit métier, pas une panne — jamais un
            // 500 avec le SQL brut qui fuite en detail (visible en debug).
            $exception instanceof UniqueConstraintViolationException => ProblemResponseFactory::creer(
                Response::HTTP_CONFLICT,
                'Conflit',
                'Une donnée identique existe déjà (ex. une valeur a déjà été saisie pour cette période).',
            ),
            default => ProblemResponseFactory::creer(
                Response::HTTP_INTERNAL_SERVER_ERROR,
                'Erreur interne',
                $this->debug ? $exception->getMessage() : null,
            ),
        };

        $event->setResponse($reponse);
    }

    private function depuisValidation(ValidationFailedException $exception): Response
    {
        $violations = [];
        foreach ($exception->getViolations() as $violation) {
            $violations[] = [
                'champ' => $violation->getPropertyPath(),
                'message' => $violation->getMessage(),
            ];
        }

        return ProblemResponseFactory::creer(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'Données invalides',
            null,
            ['violations' => $violations],
        );
    }

    private function titrePour(int $status): string
    {
        return match ($status) {
            404 => 'Ressource introuvable',
            405 => 'Méthode non autorisée',
            default => 'Erreur de requête',
        };
    }
}

<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Http;

use Plan2net\PlaywrightToolkit\Security\TestApiSecret;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Core\Environment;

final class RecordEditDiagnostics implements MiddlewareInterface
{
    /**
     * @var string
     */
    private const EDIT_PATH = '/record/edit';

    public function __construct(
        private readonly TestApiSecret $secret,
        private readonly RecordReport $report,
    ) {
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!Environment::getContext()->isTesting()) {
            return $handler->handle($request);
        }

        if (!TestApi::matches($request->getUri()->getPath(), self::EDIT_PATH)) {
            return $handler->handle($request);
        }

        // Not our endpoint: without the secret the backend's own answer goes back untouched.
        if (!$this->secret->matches(trim($request->getHeaderLine(TestApiSecret::HEADER)))) {
            return $handler->handle($request);
        }

        $before = $this->report->lastLogUid();
        $response = $handler->handle($request);

        return $this->report->withRefusals($this->withSavedRecord($response, $before), $before);
    }

    private function withSavedRecord(ResponseInterface $response, int $before): ResponseInterface
    {
        $envelope = [];

        $uid = SavedRecord::uidFrom($response->getHeaderLine('location'), 'pages');
        $slug = null === $uid ? null : $this->report->slugOf($uid);
        if (null !== $slug) {
            $envelope['slug'] = $slug;
        }

        $written = $this->report->writtenAfter($before);
        if ([] !== $written) {
            $envelope['written'] = $written;
        }

        if ([] === $envelope) {
            return $response;
        }

        return $response->withHeader(SavedRecord::HEADER, (string) json_encode($envelope));
    }
}

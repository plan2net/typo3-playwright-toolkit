<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Http;

use Plan2net\PlaywrightToolkit\DataHandling\FormRules;
use Plan2net\PlaywrightToolkit\DataHandling\UnknownColumns;
use Plan2net\PlaywrightToolkit\Security\TestApiSecret;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Core\Environment;

final class RecordEditRefusal implements MiddlewareInterface
{
    /**
     * @var string
     */
    public const SKIP_FORM_RULES_HEADER = 'X-Playwright-Skip-Form-Rules';

    /**
     * @var string
     */
    private const EDIT_PATH = '/record/edit';

    /**
     * @var int
     */
    private const HEADER_BUDGET = 2000;

    public function __construct(
        private readonly TestApiSecret $secret,
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

        if (!$this->secret->matches(trim($request->getHeaderLine(TestApiSecret::HEADER)))) {
            return $handler->handle($request);
        }

        $data = self::datamap($request);
        $refused = UnknownColumns::check($data, $GLOBALS['TCA'] ?? []);
        if ([] === $refused) {
            $refused = FormRules::check($request, $data, '1' !== $request->getHeaderLine(self::SKIP_FORM_RULES_HEADER));
        }

        if ([] !== $refused) {
            return TestApi::error(implode(' ', array_column($refused, 'message')), 422)
                ->withHeader(RecordDiagnostics::HEADER, self::envelope($refused));
        }

        return $handler->handle($request);
    }

    /**
     * @return array<string, array<string, array<string, mixed>>>
     */
    private static function datamap(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();
        if (!\is_array($body) || !\is_array($body['data'] ?? null)) {
            return [];
        }

        $datamap = [];
        foreach ($body['data'] as $table => $records) {
            if (!\is_string($table) || !\is_array($records)) {
                continue;
            }

            foreach ($records as $identifier => $record) {
                if (!\is_array($record)) {
                    continue;
                }

                foreach ($record as $column => $value) {
                    $datamap[$table][(string) $identifier][(string) $column] = $value;
                }
            }
        }

        return $datamap;
    }

    /**
     * @param list<array{table: string, message: string}> $entries
     */
    private static function envelope(array $entries): string
    {
        $count = \count($entries);

        while (\count($entries) > 1) {
            $encoded = (string) json_encode(['errors' => $entries, 'count' => $count]);
            if (\strlen($encoded) <= self::HEADER_BUDGET) {
                return $encoded;
            }

            array_pop($entries);
        }

        return (string) json_encode(['errors' => $entries, 'count' => $count]);
    }
}

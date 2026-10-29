<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Http;

use Plan2net\PlaywrightToolkit\Security\TestApiSecret;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;

final class TranslatedRecordReport implements MiddlewareInterface
{
    /**
     * @var string
     */
    private const COMMIT_PATH = '/record/commit';

    private const COMMANDS = ['localize', 'copyToLanguage'];

    public function __construct(
        private readonly TestApiSecret $secret,
        private readonly ConnectionPool $connectionPool,
        private readonly RecordReport $report,
    ) {
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!Environment::getContext()->isTesting()) {
            return $handler->handle($request);
        }

        if (!TestApi::matches($request->getUri()->getPath(), self::COMMIT_PATH)) {
            return $handler->handle($request);
        }

        if (!$this->secret->matches(trim($request->getHeaderLine(TestApiSecret::HEADER)))) {
            return $handler->handle($request);
        }

        $command = self::translationCommand($request);
        if (null === $command) {
            return $handler->handle($request);
        }

        $before = $this->highestUid($command['table']);
        $logBefore = $this->report->lastLogUid();
        $response = $this->report->withRefusals($handler->handle($request), $logBefore);

        $translation = $this->translationOf($command, $before);
        if (null === $translation) {
            return $response;
        }

        $slug = 'pages' === $command['table'] ? $this->report->slugOf($translation['uid']) : null;
        if (null !== $slug) {
            $response = $response->withHeader(SavedRecord::HEADER, (string) json_encode(['slug' => $slug]));
        }

        return $response->withHeader(
            TranslatedRecord::HEADER,
            TranslatedRecord::header(
                $translation['uid'],
                $translation['pid'],
                $this->referencesOf($command['table'], $translation['uid'])
            )
        );
    }

    /**
     * @return array{table: string, origin: int, language: int}|null
     */
    private static function translationCommand(ServerRequestInterface $request): ?array
    {
        $commands = ((array) $request->getParsedBody())['cmd'] ?? null;
        if (!\is_array($commands) || 1 !== \count($commands)) {
            return null;
        }

        $table = (string) array_key_first($commands);
        $records = $commands[$table];
        if (!\is_array($records) || 1 !== \count($records)) {
            return null;
        }

        $origin = (int) array_key_first($records);
        $instruction = $records[$origin] ?? null;
        if (!\is_array($instruction) || 1 !== \count($instruction)) {
            return null;
        }

        $name = (string) array_key_first($instruction);
        if (!\in_array($name, self::COMMANDS, true) || !isset($GLOBALS['TCA'][$table])) {
            return null;
        }

        return ['table' => $table, 'origin' => $origin, 'language' => (int) $instruction[$name]];
    }

    /**
     * @return list<string>|null the language field, then the fields naming the origin
     */
    private static function languageFields(string $table): ?array
    {
        $control = $GLOBALS['TCA'][$table]['ctrl'] ?? [];
        $language = (string) ($control['languageField'] ?? '');
        $parent = (string) ($control['transOrigPointerField'] ?? '');
        if ('' === $language || '' === $parent) {
            return null;
        }

        $source = (string) ($control['translationSource'] ?? '');

        return '' === $source ? [$language, $parent] : [$language, $parent, $source];
    }

    private function highestUid(string $table): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        return (int) $queryBuilder
            ->select('uid')
            ->from($table)
            ->orderBy('uid', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * @param array{table: string, origin: int, language: int} $command
     *
     * @return array{uid: int, pid: int}|null
     */
    private function translationOf(array $command, int $before): ?array
    {
        $fields = self::languageFields($command['table']);
        if (null === $fields) {
            return null;
        }

        $language = array_shift($fields);

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($command['table']);
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());
        $expression = $queryBuilder->expr();
        $origin = $queryBuilder->createNamedParameter($command['origin'], Connection::PARAM_INT);
        $namesTheOrigin = array_map(static fn(string $field): string => (string) $expression->eq($field, $origin), $fields);

        $row = $queryBuilder
            ->select('uid', 'pid')
            ->from($command['table'])
            ->where(
                $expression->gt('uid', $queryBuilder->createNamedParameter($before, Connection::PARAM_INT)),
                $expression->eq((string) $language, $queryBuilder->createNamedParameter($command['language'], Connection::PARAM_INT)),
                '(' . implode(' OR ', $namesTheOrigin) . ')'
            )
            ->orderBy('uid', 'ASC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return false === $row ? null : ['uid' => (int) $row['uid'], 'pid' => (int) $row['pid']];
    }

    /**
     * @return array<string, list<int>> column => reference uids, in the order they show
     */
    private function referencesOf(string $table, int $uid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());

        $rows = $queryBuilder
            ->select('uid', 'fieldname')
            ->from('sys_file_reference')
            ->where(
                $queryBuilder->expr()->eq('tablenames', $queryBuilder->createNamedParameter($table)),
                $queryBuilder->expr()->eq('uid_foreign', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT))
            )
            ->orderBy('sorting_foreign', 'ASC')
            ->addOrderBy('uid', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        $references = [];
        foreach ($rows as $row) {
            $references[(string) $row['fieldname']][] = (int) $row['uid'];
        }

        return $references;
    }
}

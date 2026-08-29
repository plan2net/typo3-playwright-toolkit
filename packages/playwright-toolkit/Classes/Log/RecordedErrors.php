<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Log;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Log\LogDataTrait;
use TYPO3\CMS\Core\Log\LogLevel;
use TYPO3\CMS\Core\SysLog\Type as SystemLogType;

final class RecordedErrors
{
    use LogDataTrait;

    /**
     * @return list<array<string, mixed>>
     */
    public function readFrom(Connection $connection, int $limit): array
    {
        $queryBuilder = $connection->createQueryBuilder();
        $expression = $queryBuilder->expr();

        // The level column holds both spellings: writelog() writes the PSR-3 name,
        // DatabaseWriter the number. Severity is only askable in the numeric form,
        // and error > 0 carries the rest.
        $severeLevels = array_map(
            static fn(string $level): string => $queryBuilder->createNamedParameter($level),
            ['0', '1', '2', '3']
        );

        $rows = $queryBuilder
            ->select('*')
            ->from('sys_log')
            ->where(
                $expression->or(
                    $expression->gt('error', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                    $expression->and(
                        $expression->neq('component', $queryBuilder->createNamedParameter('')),
                        $expression->in('level', $severeLevels)
                    )
                )
            )
            ->orderBy('uid', 'ASC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();

        $errors = $this->collapseRepeats(array_map(fn(array $row): array => $this->fromRow($row), $rows));

        return $this->dropLegacyExceptionTwins($errors);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public function fromRow(array $row): array
    {
        $kind = $this->kindOf($row);

        $common = [
            'uid' => (int) $row['uid'],
            'source' => 'channel' === $kind ? (string) ($row['channel'] ?? 'default') : $kind,
            'at' => $this->timeOf($row),
        ];

        return $common + match ($kind) {
            'datahandler' => $this->dataHandlerFields($row),
            'log' => $this->logFields($row),
            default => $this->messageFields($row),
        };
    }

    /**
     * AbstractExceptionHandler logs an uncaught exception through PSR-3 and then
     * writes it to sys_log a second time from a branch core marks "Legacy logger.
     * Remove this section eventually." Both rows are still there in 14.3, so the
     * plainer one goes whenever its PSR-3 counterpart survived.
     *
     * @param list<array<string, mixed>> $errors
     *
     * @return list<array<string, mixed>>
     */
    private function dropLegacyExceptionTwins(array $errors): array
    {
        $codes = [];
        foreach ($errors as $error) {
            if ('log' === $error['source'] && isset($error['code'])) {
                $codes[] = '#' . $error['code'];
            }
        }

        if ([] === $codes) {
            return $errors;
        }

        return array_values(array_filter($errors, static function (array $error) use ($codes): bool {
            if (\in_array($error['source'], ['log', 'datahandler'], true)) {
                return true;
            }

            foreach ($codes as $code) {
                if (str_contains((string) $error['message'], $code)) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * @param list<array<string, mixed>> $errors
     *
     * @return list<array<string, mixed>>
     */
    private function collapseRepeats(array $errors): array
    {
        $collapsed = [];

        foreach ($errors as $error) {
            $key = $error['source'] . "\0" . $error['message'];

            if (isset($collapsed[$key])) {
                ++$collapsed[$key]['count'];
                continue;
            }

            $collapsed[$key] = $error + ['count' => 1];
        }

        return array_values($collapsed);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function kindOf(array $row): string
    {
        if (SystemLogType::DB === (int) ($row['type'] ?? 0)) {
            return 'datahandler';
        }

        return '' !== trim((string) ($row['component'] ?? '')) ? 'log' : 'channel';
    }

    /**
     * DatabaseWriter writes time_micro and no tstamp; writelog() does the opposite.
     *
     * @param array<string, mixed> $row
     */
    private function timeOf(array $row): string
    {
        $seconds = (int) ($row['tstamp'] ?? 0);
        if (0 === $seconds) {
            $seconds = (int) (float) ($row['time_micro'] ?? 0);
        }

        return (new \DateTimeImmutable('@' . $seconds))->format(\DateTimeInterface::ATOM);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function dataHandlerFields(array $row): array
    {
        return [
            'message' => $this->formatLogDetails((string) ($row['details'] ?? ''), $row['log_data'] ?? ''),
            'table' => (string) ($row['tablename'] ?? ''),
            'recordUid' => (int) ($row['recuid'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function logFields(array $row): array
    {
        $context = $this->unserializeLogData($row['data'] ?? '') ?? [];

        $fields = [
            'level' => $this->levelName($row['level'] ?? ''),
            'component' => (string) ($row['component'] ?? ''),
            'message' => $this->formatLogDetails((string) ($row['message'] ?? ''), $context),
        ];

        foreach (['class' => 'exception_class', 'code' => 'exception_code', 'file' => 'file', 'line' => 'line'] as $name => $key) {
            if (!isset($context[$key])) {
                continue;
            }

            $fields[$name] = \in_array($name, ['code', 'line'], true)
                ? (int) $context[$key]
                : (string) $context[$key];
        }

        return $fields;
    }

    /**
     * DatabaseWriter stores the numeric level, writelog() the PSR-3 name.
     */
    private function levelName(mixed $level): string
    {
        $level = (string) $level;
        if (!is_numeric($level)) {
            return $level;
        }

        return LogLevel::isValidLevel((int) $level) ? LogLevel::getInternalName((int) $level) : $level;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function messageFields(array $row): array
    {
        return [
            'level' => $this->levelName($row['level'] ?? ''),
            'message' => $this->formatLogDetails((string) ($row['details'] ?? ''), $row['log_data'] ?? ''),
        ];
    }
}

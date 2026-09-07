<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Http;

final class UnknownColumns
{
    /**
     * @var int
     */
    private const SUGGESTION_DISTANCE = 3;

    /**
     * @var int
     */
    private const LONGEST_COLUMN_NAME = 64;

    /**
     * Copied from the switch in DataHandler::fillInFieldArray(), not derived from it.
     *
     * @var list<string>
     */
    private const HANDLED_BY_FILL_IN_FIELD_ARRAY = [
        'uid',
        'pid',
        'l10n_state',
        't3ver_oid',
        't3ver_wsid',
        't3ver_state',
        't3ver_stage',
    ];

    /**
     * @var list<string>
     */
    private const HANDLED_ON_PAGES_ONLY = [
        'perms_userid',
        'perms_groupid',
        'perms_user',
        'perms_group',
        'perms_everybody',
    ];

    /**
     * @param array<string, array<string, array<string, mixed>>>                                $data
     * @param array<string, array{columns?: array<string, mixed>, ctrl?: array<string, mixed>}> $tca
     *
     * @return list<array{table: string, message: string}>
     */
    public static function check(array $data, array $tca): array
    {
        $unknown = [];

        foreach ($data as $table => $records) {
            if (!isset($tca[$table])) {
                $unknown[$table] = [
                    'table' => $table,
                    'message' => sprintf('Unknown table "%s". TCA has no such table.', $table),
                ];

                continue;
            }

            $columns = $tca[$table]['columns'] ?? [];
            $ancestor = $tca[$table]['ctrl']['origUid'] ?? null;

            foreach ($records as $record) {
                foreach (array_keys($record) as $column) {
                    if (self::isAccepted($table, $column, $columns, $ancestor)) {
                        continue;
                    }

                    // Keyed, so a typo repeated across a batch is one entry.
                    $unknown[$table . '.' . $column] = [
                        'table' => $table,
                        'message' => self::message($table, $column, array_keys($columns)),
                    ];
                }
            }
        }

        return array_values($unknown);
    }

    /**
     * @param array<string, mixed> $columns
     */
    private static function isAccepted(string $table, string $column, array $columns, ?string $ancestor): bool
    {
        if (isset($columns[$column])) {
            return true;
        }

        if (in_array($column, self::HANDLED_BY_FILL_IN_FIELD_ARRAY, true)) {
            return true;
        }

        // DataHandler writes this one even though TCA has no such column.
        if ($column === $ancestor) {
            return true;
        }

        return 'pages' === $table && in_array($column, self::HANDLED_ON_PAGES_ONLY, true);
    }

    /**
     * @param list<string> $known
     */
    private static function message(string $table, string $column, array $known): string
    {
        $message = sprintf(
            'Unknown column "%s" on %s. TCA has no such column, '
                . 'so DataHandler would drop it and save the record without it.',
            // The caller picks the length, and it goes into a header.
            substr($column, 0, self::LONGEST_COLUMN_NAME),
            $table
        );

        $suggestion = self::closestTo($column, $known);

        return null === $suggestion ? $message : $message . sprintf(' Did you mean "%s"?', $suggestion);
    }

    /**
     * @param list<string> $known
     */
    private static function closestTo(string $column, array $known): ?string
    {
        $closest = null;
        $shortest = PHP_INT_MAX;

        foreach ($known as $candidate) {
            $distance = levenshtein(strtolower($column), strtolower($candidate));
            if ($distance < $shortest) {
                $shortest = $distance;
                $closest = $candidate;
            }
        }

        return $shortest <= self::SUGGESTION_DISTANCE ? $closest : null;
    }
}

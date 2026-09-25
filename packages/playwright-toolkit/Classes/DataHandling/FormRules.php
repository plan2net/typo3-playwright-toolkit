<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\DataHandling;

use Plan2net\PlaywrightToolkit\Compatibility\FormEngine;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Form\Exception as FormException;
use TYPO3\CMS\Backend\Form\FormDataGroup\OrderedProviderList;
use TYPO3\CMS\Backend\Form\FormDataProvider\DatabaseRecordOverrideValues;
use TYPO3\CMS\Backend\Form\FormDataProvider\DatabaseRecordTypeValue;
use TYPO3\CMS\Backend\Form\Utility\FormEngineUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class FormRules
{
    /**
     * @var list<string>
     */
    private const ACCEPTED_EVERYWHERE = ['uid', 'pid', 'l10n_state', 't3ver_oid', 't3ver_wsid', 't3ver_state', 't3ver_stage'];

    /**
     * @var list<string>
     */
    private const ACCEPTED_FROM_CTRL = [
        'languageField',
        'transOrigPointerField',
        'translationSource',
        'transOrigDiffSourceField',
        'sortby',
        'origUid',
    ];

    /**
     * @param array<string, array<string, array<string, mixed>>> $data
     *
     * @return list<array{table: string, message: string}>
     */
    public static function check(ServerRequestInterface $request, array $data, bool $withRules = true): array
    {
        $compiling = [
            'request' => $request,
            'data' => $data,
            'parents' => self::parentsOf($data),
            'group' => self::providers(),
            'forms' => [],
        ];

        $refused = [];
        foreach ($data as $table => $records) {
            foreach ($records as $identifier => $values) {
                $form = self::formOf($table . ':' . $identifier, $compiling);
                if (null !== $form) {
                    array_push($refused, ...self::refusalsFor($table, (string) $identifier, $values, $form, $withRules));
                }
            }
        }

        return $refused;
    }

    /**
     * @param array{
     *     request: ServerRequestInterface,
     *     data: array<string, array<string, array<string, mixed>>>,
     *     parents: array<string, array{table: string, identifier: string, field: string}>,
     *     group: OrderedProviderList,
     *     forms: array<string, array<string, mixed>|null>
     * } $compiling
     *
     * @return array<string, mixed>|null
     */
    private static function formOf(string $key, array &$compiling): ?array
    {
        if (\array_key_exists($key, $compiling['forms'])) {
            return $compiling['forms'][$key];
        }

        [$table, $identifier] = explode(':', $key, 2);
        $values = $compiling['data'][$table][$identifier];
        $parent = $compiling['parents'][$key] ?? null;
        if (null === $parent) {
            $targets = $compiling['request']->getQueryParams()['edit'] ?? [];
            $page = (int) array_key_first((array) ($targets[$table] ?? []));

            return $compiling['forms'][$key] = self::compile($compiling, $table, $identifier, $values, $page, []);
        }

        $parentForm = self::formOf($parent['table'] . ':' . $parent['identifier'], $compiling);
        if (null === $parentForm) {
            return $compiling['forms'][$key] = null;
        }

        return $compiling['forms'][$key] = self::compile($compiling, $table, $identifier, $values, (int) $parentForm['effectivePid'], [
            'isInlineChild' => true,
            'inlineParentUid' => $parent['identifier'],
            'inlineParentTableName' => $parent['table'],
            'inlineParentFieldName' => $parent['field'],
            'inlineParentConfig' => $parentForm['processedTca']['columns'][$parent['field']]['config'] ?? [],
            'inlineFirstPid' => (int) $parentForm['effectivePid'],
            'inlineTopMostParentUid' => $parent['identifier'],
            'inlineTopMostParentTableName' => $parent['table'],
            'inlineTopMostParentFieldName' => $parent['field'],
        ]);
    }

    /**
     * @param array<string, array<string, array<string, mixed>>> $data
     *
     * @return array<string, array{table: string, identifier: string, field: string}>
     */
    private static function parentsOf(array $data): array
    {
        $parents = [];
        foreach ($data as $table => $records) {
            foreach ($records as $identifier => $values) {
                foreach ($values as $column => $value) {
                    $config = $GLOBALS['TCA'][$table]['columns'][$column]['config'] ?? [];
                    if (!\in_array($config['type'] ?? '', ['inline', 'file'], true)) {
                        continue;
                    }

                    $childTable = 'file' === $config['type'] ? 'sys_file_reference' : ($config['foreign_table'] ?? '');
                    foreach (GeneralUtility::trimExplode(',', (string) $value, true) as $child) {
                        if (isset($data[$childTable][$child])) {
                            $parents[$childTable . ':' . $child] = ['table' => $table, 'identifier' => (string) $identifier, 'field' => $column];
                        }
                    }
                }
            }
        }

        return $parents;
    }

    /**
     * @param array{request: ServerRequestInterface, group: OrderedProviderList} $compiling
     * @param array<string, mixed>                                               $values
     * @param array<string, mixed>                                               $inlineContext
     *
     * @return array<string, mixed>|null
     */
    private static function compile(
        array $compiling,
        string $table,
        string $identifier,
        array $values,
        int $page,
        array $inlineContext,
    ): ?array {
        $isNew = str_starts_with($identifier, 'NEW');

        try {
            return FormEngine::compile([
                'command' => $isNew ? 'new' : 'edit',
                'tableName' => $table,
                'vanillaUid' => $isNew ? $page : (int) $identifier,
                'customData' => [PostedValuesOverlay::KEY => $values],
                ...$inlineContext,
            ], $compiling['group'], $compiling['request']);
        } catch (FormException) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, mixed> $form
     *
     * @return list<array{table: string, message: string}>
     */
    private static function refusalsFor(string $table, string $identifier, array $values, array $form, bool $withRules): array
    {
        $refused = self::permissionRefusals($table, $identifier, $values, $form);
        if (!$withRules) {
            return $refused;
        }

        $parentConfig = $form['inlineParentConfig'] ?? [];
        $accepted = [
            ...self::ACCEPTED_EVERYWHERE,
            ...array_values(array_intersect_key($form['processedTca']['ctrl'], array_flip(self::ACCEPTED_FROM_CTRL))),
            ...array_values(array_intersect_key($parentConfig, array_flip(['foreign_field', 'foreign_sortby', 'foreign_table_field']))),
            ...array_keys($parentConfig['foreign_match_fields'] ?? []),
        ];
        foreach (array_keys($values) as $column) {
            if (!\in_array($column, $accepted, true) && !isset($form['processedTca']['columns'][$column])) {
                $refused[] = [
                    'table' => $table,
                    'message' => sprintf('%s %s: "%s" is not in the form of type "%s".', $table, $identifier, $column, $form['recordTypeValue']),
                ];
            }
        }

        $isTranslation = self::isTranslation($form);
        foreach ($form['processedTca']['columns'] as $column => $configuration) {
            if ($isTranslation && empty($configuration['l10n_display']) && 'exclude' === ($configuration['l10n_mode'] ?? '')) {
                continue;
            }

            if (!self::isEditable($table, $column, $configuration)) {
                continue;
            }

            $fieldTsConfig = $form['pageTsConfig']['TCEFORM.'][$table . '.'][$column . '.'] ?? [];
            if (!empty($fieldTsConfig['disabled'])) {
                continue;
            }

            $config = FormEngineUtility::overrideFieldConf($configuration['config'] ?? [], $fieldTsConfig);
            $defaultAsReadonly = GeneralUtility::inList($configuration['l10n_display'] ?? '', 'defaultAsReadonly');
            if (!empty($config['readOnly']) || ($isTranslation && $defaultAsReadonly)) {
                continue;
            }

            if ('flex' === ($config['type'] ?? '')) {
                array_push($refused, ...self::flexFormRefusals($table, $identifier, $column, $config, (array) ($form['databaseRow'][$column] ?? [])));

                continue;
            }

            $isRelation = \in_array($config['type'] ?? '', ['inline', 'file'], true) && \array_key_exists($column, $values);
            $value = $isRelation ? $values[$column] : ($form['databaseRow'][$column] ?? '');
            foreach (self::violations($column, $config, $value) as $violation) {
                $refused[] = ['table' => $table, 'message' => $table . ' ' . $identifier . ': ' . $violation];
            }
        }

        return $refused;
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, mixed> $form
     *
     * @return list<array{table: string, message: string}>
     */
    private static function permissionRefusals(string $table, string $identifier, array $values, array $form): array
    {
        $refused = [];
        foreach (array_keys($values) as $column) {
            if (!self::isEditable($table, $column, $form['processedTca']['columns'][$column] ?? [])) {
                $refused[] = [
                    'table' => $table,
                    'message' => sprintf('%s %s: "%s" is not editable by user "%s".', $table, $identifier, $column, self::backendUser()->user['username'] ?? ''),
                ];
            }
        }

        return $refused;
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private static function isEditable(string $table, string $column, array $configuration): bool
    {
        return empty($configuration['exclude']) || self::backendUser()->check('non_exclude_fields', $table . ':' . $column);
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $value
     *
     * @return list<array{table: string, message: string}>
     */
    private static function flexFormRefusals(string $table, string $identifier, string $column, array $config, array $value): array
    {
        $refused = [];
        foreach ($config['ds']['sheets'] ?? [] as $sheet => $structure) {
            $prefix = sprintf('%s %s %s, sheet %s: ', $table, $identifier, $column, $sheet);
            foreach ($structure['ROOT']['el'] ?? [] as $field => $element) {
                $data = $value['data'][$sheet]['lDEF'][$field] ?? [];
                if (!empty($element['section'])) {
                    foreach ((array) ($data['el'] ?? []) as $instance) {
                        foreach ((array) $instance as $container => $containerData) {
                            foreach ($element['el'][$container]['el'] ?? [] as $subField => $subElement) {
                                foreach (self::violations((string) $subField, $subElement['config'] ?? [], $containerData['el'][$subField]['vDEF'] ?? '') as $violation) {
                                    $refused[] = ['table' => $table, 'message' => $prefix . $violation];
                                }
                            }
                        }
                    }

                    continue;
                }

                foreach (self::violations((string) $field, $element['config'] ?? [], $data['vDEF'] ?? '') as $violation) {
                    $refused[] = ['table' => $table, 'message' => $prefix . $violation];
                }
            }
        }

        return $refused;
    }

    /**
     * @param array<string, mixed> $form
     */
    private static function isTranslation(array $form): bool
    {
        $pointer = $form['processedTca']['ctrl']['transOrigPointerField'] ?? '';
        $parent = $form['databaseRow'][$pointer] ?? 0;

        return (int) (\is_array($parent) ? reset($parent) : $parent) > 0;
    }

    private static function backendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }

    private static function providers(): OrderedProviderList
    {
        $providers = $GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['formDataGroup']['tcaDatabaseRecord'];
        $providers[PostedValuesOverlay::class] = [
            'depends' => [DatabaseRecordOverrideValues::class],
            'before' => [DatabaseRecordTypeValue::class],
        ];
        $group = GeneralUtility::makeInstance(OrderedProviderList::class);
        $group->setProviderList($providers);

        return $group;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return list<string>
     */
    private static function violations(string $field, array $config, mixed $value): array
    {
        $violations = [];
        $count = match (true) {
            \is_array($value) => \count($value),
            \is_int($value) => $value,
            default => \count(GeneralUtility::trimExplode(',', (string) $value, true)),
        };

        if (FormEngine::isRequired($config) && (\is_array($value) ? [] === $value : '' === ltrim((string) $value))) {
            $violations[] = sprintf('"%s" is required.', $field);
        }

        if (!empty($config['minitems']) && $count < (int) $config['minitems']) {
            $violations[] = sprintf('"%s" needs at least %s, has %d.', $field, self::items((int) $config['minitems']), $count);
        }

        if (!empty($config['maxitems']) && $count > (int) $config['maxitems']) {
            $violations[] = sprintf('"%s" allows at most %s, has %d.', $field, self::items((int) $config['maxitems']), $count);
        }

        $lower = isset($config['range']['lower']) ? (int) $config['range']['lower'] : null;
        $upper = isset($config['range']['upper']) ? (int) $config['range']['upper'] : null;
        if (is_numeric($value) && ((null !== $lower && (int) $value < $lower) || (null !== $upper && (int) $value > $upper))) {
            $violations[] = match (true) {
                null === $upper => sprintf('"%s" must be at least %d, is %d.', $field, $lower, $value),
                null === $lower => sprintf('"%s" must be at most %d, is %d.', $field, $upper, $value),
                default => sprintf('"%s" must be between %d and %d, is %d.', $field, $lower, $upper, $value),
            };
        }

        $length = \is_string($value) ? mb_strlen($value) : 0;
        if (!empty($config['min']) && FormEngine::checksMinimumLength() && $length > 0 && $length < (int) $config['min']) {
            $violations[] = sprintf('"%s" needs at least %d characters, has %d.', $field, $config['min'], $length);
        }

        return $violations;
    }

    private static function items(int $count): string
    {
        return $count . (1 === $count ? ' item' : ' items');
    }
}

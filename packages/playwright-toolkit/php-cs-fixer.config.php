<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/Classes', __DIR__ . '/Configuration', __DIR__ . '/Tests'])
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setFinder($finder)
    ->setRules([
        '@Symfony' => true,
        'concat_space' => ['spacing' => 'one'],
        // A guard throw carries a sprintf message too long for one line.
        'single_line_throw' => false,
        // Symfony omits curly_brace_block, which leaves a blank line before a closing brace.
        'no_extra_blank_lines' => ['tokens' => ['curly_brace_block', 'extra']],
        'function_declaration' => ['closure_fn_spacing' => 'none'],
        'declare_strict_types' => true,
        // A class reads top-down: what it is, what it needs, then what it does,
        // widest visibility first. sort_algorithm "none" keeps the order within
        // each group, so a helper stays where its caller put it.
        'ordered_class_elements' => [
            'sort_algorithm' => 'none',
            'order' => [
                'use_trait',
                'case',
                'constant_public',
                'constant_protected',
                'constant_private',
                'property_public',
                'property_protected',
                'property_private',
                'construct',
                'destruct',
                // The fixer's own group for setUp/tearDown, so they stay at the top
                // instead of sinking below the tests with the other protecteds.
                'phpunit',
                'method_public',
                'method_protected',
                'method_private',
            ],
        ],
    ]);

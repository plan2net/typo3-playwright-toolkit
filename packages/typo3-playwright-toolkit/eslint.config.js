import tseslint from 'typescript-eslint'

export default tseslint.config(
    {
        ignores: ['dist/**', 'node_modules/**'],
    },
    ...tseslint.configs.recommendedTypeChecked,
    {
        languageOptions: {
            parserOptions: {
                projectService: true,
                tsconfigRootDir: import.meta.dirname,
            },
        },
        rules: {
            // An implementation of an async interface member is async because the
            // contract says so, not because its own body waits for anything.
            '@typescript-eslint/require-await': 'off',
            // A class reads top-down: fields, constructor, then behaviour, widest
            // visibility first.
            '@typescript-eslint/member-ordering': [
                'error',
                {
                    default: [
                        'signature',
                        'public-static-field',
                        'protected-static-field',
                        'private-static-field',
                        'public-instance-field',
                        'protected-instance-field',
                        'private-instance-field',
                        'constructor',
                        'public-static-method',
                        'public-instance-method',
                        'protected-static-method',
                        'protected-instance-method',
                        'private-static-method',
                        'private-instance-method',
                    ],
                },
            ],
        },
    },
    {
        // Build configuration, outside the package's own tsconfig.
        files: ['eslint.config.js', 'vitest.config.ts'],
        extends: [tseslint.configs.disableTypeChecked],
    },
    {
        // `#src/*` maps to ./src, which the tarball does not ship. TypeScript
        // emits the specifier unchanged, so one in src/ would resolve to nothing
        // in a consumer's node_modules. It has to be a `regex`: a glob pattern
        // is matched gitignore-style, where a leading `#` starts a comment and
        // the whole entry is silently dropped.
        files: ['src/**/*.ts'],
        rules: {
            'no-restricted-imports': [
                'error',
                {
                    patterns: [
                        {
                            regex: '^#src/',
                            message: 'Use a relative import inside src/ — #src/* resolves only in tests.',
                        },
                    ],
                },
            ],
        },
    },
    {
        // Tests reach into internals and stub globals on purpose.
        files: ['**/*.test.ts', 'tests/**/*.ts'],
        rules: {
            '@typescript-eslint/no-explicit-any': 'off',
            '@typescript-eslint/no-unsafe-assignment': 'off',
            '@typescript-eslint/no-unsafe-member-access': 'off',
            '@typescript-eslint/no-unsafe-argument': 'off',
            '@typescript-eslint/no-unsafe-call': 'off',
            '@typescript-eslint/no-unsafe-return': 'off',
        },
    },
)

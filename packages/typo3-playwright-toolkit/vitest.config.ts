import { defineConfig } from 'vitest/config'

export default defineConfig({
    test: {
        environment: 'node',
        setupFiles: ['./tests/setup.ts'],
        include: ['tests/**/*.test.ts'],
        exclude: ['tests/smoke/node-modules-resolution.test.ts', '**/node_modules/**', '**/dist/**'],
    },
})

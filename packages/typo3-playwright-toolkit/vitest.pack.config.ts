import { defineConfig } from 'vitest/config'

// The one test vitest.config.ts excludes, because it packs the tarball and
// imports from node_modules rather than from src.
export default defineConfig({
    test: {
        environment: 'node',
        include: ['tests/smoke/node-modules-resolution.test.ts'],
        // The setup runs tsc and two npm installs, and one test runs tsc again:
        // minutes in a container, and over the 10s/5s defaults even on a runner.
        hookTimeout: 300_000,
        testTimeout: 120_000,
    },
})

import { defineConfig } from 'vitest/config'

// The one test vitest.config.ts excludes, because it packs the tarball and
// imports from node_modules rather than from src.
export default defineConfig({
    test: {
        environment: 'node',
        include: ['tests/smoke/node-modules-resolution.test.ts'],
    },
})

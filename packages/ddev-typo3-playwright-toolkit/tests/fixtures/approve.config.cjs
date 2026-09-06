module.exports = {
    testMatch: '**/*.spec.cjs',
    outputDir: './custom results',
    snapshotPathTemplate: '{testDir}/baselines/{arg}{ext}',
    reporter: 'line',
    workers: 1,
};

const { test, expect } = require(process.env.APPROVE_PLAYWRIGHT_MODULE);

test('accordion', () => {
    expect(process.env.APPROVE_ACCORDION || 'original').toMatchSnapshot('accordion.txt');
});

test('teaser', () => {
    expect(process.env.APPROVE_TEASER || 'original').toMatchSnapshot('teaser.txt');
});

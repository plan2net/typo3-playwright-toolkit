-- A template record rather than a site set and a site-level setup.typoscript, which
-- are 13.4 and newer: this is the one mechanism every supported core reads.
--
-- Both images render on every page of every scenario, so parallel tests convert the
-- same two files at once, and the crop makes core write a scratch file before the
-- scaling one. The first belongs to no storage and goes through the fallback
-- storage; the second is under fileadmin, whose storage record TYPO3 writes on
-- first use.
INSERT INTO sys_template (uid, pid, title, root, clear, include_static_file, config, deleted, hidden)
VALUES (
    1,
    1,
    'E2E',
    1,
    3,
    'EXT:fluid_styled_content/Configuration/TypoScript/',
    'page = PAGE
page.10 < styles.content.get

page.20 = IMAGE
page.20 {
    file = e2e-images/outside-any-storage.png
    file.width = 120
    file.crop = 20,20,200,120
    altText = outside any storage
}

page.30 = IMAGE
page.30 {
    file = fileadmin/inside-a-storage.png
    file.width = 100
    file.crop = 10,10,180,100
    altText = inside a storage
}',
    0,
    0
);

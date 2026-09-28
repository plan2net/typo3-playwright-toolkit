INSERT INTO pages (uid, pid, title, slug, doktype, is_siteroot, hidden, deleted)
VALUES (2, 0, 'E2E shop root', '/', 1, 1, 0, 0);

INSERT INTO sys_template (uid, pid, title, root, clear, include_static_file, config, deleted, hidden)
VALUES (
    2,
    2,
    'E2E shop',
    1,
    3,
    'EXT:fluid_styled_content/Configuration/TypoScript/',
    'page = PAGE
page.10 < styles.content.get',
    0,
    0
);

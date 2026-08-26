-- Seeded into the template database, so every test database starts with the root
-- page the site configuration points at. The builders hang their pages off uid 1.
INSERT INTO pages (uid, pid, title, slug, doktype, is_siteroot, hidden, deleted)
VALUES (1, 0, 'E2E root', '/', 1, 1, 0, 0);

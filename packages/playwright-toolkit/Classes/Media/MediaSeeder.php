<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Media;

use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class MediaSeeder
{
    /**
     * @var int
     */
    public const STORAGE_UID = 900;

    /**
     * @var string
     */
    public const BASE_PATH = 'fileadmin/playwright-media/';

    /**
     * @var string
     */
    private const STORAGE_NAME = 'Playwright fixtures';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly StorageRepository $storageRepository,
    ) {
    }

    public function seed(string $mediaPath, string $mediaStorage = ''): void
    {
        // The cache can still hold storage rows from another database, and those
        // rows say which directory gets emptied.
        $this->storageRepository->flush();

        $target = $this->resolveTarget($mediaStorage);
        $storage = $this->storageOf($target['uid']);
        self::assertOutsideTheTarget($mediaPath, $target['directory']);

        $root = $storage->getFolder($target['folder']);
        $names = array_merge(
            array_keys(MediaSources::scan($mediaPath)),
            array_keys(MediaSources::onlineMedia($mediaPath))
        );
        foreach ($names as $name) {
            self::assertStorageKeepsTheName($storage, $root, $name);
        }

        $this->clear($storage, $root);

        $files = [];
        foreach (MediaSources::scan($mediaPath) as $name => $path) {
            $files[$name] = self::asFile($storage->addFile(
                $path,
                $this->folderFor($storage, $target['folder'], \dirname($name)),
                basename($name),
                removeOriginal: false
            ));
        }

        foreach (MediaSources::onlineMedia($mediaPath) as $name => $onlineMediaId) {
            $file = self::asFile($storage->createFile(
                basename($name),
                $this->folderFor($storage, $target['folder'], \dirname($name))
            ));
            $file->setContents($onlineMediaId);
            $files[$name] = $file;
        }

        foreach (MediaSources::metadata($mediaPath) as $name => $fields) {
            $files[$name]->getMetaData()->add($fields)->save();
        }

        $this->storageRepository->flush();
    }

    public function destinationMatches(string $mediaPath, string $mediaStorage = ''): bool
    {
        $directory = $this->resolveTarget($mediaStorage)['directory'];

        foreach (MediaSources::scan($mediaPath) as $name => $path) {
            $published = $directory . $name;
            if (!is_file($published) || sha1_file($published) !== sha1_file($path)) {
                return false;
            }
        }

        foreach (MediaSources::onlineMedia($mediaPath) as $name => $onlineMediaId) {
            $published = $directory . $name;
            if (!is_file($published) || trim((string) file_get_contents($published)) !== $onlineMediaId) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, int> name => sys_file uid
     */
    public function readMap(string $mediaStorage = ''): array
    {
        $target = $this->resolveTarget($mediaStorage);

        $rows = $this->connectionPool->getConnectionForTable('sys_file')
            ->executeQuery('SELECT uid, identifier FROM sys_file WHERE storage = ?', [$target['uid']])
            ->fetchAllAssociative();

        $map = [];
        foreach ($rows as $row) {
            $identifier = (string) $row['identifier'];
            // The storage can hold files this feature never put there.
            if (!str_starts_with($identifier, $target['folder'])) {
                continue;
            }

            $map[substr($identifier, \strlen($target['folder']))] = (int) $row['uid'];
        }

        ksort($map);

        return $map;
    }

    /**
     * @return array{uid: int, folder: string, directory: string}
     */
    private function resolveTarget(string $mediaStorage): array
    {
        if ('' === $mediaStorage) {
            return [
                'uid' => self::STORAGE_UID,
                'folder' => '/',
                'directory' => rtrim(Environment::getPublicPath(), '/') . '/' . self::BASE_PATH,
            ];
        }

        [$uid, $folder] = self::parseCombinedIdentifier($mediaStorage);
        if ('/' === $folder) {
            throw new \RuntimeException(sprintf(
                'mediaStorage "%s" names the root of storage %d. Seeding empties what it points at,'
                . ' so it has to name a folder of its own.',
                $mediaStorage,
                $uid
            ));
        }

        // Read from the row: FAL cannot build a storage whose driver is not registered.
        $driver = $this->driverOf($uid);
        if ('' === $driver && $this->storageRepository->findByUid($uid) instanceof ResourceStorage) {
            // TYPO3 writes the fileadmin row itself when the table is empty, and
            // only a repository lookup triggers that.
            $driver = $this->driverOf($uid);
        }

        if ('' === $driver) {
            throw new \RuntimeException(sprintf('mediaStorage names storage %d, which does not exist.', $uid));
        }

        if ('Local' !== $driver) {
            throw new \RuntimeException(sprintf(
                'Storage %d uses the "%s" driver. Media fixtures need a Local storage: the overlap'
                . ' guard compares real paths, preparing must not reach the network, and cleanup'
                . ' only sweeps processed files from Local storages.',
                $uid,
                $driver
            ));
        }

        $storage = $this->storageOf($uid);
        $basePath = trim((string) ($storage->getConfiguration()['basePath'] ?? ''), '/');
        $directory = rtrim(Environment::getPublicPath(), '/') . '/' . $basePath . $folder;
        GeneralUtility::mkdir_deep($directory);

        return ['uid' => $uid, 'folder' => $folder, 'directory' => $directory];
    }

    /**
     * @return array{int, string}
     */
    private static function parseCombinedIdentifier(string $mediaStorage): array
    {
        if (1 !== preg_match('#^(\d+):(/.*)$#', $mediaStorage, $matches)) {
            throw new \RuntimeException(sprintf(
                'mediaStorage has to be a combined identifier such as "1:/playwright-media/", not "%s".',
                $mediaStorage
            ));
        }

        return [(int) $matches[1], rtrim($matches[2], '/') . '/'];
    }

    private function driverOf(int $uid): string
    {
        return (string) $this->connectionPool->getConnectionForTable('sys_file_storage')
            ->executeQuery('SELECT driver FROM sys_file_storage WHERE uid = ?', [$uid])
            ->fetchOne();
    }

    private function storageOf(int $uid): ResourceStorage
    {
        if (self::STORAGE_UID === $uid) {
            return $this->provisionedStorage();
        }

        $storage = $this->storageRepository->findByUid($uid);
        if (!$storage instanceof ResourceStorage) {
            throw new \RuntimeException(sprintf('mediaStorage names storage %d, which does not exist.', $uid));
        }

        return $storage;
    }

    private function provisionedStorage(): ResourceStorage
    {
        $existing = $this->storageRepository->findByUid(self::STORAGE_UID);
        if ($existing instanceof ResourceStorage) {
            return $existing;
        }

        GeneralUtility::mkdir_deep(rtrim(Environment::getPublicPath(), '/') . '/' . self::BASE_PATH);

        $this->connectionPool->getConnectionForTable('sys_file_storage')->insert('sys_file_storage', [
            'uid' => self::STORAGE_UID,
            'pid' => 0,
            'name' => self::STORAGE_NAME,
            'driver' => 'Local',
            'configuration' => self::localDriverConfiguration(),
            'is_default' => 0,
            'is_browsable' => 1,
            'is_public' => 1,
            'is_writable' => 1,
            'is_online' => 1,
        ]);

        $this->storageRepository->flush();

        $storage = $this->storageRepository->findByUid(self::STORAGE_UID);
        if (!$storage instanceof ResourceStorage) {
            throw new \RuntimeException('The media fixture storage was written but cannot be read back.');
        }

        return $storage;
    }

    private static function assertStorageKeepsTheName(ResourceStorage $storage, Folder $root, string $name): void
    {
        foreach (explode('/', $name) as $component) {
            // With no folder the storage resolves its default upload folder, which
            // need not exist.
            $kept = $storage->sanitizeFileName($component, $root);
            if ($kept === $component) {
                continue;
            }

            throw new \RuntimeException(sprintf(
                'The media fixture "%s" would be stored as "%s", because the storage rewrites'
                . ' "%s" to "%s". Rename it so the name it is referenced by is the name it gets.',
                $name,
                str_replace($component, $kept, $name),
                $component,
                $kept
            ));
        }
    }

    private static function assertOutsideTheTarget(string $mediaPath, string $directory): void
    {
        $source = self::canonical($mediaPath);
        $target = self::canonical($directory);

        if ($source !== $target
            && !str_starts_with($source, $target . '/')
            && !str_starts_with($target, $source . '/')
        ) {
            return;
        }

        throw new \RuntimeException(sprintf(
            'The media directory %s overlaps the fixture storage at %s. Seeding empties the storage'
            . ' before it copies anything in, so the sources have to live outside it.',
            $source,
            $target
        ));
    }

    private static function canonical(string $path): string
    {
        return rtrim(realpath($path) ?: $path, '/');
    }

    private static function asFile(FileInterface $file): File
    {
        if (!$file instanceof File) {
            throw new \RuntimeException(sprintf('%s is not an indexed file.', $file->getIdentifier()));
        }

        return $file;
    }

    private function clear(ResourceStorage $storage, Folder $folder): void
    {
        foreach ($storage->getFilesInFolder($folder, 0, 0, false, true) as $file) {
            $storage->deleteFile($file);
        }

        foreach ($storage->getFoldersInFolder($folder, 0, 0, false) as $inside) {
            $storage->deleteFolder($inside, true);
        }
    }

    private function folderFor(ResourceStorage $storage, string $root, string $path): Folder
    {
        $identifier = '.' === $path || '' === $path ? $root : $root . $path;

        return $storage->hasFolder($identifier)
            ? $storage->getFolder($identifier)
            : $storage->createFolder($identifier);
    }

    private static function localDriverConfiguration(): string
    {
        return sprintf(
            '<?xml version="1.0" encoding="utf-8" standalone="yes" ?>
<T3FlexForms>
    <data>
        <sheet index="sDEF">
            <language index="lDEF">
                <field index="basePath">
                    <value index="vDEF">%s</value>
                </field>
                <field index="pathType">
                    <value index="vDEF">relative</value>
                </field>
                <field index="caseSensitive">
                    <value index="vDEF">1</value>
                </field>
            </language>
        </sheet>
    </data>
</T3FlexForms>
',
            self::BASE_PATH
        );
    }
}

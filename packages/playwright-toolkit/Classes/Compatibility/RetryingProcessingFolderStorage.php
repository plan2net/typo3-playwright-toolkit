<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Compatibility;

use TYPO3\CMS\Core\Resource\Exception\ExistingTargetFolderException;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceStorage;

final class RetryingProcessingFolderStorage extends ResourceStorage
{
    /**
     * 11.5 creates each level of a processing folder with a check and a create, and
     * fails the request when a parallel one got in between; 12.4 takes the folder.
     *
     * @param string $folderName
     */
    #[\Override]
    public function createFolder($folderName, ?Folder $parentFolder = null): Folder
    {
        try {
            return parent::createFolder($folderName, $parentFolder);
        } catch (ExistingTargetFolderException) {
            return $this->getFolderInFolder($folderName, $parentFolder ?? $this->getRootLevelFolder(false));
        }
    }
}

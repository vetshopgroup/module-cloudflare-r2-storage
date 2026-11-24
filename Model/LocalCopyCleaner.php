<?php
/**
 * Copyright © MageZero. All rights reserved.
 */
namespace MageZero\CloudflareR2\Model;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Psr\Log\LoggerInterface;

/**
 * Removes media files from pub/media at the end of the request or command, once they are known to be
 * in the bucket: files downloaded from it, files uploaded to it, and files fetched to list a folder.
 *
 * Magento's database media storage, which this module plugs into, treats pub/media as a cache of the
 * storage: code that needs a file it doesn't find there fetches it again (saveFileToFilesystem). On a
 * server whose pub/media isn't thrown away on restart, as it is in a container with a tmpfs, the
 * copies would otherwise stay for good and the folder would keep growing.
 *
 * Kept:
 * - files under a tmp folder: an admin upload is saved there in one request and moved from that local
 *   copy by the next one (category images, theme logos);
 * - anything this request didn't confirm is in the bucket, such as what was there before the switch.
 *
 * Removal waits until shutdown, so code later in the same request still finds the file, and the
 * response (which may send a file from disk) has gone.
 */
class LocalCopyCleaner
{
    private const TMP_FOLDER = '#(^|/)tmp/#';

    private Config $config;
    private WriteInterface $mediaDirectory;
    private Shutdown $shutdown;
    private LoggerInterface $logger;
    /** @var array<string,true> */
    private array $paths = [];
    private bool $registered = false;

    /**
     * @param Config $config
     * @param Filesystem $filesystem
     * @param Shutdown $shutdown
     * @param LoggerInterface $logger
     */
    public function __construct(
        Config $config,
        Filesystem $filesystem,
        Shutdown $shutdown,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->mediaDirectory = $filesystem->getDirectoryWrite(DirectoryList::MEDIA);
        $this->shutdown = $shutdown;
        $this->logger = $logger;
    }

    /**
     * Notes a file, relative to pub/media, that is now in the bucket.
     *
     * @param string $relativePath
     * @return void
     */
    public function track(string $relativePath): void
    {
        $relativePath = ltrim($relativePath, '/');
        if ($relativePath === ''
            || preg_match(self::TMP_FOLDER, $relativePath)
            || in_array('..', explode('/', $relativePath), true)
        ) {
            return;
        }

        $this->paths[$relativePath] = true;
        if (!$this->registered) {
            $this->registered = true;
            $this->shutdown->register([$this, 'clean']);
        }
    }

    /**
     * Removes the noted files that are still on disk. Run at shutdown.
     *
     * @return void
     */
    public function clean(): void
    {
        $paths = array_keys($this->paths);
        $this->paths = [];
        if (!$paths || !$this->config->isR2Selected() || !$this->config->removesLocalCopies()) {
            return;
        }

        foreach ($paths as $path) {
            try {
                if ($this->mediaDirectory->isFile($path)) {
                    $this->mediaDirectory->delete($path);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('R2: failed to remove local copy', [
                    'path' => $path,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}

<?php
namespace MageZero\CloudflareR2\Test\Unit\Model;

use MageZero\CloudflareR2\Model\Config;
use MageZero\CloudflareR2\Model\LocalCopyCleaner;
use MageZero\CloudflareR2\Model\Shutdown;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class LocalCopyCleanerTest extends TestCase
{
    private Config $config;
    private WriteInterface $media;
    private Shutdown $shutdown;
    private LoggerInterface $logger;
    /** @var string[] files on disk, relative to pub/media */
    private array $onDisk = [];
    /** @var string[] */
    private array $deleted = [];
    private LocalCopyCleaner $cleaner;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->config->method('isR2Selected')->willReturn(true);
        $this->media = $this->createMock(WriteInterface::class);
        $this->media->method('isFile')->willReturnCallback(fn($path) => in_array($path, $this->onDisk, true));
        $this->media->method('delete')->willReturnCallback(function ($path) {
            $this->deleted[] = $path;
            return true;
        });
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('getDirectoryWrite')->willReturn($this->media);
        $this->shutdown = $this->createMock(Shutdown::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->cleaner = new LocalCopyCleaner($this->config, $filesystem, $this->shutdown, $this->logger);
    }

    public function testCleanRemovesTrackedFilesStillOnDisk(): void
    {
        $this->config->method('removesLocalCopies')->willReturn(true);
        $this->onDisk = ['catalog/product/a/b/ab.jpg', 'catalog/product/cache/700x700/a/b/ab.jpg'];

        $this->cleaner->track('catalog/product/a/b/ab.jpg');
        $this->cleaner->track('/catalog/product/cache/700x700/a/b/ab.jpg');
        $this->cleaner->track('wysiwyg/gone.png');
        $this->cleaner->clean();

        $this->assertSame(['catalog/product/a/b/ab.jpg', 'catalog/product/cache/700x700/a/b/ab.jpg'], $this->deleted);
    }

    public function testEachFileIsRemovedOnce(): void
    {
        $this->config->method('removesLocalCopies')->willReturn(true);
        $this->onDisk = ['catalog/product/a/b/ab.jpg'];

        $this->cleaner->track('catalog/product/a/b/ab.jpg');
        $this->cleaner->track('catalog/product/a/b/ab.jpg');
        $this->cleaner->clean();
        $this->cleaner->clean();

        $this->assertSame(['catalog/product/a/b/ab.jpg'], $this->deleted);
    }

    /**
     * An admin upload is saved under a tmp folder in one request and moved from that local copy by the
     * next one (category images, theme logos), so it must still be on disk then.
     */
    public function testUploadsWaitingInATmpFolderAreKept(): void
    {
        $this->config->method('removesLocalCopies')->willReturn(true);
        $this->onDisk = ['catalog/product/tmp/a/b/ab.jpg', 'catalog/tmp/category/c.jpg', 'tmp/design/file/logo.png'];

        foreach ($this->onDisk as $path) {
            $this->cleaner->track($path);
        }
        $this->cleaner->clean();

        $this->assertSame([], $this->deleted);
    }

    public function testPathsOutsideTheMediaFolderAreIgnored(): void
    {
        $this->config->method('removesLocalCopies')->willReturn(true);
        $this->onDisk = ['../app/etc/env.php', 'catalog/../../app/etc/env.php'];

        $this->cleaner->track('../app/etc/env.php');
        $this->cleaner->track('catalog/../../app/etc/env.php');
        $this->cleaner->clean();

        $this->assertSame([], $this->deleted);
    }

    public function testCleanIsRegisteredForShutdownOnTheFirstTrackedFile(): void
    {
        $this->shutdown->expects($this->once())->method('register')
            ->with([$this->cleaner, 'clean']);

        $this->cleaner->track('catalog/product/a/b/ab.jpg');
        $this->cleaner->track('catalog/product/c/d/cd.jpg');
    }

    public function testNothingIsRegisteredWhenNothingIsTracked(): void
    {
        $this->shutdown->expects($this->never())->method('register');

        $this->cleaner->track('catalog/product/tmp/a/b/ab.jpg');
    }

    public function testNothingIsRemovedWhenSwitchedOff(): void
    {
        $this->config->method('removesLocalCopies')->willReturn(false);
        $this->onDisk = ['catalog/product/a/b/ab.jpg'];

        $this->cleaner->track('catalog/product/a/b/ab.jpg');
        $this->cleaner->clean();

        $this->assertSame([], $this->deleted);
    }

    public function testNothingIsRemovedWhenR2IsNotTheMediaStorage(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isR2Selected')->willReturn(false);
        $config->method('removesLocalCopies')->willReturn(true);
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('getDirectoryWrite')->willReturn($this->media);
        $cleaner = new LocalCopyCleaner($config, $filesystem, $this->shutdown, $this->logger);
        $this->onDisk = ['catalog/product/a/b/ab.jpg'];

        $cleaner->track('catalog/product/a/b/ab.jpg');
        $cleaner->clean();

        $this->assertSame([], $this->deleted);
    }

    public function testAFailedRemovalIsLoggedAndTheOthersStillRemoved(): void
    {
        $this->config->method('removesLocalCopies')->willReturn(true);
        $media = $this->createMock(WriteInterface::class);
        $media->method('isFile')->willReturn(true);
        $media->method('delete')->willReturnCallback(function ($path) {
            if ($path === 'catalog/product/locked.jpg') {
                throw new \RuntimeException('Permission denied');
            }
            $this->deleted[] = $path;
            return true;
        });
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('getDirectoryWrite')->willReturn($media);
        $this->logger->expects($this->once())->method('warning');
        $cleaner = new LocalCopyCleaner($this->config, $filesystem, $this->shutdown, $this->logger);

        $cleaner->track('catalog/product/locked.jpg');
        $cleaner->track('catalog/product/free.jpg');
        $cleaner->clean();

        $this->assertSame(['catalog/product/free.jpg'], $this->deleted);
    }
}

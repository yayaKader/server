<?php
/**
 * @copyright Copyright (c) 2018, Roeland Jago Douma <roeland@famdouma.nl>
 *
 * @author Roeland Jago Douma <roeland@famdouma.nl>
 *
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace Test\Preview;

use OC\Files\AppData\Factory;
use OC\Preview\BackgroundCleanupJob;
use OC\Preview\Storage\Root;
use OC\PreviewManager;
use OC\SystemConfig;
use OCP\Files\File;
use OCP\Files\IMimeTypeLoader;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IDBConnection;
use Test\Traits\MountProviderTrait;
use Test\Traits\UserTrait;

/**
 * Class BackgroundCleanupJobTest
 *
 * @group DB
 *
 * @package Test\Preview
 */
class BackgroundCleanupJobTest extends \Test\TestCase {

	use MountProviderTrait;
	use UserTrait;

	/** @var string */
	private $userId;

	/** @var bool */
	private $trashEnabled;

	/** @var IDBConnection */
	private $connection;

	/** @var PreviewManager */
	private $previewManager;

	/** @var IRootFolder */
	private $rootFolder;

	/** @var IMimeTypeLoader */
	private $mimeTypeLoader;

	protected function setUp(): void {
		parent::setUp();

		$this->userId = $this->getUniqueID();
		$this->createUser($this->userId, $this->userId);

		$storage = new \OC\Files\Storage\Temporary([]);
		$this->registerMount($this->userId, $storage, '');

		$this->loginAsUser($this->userId);
		$this->logout();
		$this->loginAsUser($this->userId);

		$appManager = \OC::$server->getAppManager();
		$this->trashEnabled = $appManager->isEnabledForUser('files_trashbin', $this->userId);
		$appManager->disableApp('files_trashbin');

		$this->connection = \OC::$server->getDatabaseConnection();
		$this->previewManager = \OC::$server->getPreviewManager();
		$this->rootFolder = \OC::$server->getRootFolder();
		$this->mimeTypeLoader = \OC::$server->getMimeTypeLoader();
	}

	protected function tearDown(): void {
		if ($this->trashEnabled) {
			$appManager = \OC::$server->getAppManager();
			$appManager->enableApp('files_trashbin');
		}

		$this->logout();

		parent::tearDown();
	}

	private function getRoot(): Root {
		return new Root(
			\OC::$server->getRootFolder(),
			\OC::$server->getSystemConfig()
		);
	}

	private function setup11Previews(): array {
		$userFolder = $this->rootFolder->getUserFolder($this->userId);

		$files = [];
		for ($i = 0; $i < 11; $i++) {
			$file = $userFolder->newFile($i.'.txt');
			$file->putContent('hello world!');
			$this->previewManager->getPreview($file);
			$files[] = $file;
		}

		return $files;
	}

	private function countPreviews(Root $previewRoot, array $fileIds): int {
		$i = 0;

		foreach ($fileIds as $fileId) {
			try {
				$previewRoot->getFolder((string)$fileId);
				var_dump('Found: ' . $fileId);
			} catch (NotFoundException $e) {
				continue;
			}

			$i++;
		}

		return $i;
	}

	public function testCleanupSystemCron() {
		$files = $this->setup11Previews();
		$fileIds = array_map(function (File $f) {
			return $f->getId();
		}, $files);

		$root = $this->getRoot();

		$this->assertSame(11, $this->countPreviews($root, $fileIds));
		$job = new BackgroundCleanupJob($this->connection, $root, $this->mimeTypeLoader, true);
		$job->run([]);

		foreach ($files as $file) {
			$file->delete();
		}

		$root = $this->getRoot();
		$this->assertSame(11, $this->countPreviews($root, $fileIds));
		$job->run([]);

		$root = $this->getRoot();
		$this->assertSame(0, $this->countPreviews($root, $fileIds));
	}

	public function XtestCleanupAjax() {
		$files = $this->setup11Previews();

		$preview = $this->appDataFactory->get('preview');

		$previews = $preview->getDirectoryListing();
		$this->assertCount(11, $previews);

		$job = new BackgroundCleanupJob($this->connection, $this->appDataFactory, $this->mimeTypeLoader, false);
		$job->run([]);

		foreach ($files as $file) {
			$file->delete();
		}

		$this->assertCount(11, $previews);
		$job->run([]);

		$previews = $preview->getDirectoryListing();
		$this->assertCount(1, $previews);

		$job->run([]);

		$previews = $preview->getDirectoryListing();
		$this->assertCount(0, $previews);
	}
}

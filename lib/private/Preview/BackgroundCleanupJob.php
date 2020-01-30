<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2018, Roeland Jago Douma <roeland@famdouma.nl>
 *
 * @author Roeland Jago Douma <roeland@famdouma.nl>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OC\Preview;

use OC\BackgroundJob\TimedJob;
use OC\Files\AppData\Factory;
use OC\Preview\Storage\Root;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\IMimeTypeLoader;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IDBConnection;

class BackgroundCleanupJob extends TimedJob {

	/** @var IDBConnection */
	private $connection;

	/** @var Root */
	private $previewFolder;

	/** @var bool */
	private $isCLI;

	/** @var IMimeTypeLoader */
	private $mimeTypeLoader;

	public function __construct(IDBConnection $connection,
								Root $previewFolder,
								IMimeTypeLoader $mimeTypeLoader,
								bool $isCLI) {
		// Run at most once an hour
		$this->setInterval(3600);

		$this->connection = $connection;
		$this->previewFolder = $previewFolder;
		$this->isCLI = $isCLI;
		$this->mimeTypeLoader = $mimeTypeLoader;
	}

	public function run($argument) {
		foreach ($this->getDeletedFiles() as $fileId) {
			try {
				$preview = $this->previewFolder->getFolder($fileId);
				$preview->delete();
			} catch (NotFoundException $e) {
				// continue
			} catch (NotPermittedException $e) {
				// continue
			}
		}
	}

	private function getDeletedFiles(): \Iterator {
		yield from $this->getOldPreviewLocations();
		yield from $this->getNewPreviewLocations();
	}

	private function getOldPreviewLocations(): \Iterator {
		$qb = $this->connection->getQueryBuilder();
		$qb->selectDistinct('a.name')
			->from('filecache', 'a')
			->leftJoin('a', 'filecache', 'b', $qb->expr()->eq(
				$qb->expr()->castColumn('a.name', IQueryBuilder::PARAM_INT), 'b.fileid'
			))
			->leftJoin('a', 'filecache', 'c', $qb->expr()->eq(
				'a.fileid', 'c.parent'
			))
			->where(
				$qb->expr()->andX(
					$qb->expr()->isNull('b.fileid'),
					$qb->expr()->eq('a.parent', $qb->createNamedParameter($this->previewFolder->getId())),
					$qb->expr()->eq('a.mimetype', $qb->createNamedParameter($this->mimeTypeLoader->getId('httpd/unix-directory'))),
					$qb->expr()->neq('c.mimetype', $qb->createNamedParameter($this->mimeTypeLoader->getId('httpd/unix-directory')))
				)
			);

		if (!$this->isCLI) {
			$qb->setMaxResults(10);
		}

		$cursor = $qb->execute();

		while ($row = $cursor->fetch()) {
			yield $row['name'];
		}

		$cursor->closeCursor();
	}

	private function getNewPreviewLocations(): \Iterator {
		$qb = $this->connection->getQueryBuilder();
		$qb->select('path', 'mimetype')
			->from('filecache')
			->where($qb->expr()->eq('fileid', $qb->createNamedParameter($this->previewFolder->getId())));
		$cursor = $qb->execute();
		$data = $cursor->fetch();
		$cursor->closeCursor();

		if ($data === null) {
			return [];
		}

		$like = $data['path'] . '/_/_/_/_/_/_/_/%';

		$qb = $this->connection->getQueryBuilder();
		$qb->select('a.name')
			->from('filecache', 'a')
			->leftJoin('a', 'filecache', 'b', $qb->expr()->eq(
				$qb->expr()->castColumn('a.name', IQueryBuilder::PARAM_INT), 'b.fileid'
			))
			->where(
				$qb->expr()->andX(
					$qb->expr()->isNull('b.fileid'),
					$qb->expr()->like('a.path', $qb->createNamedParameter($like)),
					$qb->expr()->eq('a.mimetype', $qb->createNamedParameter($this->mimeTypeLoader->getId('httpd/unix-directory')))
				)
			);

		if (!$this->isCLI) {
			$qb->setMaxResults(10);
		}

		$cursor = $qb->execute();

		while ($row = $cursor->fetch()) {
			yield $row['name'];
		}

		$cursor->closeCursor();
	}
}

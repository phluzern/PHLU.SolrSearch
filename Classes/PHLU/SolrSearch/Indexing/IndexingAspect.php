<?php
namespace PHLU\SolrSearch\Indexing;
use TYPO3\Flow\Annotations as Flow;

/*                                                                        *
 * This script belongs to the TYPO3 Flow package "PHLU.SolrSearch".       *
 *                                                                        *
 * It is free software; you can redistribute it and/or modify it under    *
 * the terms of the GNU Lesser General Public License, either version 3   *
 * of the License, or (at your option) any later version.                 *
 *                                                                        *
 * The TYPO3 project - inspiring people to share!                         *
 *                                                                        */

/**
 * @Flow\Aspect
 */
class IndexingAspect {

	/**
	 * @var \PHLU\SolrSearch\Domain\Repository\IndexQueueRepository
	 * @Flow\Inject
	 */
	protected $indexQueueRepository;

	/**
	 * @var \TYPO3\Flow\Persistence\PersistenceManagerInterface
	 * @Flow\Inject
	 */
	protected $persistenceManager;

	/**
	 * Mark a file as deleted in the index queue when it's removed from phlu_portal_domain_model_file
	 *
	 * @param \TYPO3\Flow\AOP\JoinPointInterface $joinPoint
	 * @Flow\After("method(PHLU\Portal\Domain\Model\Filebrowser->removeFile())")
	 * @return void
	 */
	public function removeFileFromSearchIndex(\TYPO3\Flow\AOP\JoinPointInterface $joinPoint) {
		/** @var \PHLU\Portal\Domain\Model\File $file */
		$file = $joinPoint->getMethodArgument('file');
		/** @var \PHLU\SolrSearch\Domain\Model\IndexQueue $indexQueueItem */
		$indexQueueItem = $this->indexQueueRepository->findOneByResource($file->getId());
		if (is_object($indexQueueItem)) {
			$indexQueueItem->setDeleted(new \TYPO3\Flow\Utility\Now);
			$this->indexQueueRepository->update($indexQueueItem);
		}
	}

	/**
	 * Put a file to the index queue when it's added to phlu_portal_domain_model_file
	 *
	 * @param \TYPO3\Flow\AOP\JoinPointInterface $joinPoint
	 * @Flow\After("method(PHLU\Portal\Domain\Model\Filebrowser->addFile())")
	 * @return void
	 */
	public function addFileToSearchIndex(\TYPO3\Flow\AOP\JoinPointInterface $joinPoint) {
		$table = 'phlu_portal_domain_model_file';

		/** @var \PHLU\Portal\Domain\Model\File $file */
		$file = $joinPoint->getMethodArgument('file');

		/** @var \PHLU\SolrSearch\Domain\Model\IndexQueue $indexJob */
		$indexQueueItem = new \PHLU\SolrSearch\Domain\Model\IndexQueue;
		$indexQueueItem->setResourceModel($table);
		$indexQueueItem->setResource($file->getId());
		$indexQueueItem->setFileBrowser($file->getOriginal_filebrowser());
		$this->indexQueueRepository->add($indexQueueItem);

	}

}
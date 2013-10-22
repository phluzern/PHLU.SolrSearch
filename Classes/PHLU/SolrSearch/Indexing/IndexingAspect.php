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
	 * Log a message if a post is deleted
	 *
	 * @param \TYPO3\Flow\AOP\JoinPointInterface $joinPoint
	 * @Flow\After("method(PHLU\Portal\Domain\Model\Filebrowser->removeFile())")
	 * @return void
	 */
	public function removeFileFromSearchIndex(\TYPO3\Flow\AOP\JoinPointInterface $joinPoint) {
		/** @var \PHLU\Portal\Domain\Model\File $file */
		$file = $joinPoint->getMethodArgument('file');
\TYPO3\Flow\var_dump('hier');
		/** @var \PHLU\SolrSearch\Domain\Model\IndexQueue $indexQueueItem */
		$indexQueueItem = $this->indexQueueRepository->findOneByResource($file->getId());
		\TYPO3\Flow\var_dump($indexQueueItem, 'indexQueueItem');
		if (is_object($indexQueueItem)) {
			\TYPO3\Flow\var_dump('hier2');

			$indexQueueItem->setDeleted(new \TYPO3\Flow\Utility\Now);
		}

	}

}
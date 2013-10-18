<?php
namespace PHLU\SolrSearch\Domain\Repository;

/*                                                                        *
 * This script belongs to the TYPO3 Flow package "PHLU.SolrSearch".       *
 *                                                                        *
 *                                                                        */

use TYPO3\Flow\Annotations as Flow;
use TYPO3\Flow\Persistence\Repository;

/**
 * @Flow\Scope("singleton")
 */
class IndexQueueRepository extends Repository {

	/**
	 * @Flow\Inject
	 * @var \Doctrine\Common\Persistence\ObjectManager
	 */
	protected $entityManager;

	/**
	 * Put all resources of a certain table to index queue
	 *
	 * Because we're handling with a very big number of files, we use native SQL here
	 *
	 * @param string $table source table
	 * @param string $whereClause where clause for insert query
	 */
	public function putResourcesToQueue($table, $whereClause) {

		$sql = 'INSERT IGNORE INTO phlu_solrsearch_domain_model_indexqueue (persistence_object_identifier, resourceModel, resource)
			SELECT uuid(), \'' . $table . '\', source.persistence_object_identifier FROM ' . $table . ' AS source ' .
			$whereClause;

		/** @var $sqlConnection \Doctrine\DBAL\Connection */
		$sqlConnection = $this->entityManager->getConnection();
		$sqlConnection->executeUpdate($sql);

	}

	/**
	 * Find a number of files of a certain resourceModel
	 *
	 * @param $limit
	 * @param $table
	 * @return \TYPO3\Flow\Persistence\QueryResultInterface
	 */
	public function findItemsToIndex($limit, $table) {

		$query = $this->createQuery();
		$query->matching(
			$query->logicalAnd(
				$query->equals('indexed', NULL),
				$query->equals('error', NULL),
				$query->equals('resourceModel', $table)
			)
		);
		$query->setLimit($limit);
		return $query->execute();

	}

}
?>


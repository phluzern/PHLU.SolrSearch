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

		$sql = 'INSERT IGNORE INTO phlu_solrsearch_domain_model_indexqueue (persistence_object_identifier, resourcemodel, resource, filebrowser)
			SELECT uuid(), \'' . $table . '\', source.persistence_object_identifier, source.original_filebrowser FROM ' . $table . ' AS source ' .
			$whereClause;

		/** @var $sqlConnection \Doctrine\DBAL\Connection */
		$sqlConnection = $this->entityManager->getConnection();
		$sqlConnection->executeUpdate($sql);

	}

	/**
	 * Update items in the index
	 *
	 * Because we're handling with a very big number of files, we use native SQL here
	 *
	 * @param $table
	 * @param string $setClause set clause for update query
	 * @param string $whereClause where clause for update query
	 */
	public function updateIndexItems($table, $setClause, $whereClause) {

		$sql = 'UPDATE phlu_solrsearch_domain_model_indexqueue ' . $setClause . ' ' . $whereClause . ' AND resourcemodel = \'' . $table . '\'';

		/** @var $sqlConnection \Doctrine\DBAL\Connection */
		$sqlConnection = $this->entityManager->getConnection();
		$sqlConnection->executeUpdate($sql);

	}

	/**
	 * Find a number of files of a certain resourceModel
	 *
	 * @param $limit
	 * @param $table
	 * @param array $fileBrowsers
	 * @return \TYPO3\Flow\Persistence\QueryResultInterface
	 */
	public function findItemsToIndex($limit, $table, $fileBrowsers = NULL) {

		$query = $this->createQuery();

		$queryConstraints = array();
		$queryConstraints[] = $query->equals('indexed', NULL);
		$queryConstraints[] = $query->equals('deleted', NULL);
		$queryConstraints[] = $query->equals('error', NULL);
		$queryConstraints[] = $query->equals('resourceModel', $table);
		if ($fileBrowsers) {
			// if specific fileBrowsers are requested, add constraint
			$queryConstraints[] = $query->in('fileBrowser', $fileBrowsers);
		}

		$query->matching(
			$query->logicalAnd(
				$queryConstraints
			)
		);
		$query->setLimit($limit);
		return $query->execute();

	}

	/**
	 * Find all files that need to be removed from the Solr index
	 *
	 * @return \TYPO3\Flow\Persistence\QueryResultInterface
	 */
	public function findItemsToDelete() {

		$query = $this->createQuery();
		$query->matching(
			$query->logicalNot(
				$query->equals('deleted', NULL)
			)
		);
		return $query->execute();

	}

}
?>


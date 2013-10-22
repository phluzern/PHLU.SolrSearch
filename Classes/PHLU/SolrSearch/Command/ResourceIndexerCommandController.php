<?php
namespace PHLU\SolrSearch\Command;

/*                                                                        *
 * This script belongs to the TYPO3 Flow package "PHLU.SolrSearch".       *
 *                                                                        *
 *                                                                        */

use TYPO3\Flow\Annotations as Flow;

/**
 * @Flow\Scope("singleton")
 */
class ResourceIndexerCommandController extends \TYPO3\Flow\Cli\CommandController {

	/**
	 * @var array
	 */
	protected $settings = array();

	/**
	 * Injects the Flow settings, only the persistence part is kept for further use
	 *
	 * @param array $settings
	 * @return void
	 */
	public function injectSettings(array $settings) {
		$this->settings = $settings;
	}

	/**
	 * @var \PHLU\SolrSearch\Domain\Repository\IndexQueueRepository
	 * @Flow\Inject
	 */
	protected $indexQueueRepository;

	/**
	 * @var \PHLU\Portal\Domain\Repository\FileRepository
	 * @Flow\Inject
	 */
	protected $fileRepository;

	/**
	 * @var \PHLU\SolrSearch\Service\SolrService
	 * @Flow\Inject
	 */
	protected $solrService;

	/**
	 * @var \PHLU\SolrSearch\Service\TikaService
	 * @Flow\Inject
	 */
	protected $tikaService;

	/**
	 * @var
	 */
	protected $solrClient;

	/**
	 * Puts existing resources to the index queue
	 *
	 * The comment of this command method is also used for TYPO3 Flow's help screens. The first line should give a very short
	 * summary about what the command does. Then, after an empty line, you should explain in more detail what the command
	 * does. You might also give some usage example.
	 *
	 * It is important to document the parameters with param tags, because that information will also appear in the help
	 * screen.
	 *
	 * @return void
	 */
	public function putResourcesToQueueCommand() {

		$table = 'phlu_portal_domain_model_file';
		$whereClause = "WHERE path NOT LIKE 'Moodle%' AND resource IS NOT NULL";
		$this->indexQueueRepository->putResourcesToQueue($table, $whereClause);
		$this->outputLine('Alle Resourcen, die noch nicht in der Index-Queue sind, wurden in die Index-Queue gestellt.');

	}

	/**
	 * Puts the given number of items to the Solr index
	 *
	 * The comment of this command method is also used for TYPO3 Flow's help screens. The first line should give a very short
	 * summary about what the command does. Then, after an empty line, you should explain in more detail what the command
	 * does. You might also give some usage example.
	 *
	 * It is important to document the parameters with param tags, because that information will also appear in the help
	 * screen.
	 *
	 * @return void
	 */
	public function queueWorkerCommand() {
		$table = 'phlu_portal_domain_model_file';
		$this->solrClient = $this->solrService->getSolrClient($this->settings['server']);
		// we proceed if the Solr server is reachable
		if (is_object($this->solrClient->ping())) {

			$jobs = $this->indexQueueRepository->findItemsToIndex(20, $table);
			foreach ($jobs as $job) {
				$resource = $this->fileRepository->findByIdentifier($job->getResource());
				$success = $this->addResourceToIndex($resource, $table);
				if ($success) {
					$job->setIndexed(new \TYPO3\Flow\Utility\Now);
				}
				$this->indexQueueRepository->update($job);
			}

			// commit items
			$this->solrClient->commit();
		}


		//$this->outputLine($this->solrClient->ping());
	}

	/**
	 * @param $table
	 * @param $resource \PHLU\Portal\Domain\Model\File
	 */
	public function addResourceToIndex($resource, $table) {

		$resourceStreamPointer = FLOW_PATH_DATA . $this->settings['tika']['resourcesPathRelativeFromFlowDataPath'] . $resource->getSha1();


		if (is_file($resourceStreamPointer)) {

			$appKey = 'PHLU.SolrSearch';

			$document = new \SolrInputDocument();

			$document->addField('id', $appKey . '/' . $table . '/' . $resource->getId());
			$document->addField('uuid', $resource->getId());
			$document->addField('appKey', $appKey);
			$document->addField('type', $table);

			$document->addField('resourceCollection', $resource->getOriginal_repository());

			// Content from resource
			$resourceContent = $this->tikaService->extractText($resourceStreamPointer, $this->settings['tika']);
			if ($resourceContent === FALSE) {
				$this->outputLine('Tika Fehler: Kein Inhalt für ' . $resourceStreamPointer);
			} else {
				$document->addField('content', $resourceContent);
			}

			// Metadata from resource
			$resourceMetadata = $this->tikaService->extractMetadata($resourceStreamPointer, $this->settings['tika']);
			if ($resourceMetadata === FALSE) {
				$this->outputLine('Tika Fehler: Keine Metdaten für ' . $resourceStreamPointer);
				// Set document title to file name in this case
				$document->addField('title', $resource->getResource()->getFilename());
			} else {
				$document->addField('title', !empty($resourceMetadata['title']) ? $resourceMetadata['title'] : '');
				$document->addField('abstract', $resource->getSha1());
				$document->addField('author', !empty($resourceMetadata['Author']) ? $resourceMetadata['Author'] : '');
				$document->addField('fileMimeType', !empty($resourceMetadata['Content-Type']) ? $resourceMetadata['Content-Type'] : '');
				$document->addField('keywords',  !empty($resourceMetadata['Keywords']) ? $resourceMetadata['Keywords'] : '');


			}

			// Default for field indexed in Solr: NOW
			$document->addField('created', self::timestampToIso($resource->getMetadata()->getCreated()->getTimestamp()));
			$document->addField('lastModified', self::timestampToIso($resource->getMetadata()->getLastmodified()->getTimestamp()));
			//		$document->addField('breadcrumb', 'TODO: BREADCRUMB');



			//TODO getter for description missing
			//		$document->addField('description', $resource->getMetadata()->getDescription());
			//TODO getter for creator missing
			// bei Moodle: $resource->getExternalresource
			//		$document->addField('url', $resource->getSha1());

			$document->addField('fileName', $resource->getResource()->getFilename());
			$document->addField('fileExtension', $resource->getResource()->getFileExtension());
			$document->addField('fileRelativePath', $resource->getPath());
			$document->addField('fileRelativePathOnly', $resource->getPath());
			$document->addField('fileSha1', $resource->getSha1());


			try {
				$updateResponse = $this->solrClient->addDocument($document);
			} catch (Exception $e) {
				$this->outputLine('Dokument ungültig.');
			}

			try {
				$updateResponse = $updateResponse->getResponse();
				$this->outputLine('Hinzugefügt: ' . $resource->getName());
				return TRUE;
			} catch (Exception $e) {
				$this->outputLine('Fehler beim Hinzufügen: ' . $resource->getName());
				return FALSE;
			}
		} else {
			$this->outputLine('Nicht zum Index hinzugefügt da Datei nicht gefunden: ' . $resourceStreamPointer);
			return FALSE;
		}

	}

	/**
	 * Converts a date from unix timestamp to ISO 8601 format.
	 * TODO move to utility
	 *
	 * @param	integer	unix timestamp
	 * @return	string	the date in ISO 8601 format
	 */
	public static function timestampToIso($timestamp) {
		return date('Y-m-d\TH:i:s\Z', $timestamp);
	}

}
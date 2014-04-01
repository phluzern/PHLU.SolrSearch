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
	 * @var \PHLU\Portal\Domain\Repository\FileconnectorRepository
	 * @Flow\Inject
	 */
	protected $fileConnectorRepository;

	/**
	 * @var \PHLU\Portal\Domain\Repository\FilebrowserRepository
	 * @Flow\Inject
	 */
	protected $filebrowserRepository;

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
	 * Puts all existing resources to the index queue
	 *
	 * The comment of this command method is also used for TYPO3 Flow's help screens. The first line should give a very short
	 * summary about what the command does. Then, after an empty line, you should explain in more detail what the command
	 * does. You might also give some usage example.
	 *
	 * @return void
	 */
	public function putAllResourcesToQueueCommand() {
		$table = 'phlu_portal_domain_model_file';
		$whereClause = "WHERE path IS NOT NULL AND (resource IS NOT NULL OR externalresource IS NOT NULL)";
		$this->indexQueueRepository->putResourcesToQueue($table, $whereClause);
		$this->outputLine('Alle Resourcen, die noch nicht in der Index-Queue sind, wurden in die Index-Queue gestellt.');
	}

	/**
	 * Queues all items for reindexing (without emptying the index first)
	 *
	 * The comment of this command method is also used for TYPO3 Flow's help screens. The first line should give a very short
	 * summary about what the command does. Then, after an empty line, you should explain in more detail what the command
	 * does. You might also give some usage example.
	 *
	 * @param boolean $onlyErrors Only queue reindexing for items that have errors
	 * @return void
	 */
	public function queueReindexingCommand($onlyErrors = FALSE) {
		$table = 'phlu_portal_domain_model_file';
		if ($onlyErrors) {
			$setClause = 'SET error=NULL, indexed=NULL';
			$whereClause = 'WHERE error IS NOT NULL AND deleted IS NULL';
		} else {
			$setClause = 'SET error=NULL, indexed=NULL';
			$whereClause = 'WHERE deleted IS NULL';
		}

		$this->indexQueueRepository->updateIndexItems($table, $setClause, $whereClause);
		$this->outputLine('Reindexierung wurde geplant. Die Resourcen werden nun mit dem queueWorker neu indexiert.');
	}

	/**
	 * Puts the given number of items to the Solr index
	 *
	 * Takes the indicated number of items to be indexed from the queue and adds them to the search index.
	 *
	 * @param integer $filesPerRun number of files being indexed in one run
	 * @return void
	 */
	public function queueWorkerCommand($filesPerRun = 20) {
		$table = 'phlu_portal_domain_model_file';
		$this->solrClient = $this->solrService->getSolrClient($this->settings['server']);

		// we proceed if the Solr server is reachable
		if (is_object($this->solrClient->ping())) {

			$jobs = $this->indexQueueRepository->findItemsToIndex($filesPerRun, $table);
			foreach ($jobs as $job) {
				$resource = $this->fileRepository->findByIdentifier($job->getResource());
				if (is_object($resource)) {
					$success = $this->addResourceToIndex($resource, $table);
					if ($success) {
						$job->setIndexed(new \TYPO3\Flow\Utility\Now);
					} else {
						$job->setError(new \TYPO3\Flow\Utility\Now);
					}
				} else {
					$job->setError(new \TYPO3\Flow\Utility\Now);
					$this->outputLine('Fehler: Resource ' . $job->getResource() . ' nicht gefunden.');
				}
				$this->indexQueueRepository->update($job);
			}

			// commit items
			$this->solrClient->commit();
		} else {
			$this->outputLine('Fehler: Solr-Server nicht erreichbar.');
		}
	}

	/**
	 * Empty the Solr index
	 *
	 * Removes all (!) documents from the Solr index, respecting the appKey
	 *
	 * @param boolean $force Force clearing the whole index, not only the index for the current appKey
	 * @return void
	 */
	public function emptyIndexCommand($force = FALSE) {
		$this->solrClient = $this->solrService->getSolrClient($this->settings['server']);
		// we proceed if the Solr server is reachable
		if (is_object($this->solrClient->ping())) {
			if ($force) {
				$this->solrClient->deleteByQuery('*:*');
			} else {
				$this->solrClient->deleteByQuery('appKey:' . $this->settings['server']['appKey']);
			}
			$this->solrClient->commit();
			$this->outputLine('Der Solr-Index wurde geleert.');
		} else {
			$this->outputLine('Fehler: Solr-Server nicht erreichbar.');
		}
	}

	/**
	 * Garbage collector
	 *
	 * Removes all deleted resources from the Solr index
	 *
	 * @return void
	 */
	public function garbageCollectorCommand() {
		$this->solrClient = $this->solrService->getSolrClient($this->settings['server']);
		// we proceed if the Solr server is reachable
		if (is_object($this->solrClient->ping())) {
			$filesToDelete = $this->indexQueueRepository->findItemsToDelete();

			foreach ($filesToDelete as $fileToDelete) {
				$appKey = 'PHLU.SolrSearch';
				// example: PHLU.SolrSearch/phlu_portal_domain_model_file/00024887-63FF-F56F-3961-9F434A3E3CB6
				$fileId = $appKey . '/' . $fileToDelete->getResourceModel() . '/' . $fileToDelete->getResource();
				$this->solrClient->deleteByQuery('id:' . $fileId);
				// delete item from index queue
				$this->indexQueueRepository->remove($fileToDelete);
				$this->outputLine('Resource als Solr-Index gelöscht: ' . $fileId);
			}
			$this->solrClient->commit();
		} else {
			$this->outputLine('Fehler: Solr-Server nicht erreichbar.');
		}
	}

	/**
	 * Add a resource to the index
	 *
	 * @param \PHLU\Portal\Domain\Model\File $resource
	 * @param string $table
	 *
	 * @return boolean
	 */
	public function addResourceToIndex($resource, $table) {
		$solrDocument = $this->getSolrDocumentForResource($resource, $table);

		if ($solrDocument !== FALSE) {
			try {
				$updateResponse = $this->solrClient->addDocument($solrDocument);
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
		}

	}

	/**
	 * Creates a SolrInputDocument object for a resource, returns FALSE if a file could not be found
	 *
	 * @param \PHLU\Portal\Domain\Model\File $resource
	 * @param $table
	 * @return bool|\SolrInputDocument
	 */
	public function getSolrDocumentForResource(\PHLU\Portal\Domain\Model\File $resource, $table) {
		$appKey = $this->settings['server']['appKey'];
		$document = new \SolrInputDocument();

		$document->addField('id', $appKey . '/' . $table . '/' . $resource->getId());
		$document->addField('uuid', $resource->getId());
		$document->addField('appKey', $appKey);
		$document->addField('type', $table);

		$document->addField('breadcrumb', $this->getBreadCrumbFromPath($resource->getPath(), $resource->getOriginal_filebrowser()));

		if (!is_string($resource->getMetadata()->getName())) {
			// we can't add a resource without file name to the index
			$this->outputLine('Nicht zum Index hinzugefügt da Datei keinen Namen hat: ' . $resource->getId());
			return FALSE;
		}
		$document->addField('title', $resource->getMetadata()->getName());
		$document->addField('fileRelativePath', $resource->getPath());
		$document->addField('fileSha1', $resource->getSha1());

		// unused fields, getters missing
		//		$document->addField('description', $resource->getMetadata()->getDescription());
		//		$document->addField('creator', $resource->getMetadata()->getCreator());


		$document->addField('resourceCollection', $resource->getOriginal_filebrowser());

		// fields specific to the type of the file (virtual or non-virtual)
		if ($resource->getExternalresource() === NULL) {
			// this is a "normal" document that is present on the server

			if (!is_object($resource->getResource()) || (is_object($resource->getResource() && !is_string($resource->getResource()->getFilename())))) {
				// early return if the resource doesn't exist or the file name is empty
				$this->outputLine('Nicht zum Index hinzugefügt da Dateiname leer');
				return FALSE;
			}

			$document->addField('fileName', $resource->getResource()->getFilename());
			$document->addField('fileExtension', $resource->getResource()->getFileExtension());

			$resourceStreamPointer = FLOW_PATH_DATA . $this->settings['tika']['resourcesPathRelativeFromFlowDataPath'] . $resource->getSha1();


			if (is_file($resourceStreamPointer)) {
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
					$document->addField('created', self::timestampToIso($resource->getMetadata()->getCreated()->getTimestamp()));
					$document->addField('changed', self::timestampToIso($resource->getMetadata()->getLastmodified()->getTimestamp()));
				} else {
					// title from documents is unreliable users are not force to set it
					//$document->addField('title', !empty($resourceMetadata['title']) ? $resourceMetadata['title'] : '');
					$document->addField('abstract', $resource->getSha1());
					$document->addField('author', !empty($resourceMetadata['Author']) ? $resourceMetadata['Author'] : '');
					$document->addField('fileMimeType', !empty($resourceMetadata['Content-Type']) ? $resourceMetadata['Content-Type'] : '');
					$document->addField('keywords',  !empty($resourceMetadata['Keywords']) ? $resourceMetadata['Keywords'] : '');
					$document->addField('created', !empty($resourceMetadata['Creation-Date']) ? self::getSolrCompliantDate($resourceMetadata['Creation-Date']) : self::timestampToIso($resource->getMetadata()->getCreated()->getTimestamp()));
					$document->addField('changed', !empty($resourceMetadata['Last-Modified']) ? self::getSolrCompliantDate($resourceMetadata['Last-Modified']) : self::timestampToIso($resource->getMetadata()->getLastmodified()->getTimestamp()));
				}
			} else {
				// early return if the file doesn't exist
				$this->outputLine('Nicht zum Index hinzugefügt da Datei nicht gefunden: ' . $resourceStreamPointer);
				return FALSE;
			}
		} else {
			// we have a virtual Moodle document that is only linked
			if ($resource->getExternalresource() === 'https://moodle.phlu.ch') {
				// if it is no file in Moodle, quit
				$this->outputLine('Nicht zum Index hinzugefügt da externalresource nicht auf eine Datei zeigt: ' . $resource->getName());
				return FALSE;
			}
			$document->addField('url', $resource->getExternalresource());

			// since we have no resource, we must extract the fileName and fileExtension ourselves
			$document->addField('fileName', $resource->getName());
			$fileExtension = pathinfo($resource->getName(), PATHINFO_EXTENSION);
			$document->addField('fileExtension', $fileExtension);
		}

		return $document;

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

	/**
	 * Convert a date to an UNIX timestamp
	 * TODO move to utility
	 *
	 * @param $date
	 * @return bool|string
	 */
	public static function dateToTimestamp($date) {
		return date('U', strtotime($date));
	}

	/**
	 * Check a date for Solr compliance (ISO 8601) and convert it to ISO 8601 if it isn't
	 * TODO move to utility
	 *
	 * @param $date
	 * @return string
	 */
	public static function getSolrCompliantDate($date) {
		if (substr($date, -1) === 'Z') {
			return $date;
		} else {
			$timestamp = self::dateToTimestamp($date);
			return self::timestampToIso($timestamp);
		}
	}

	/**
	 * @param $path string
	 * @param $resourceCollection string the ID of the resource collection
	 *
	 * @return string HTML code of Breadcrumb
	 */
	public static function getBreadCrumbFromPath($path, $resourceCollection) {
		// example path: Dozierende/_PH_Luzern/Bilder_PH_Luzern/Einzelbilder/Personen/WB/A5_quer
		$pathParts = explode('/', $path);
		// we don't need the first part because it is the identifier of the resourceCollection
		//unset($pathParts[0]);
        $breadCrumbItems = array();
        $absolutePath = "";
        foreach ($pathParts as $pathPart) {
            if ($absolutePath == "") {} else {$absolutePath .= "/";}
            $absolutePath .= $pathPart;
            $breadCrumbItems[] = '<a href="#" onclick="return filebrowser_open_path(\'' . $resourceCollection . '\', \'' . md5($absolutePath) . '\',true)">' . $pathPart . '</a>';
        }

        return implode('<span class="pathSeparator">&gt;</span>', $breadCrumbItems);

	}

}

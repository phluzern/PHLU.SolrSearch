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
				$success = $this->addResourceToIndex($resource, $table);
				if ($success) {
					$job->setIndexed(new \TYPO3\Flow\Utility\Now);
				} else {
					$job->setError(new \TYPO3\Flow\Utility\Now);
				}
				$this->indexQueueRepository->update($job);
			}

			// commit items
			$this->solrClient->commit();
		}
	}

	/**
	 * Emty the Solr index
	 *
	 * Removes all (!) documents from the Solr index
	 *
	 * @return void
	 */
	public function emptyIndexCommand() {
		$this->solrClient = $this->solrService->getSolrClient($this->settings['server']);
		// we proceed if the Solr server is reachable
		if (is_object($this->solrClient->ping())) {
			$this->solrClient->deleteByQuery("*:*");
			$this->solrClient->commit();
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

		$resourceStreamPointer = FLOW_PATH_DATA . $this->settings['tika']['resourcesPathRelativeFromFlowDataPath'] . $resource->getSha1();


		if (is_file($resourceStreamPointer)) {

			$appKey = 'PHLU.SolrSearch';

			$document = new \SolrInputDocument();

			$document->addField('id', $appKey . '/' . $table . '/' . $resource->getId());
			$document->addField('uuid', $resource->getId());
			$document->addField('appKey', $appKey);
			$document->addField('type', $table);


			/** @var \PHLU\Portal\Domain\Model\Filebrowser $filebrowser */
			$filebrowser = $this->filebrowserRepository->getFilebrowserByFileNoAccessCheck($resource)->getFirst();
			$document->addField('resourceCollection', $filebrowser->getId());

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

			$document->addField('breadcrumb', $this->getBreadCrumbFromPath($resource->getMetadata()->getOriginal_path(), $resource->getMetadata()->getOriginal_repository()));

			// unused fields, getters missing
			//		$document->addField('description', $resource->getMetadata()->getDescription());
			//		$document->addField('creator', $resource->getMetadata()->getCreator());

			// bei Moodle: $resource->getExternalresource
			//		$document->addField('url', $resource->getSha1());

			$document->addField('title', $resource->getMetadata()->getName());
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
		foreach ($pathParts as $pathPart) {
			$breadCrumbItems[] = '<a href="#" onclick="return filebrowser_open_path(\'' . $resourceCollection . '\', \'' . $pathPart . '\')">' . $pathPart . '</a>';
		}

		return implode('<span class="pathSeparator">&gt;</span>', $breadCrumbItems);
	}

}
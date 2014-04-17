<?php
namespace PHLU\SolrSearch\Controller;

/*                                                                        *
 * This script belongs to the TYPO3 Flow package "PHLU.SolrSearch".       *
 *                                                                        *
 *                                                                        */

use TYPO3\Flow\Annotations as Flow;

class SearchController extends \TYPO3\Flow\Mvc\Controller\ActionController {

	/**
	 * @var \PHLU\SolrSearch\Service\SolrService
	 * @Flow\Inject
	 */
	protected $solrService;

	/**
	 * @Flow\Inject
	 * @var \PHLU\Portal\Domain\Repository\FilebrowserRepository
	 */
	protected $filebrowserRepository;

	/**
	 * @var
	 */
	protected $solrClient;

	/**
	 * @return string
	 */
	public function indexAction() {

		$template = new \TYPO3\Fluid\View\StandaloneView();
		$template->setTemplatePathAndFilename('resource://PHLU.SolrSearch/Private/Templates/Search/Index.html');

		// get all file browsers the current user has permissions to use
		$filebrowsers = $this->filebrowserRepository->get_filebrowsers();
		$template->assign('filebrowsers', $filebrowsers);

		// get all media types
		$template->assign('documentTypes', $this->settings['documentTypes']);

		/*$requestArguments = $this->request->getArguments();
		if (!empty($requestArguments)) {
			// if the filter tab is opened
			$displayFilters = FALSE;

			$template->assign('requestArguments', $requestArguments);
			$template->assign('searchFiltersClass', $displayFilters ? 'searchFilters' : 'searchFilters-hidden');
		}*/

		return $template->renderSection('Content');

	}

	/**
	 *
	 * @param array $demand
	 * @param integer $nextPage
	 * @param integer $previousPage
	 * @return void
	 */
	public function resultsAction($demand = NULL, $nextPage = NULL, $previousPage = NULL) {

		// get all file browsers the current user has permissions to use
		$filebrowsers = $this->filebrowserRepository->get_filebrowsers();

		// only query files that the user has access to
		$filebrowserUuids = array();
		foreach ($filebrowsers as $filebrowser) {
			$filebrowserUuids[] = $filebrowser->getId();
			$filebrowserNames[$filebrowser->getId()] = $filebrowser->getName();
		}

		$this->view->assign('filebrowsers', $filebrowsers);

		// get all media types
		$this->view->assign('documentTypes', $this->settings['documentTypes']);

		if ($demand) {
			if (isset($nextPage)) {
				$demand['nextPage'] = $nextPage;
			}
			if (isset($previousPage)) {
				$demand['previousPage'] = $previousPage;
			}
			$response = $this->getSolrResponseForDemand($demand, $filebrowserUuids);
			$this->view->assign('solrDocs', $response->response->docs);
			$this->view->assign('hl', $response->highlighting);
			$this->view->assign('numberOfDocuments', $response->response->numFound);
			$this->view->assign('firstDocumentIndex', $response->response->start+1);
			$lastCalculatedDocument = (int)$response->response->start + $this->settings['results']['resultsPerPage'];
			$lastDocument = $lastCalculatedDocument <= $response->response->numFound ? $lastCalculatedDocument : $response->response->numFound;
			$this->view->assign('lastDocumentIndex', $lastDocument);
			$this->view->assign('requestArguments', $demand);
		}

	}

	/**
	 * Return the number of documents found for every filebrowser the user has access to
	 *
	 * @param array $demand
	 * @return string
	 */
	public function returnCountForFileBrowsersAction($demand = NULL) {
		if ($demand) {

			// get all file browsers the current user has permissions to use
			$filebrowsers = $this->filebrowserRepository->get_filebrowsers();

			$countResultsForFileBrowserArray = array();

			if (array_key_exists('filter', $demand)) {
				if (array_key_exists('resourceCollection', $demand['filter'])) {
					unset($demand['filter']['resourceCollection']);
				}
			}

			foreach ($filebrowsers as $filebrowser) {
				/** @var \PHLU\Portal\Domain\Model\Filebrowser $filebrowser */
				$resultForFileBrowser = $this->getSolrResponseForDemand($demand, array($filebrowser->getId()))->response->numFound;
				$countResultsForFileBrowserArray[] = array($filebrowser->getId() => $resultForFileBrowser);
			}

			return json_encode($countResultsForFileBrowserArray);

		} else {
			return FALSE;
		}
	}


	/**
	 * Build and perform a Solr query for a given demand
	 *
	 * @param $demand
	 * @param array $filebrowserUuids
	 * @internal param string $fileBrowser the file browser to build the constraint with, all if not set
	 * @return mixed
	 */
	public function getSolrResponseForDemand($demand, $filebrowserUuids = array()) {

		$this->solrClient = $this->solrService->getSolrClient($this->settings['server']);

		// generate query and set general settings
		$query = new \SolrQuery();
		$offset = 0;
		if (isset($demand['nextPage'])) {
			$lastOffset = (int)$demand['nextPage'];
			$offset = $lastOffset + $this->settings['results']['resultsPerPage'];
		}
		if (isset($demand['previousPage'])) {
			$lastOffset = (int)$demand['previousPage'];
			$offset = $lastOffset - $this->settings['results']['resultsPerPage'];
		}
		// the current offset
		$this->view->assign('offset', $offset);
		// the calcutalted maximum item is to determine whether we show the next button or not
		$calculatedMaximumItem = $offset + $this->settings['results']['resultsPerPage'];
		$this->view->assign('calculatedMaximumItem', $calculatedMaximumItem);

		$query->setStart($offset);
		$query->setRows($this->settings['results']['resultsPerPage']);

		/* search based on query string */
		$queryString = !empty($demand['query']) ? '*' . self::sanitizeTerm($demand['query']) . '*': '*:*';
		// general query settings
		$query->setQuery($queryString);

		/* respect the appKey */
		$query->addFilterQuery('appKey:' . $this->settings['server']['appKey']);

		/* search based on filters, all filters are OR for the moment */
		// TODO check if filter and filter value are valid
		if (is_array($demand['filter']) && !empty($demand['filter'])) {
			foreach ($demand['filter'] as $key => $filterType) {
				if (!empty($filterType)) {
					$filterValues = '';
					for ($i = 0; $i < count($filterType); $i++) {
						if ($i === 0) {
							$filterValues .= $filterType[$i];
						} else {
							$filterValues .= ' OR ' . $filterType[$i];
						}
						// register requested filebrowser filters
						//if ($key == 'resourceCollection') $demand[] = $filebrowserNames[$filterType[$i]];
					}
					$query->addFilterQuery($key . ':(' . $filterValues . ')');
				}
			}


		}

		// documentType filter
		if (array_key_exists('documentType', $demand) && !empty($demand['documentType'])) {
			$filterValues = array();
			foreach ($demand['documentType'] as $documentType) {
				$fileExtensionArray = $this->settings['documentTypes'][$documentType]['fileExtensions'];
				foreach ($fileExtensionArray as $fileExtension) {
					$filterValues[] = $fileExtension;
				}
			}
			$filterValues = implode(' OR ', $filterValues);
			$query->addFilterQuery('fileExtension:(' . $filterValues . ')');
		}


		if (is_array($filebrowserUuids)) {
			$query->addFilterQuery('resourceCollection:(' . implode(' OR ', $filebrowserUuids) . ')');
		}

		// normal fields
		$query->addField('id')
			->addField('uuid')
			->addField('title')
			->addField('changed')
			->addField('author')
			->addField('fileRelativePath')
			->addField('fileSha1')
			->addField('fileMimeType')
			->addField('fileExtension')
			->addField('fileName')
			->addField('breadcrumb')
			->addField('score')
			->addField('url')
			->addField('changed');

		// configuration for highlighting the query string in results
		$query->setHighlight(TRUE);
		$query->setHighlightFormatter('html');
		$query->setHighlightSimplePre($this->settings['results']['highlighting']['prefix']);
		$query->setHighlightSimplePost($this->settings['results']['highlighting']['suffix']);
		$query->setHighlightFragsize($this->settings['results']['highlighting']['fragmentSize']);

		// highlight fields
		$query->addHighlightField('content');

		// sorting of results
		if (empty($demand['query'])) {
			// if we have no query string, we show the latest documents first
			$query->addSortField('changed', \SolrQuery::ORDER_DESC);
		} else {
			if (isset($demand['sorting'])) {
				// sorting should be set by default
				$sortingField = (string)$demand['sorting'];
				$query->addSortField($sortingField, \SolrQuery::ORDER_DESC);
			}
		}

		// perform query
		$queryResponse = $this->solrClient->query($query);
		return $queryResponse->getResponse();
	}

	/**
	 * Escape a term
	 *
	 * A term is a single word.
	 * All characters that have a special meaning in a Solr query are escaped.
	 *
	 * @link http://lucene.apache.org/java/docs/queryparsersyntax.html#Escaping%20Special%20Characters
	 *
	 * @param string $input
	 * @return string
	 */
	static public function sanitizeTerm($input)	{
		$pattern = '/(\+|-|&&|\|\||!|\(|\)|\{|}|\[|]|\^|"|~|\*|\?|:|\\\)/';
		return preg_replace($pattern, '\\\$1', $input);
	}

}

?>
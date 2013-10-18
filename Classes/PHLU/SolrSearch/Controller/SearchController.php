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
	 * @return void
	 */
	public function indexAction() {

		// get all file browsers the current user has permissions to use
		$filebrowsers = $this->filebrowserRepository->get_filebrowsers();
		$this->view->assign('filebrowsers', $filebrowsers);

		// get all media types
		$this->view->assign('documentTypes', $this->settings['documentTypes']);

		$requestArguments = $this->request->getArguments();
		if (!empty($requestArguments)) {
			// if the filter tab is opened
			$displayFilters = FALSE;

			$this->view->assign('requestArguments', $requestArguments);
			$this->view->assign('searchFiltersClass', $displayFilters ? 'searchFilters' : 'searchFilters-hidden');
		}

	}
		/**
	 * @return void
	 */
	public function resultsAction() {

		// get all file browsers the current user has permissions to use
		$filebrowsers = $this->filebrowserRepository->get_filebrowsers();
		$this->view->assign('filebrowsers', $filebrowsers);

		// get all media types
		$this->view->assign('documentTypes', $this->settings['documentTypes']);

		$requestArguments = $this->request->getArguments();
		if (!empty($requestArguments)) {
			$this->solrClient = $this->solrService->getSolrClient($this->settings['server']);

			// generate query and set general settings
			$query = new \SolrQuery();
			$offset = 0;
			if (isset($requestArguments['nextPage'])) {
				$lastOffset = (int)$requestArguments['nextPage'];
				$offset = $lastOffset + $this->settings['results']['resultsPerPage'];
			}
			if (isset($requestArguments['previousPage'])) {
				$lastOffset = (int)$requestArguments['previousPage'];
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
			$queryString = !empty($requestArguments['query']) ? self::sanitizeTerm($requestArguments['query']) : '*:*';
			// general query settings
			$query->setQuery($queryString);

			/* search based on filters, all filters are OR for the moment */
			// TODO check if filter and filter value are valid
			if (is_array($requestArguments['filter']) && !empty($requestArguments['filter'])) {
				foreach ($requestArguments['filter'] as $key => $filterType) {
					if (!empty($filterType)) {
						$filterValues = '';
						for ($i = 0; $i < count($filterType); $i++) {
							if ($i === 0) {
								$filterValues .= $filterType[$i];
							} else {
								$filterValues .= ' OR ' . $filterType[$i];
							}
						}
						$query->addFilterQuery($key . ':(' . $filterValues . ')');
					}
				}
			}

			/* documentType filter */
			if (is_array($requestArguments['documentType']) && !empty($requestArguments['documentType'])) {
				$filterValues = array();
				foreach ($requestArguments['documentType'] as $documentType) {
					$fileExtensionArray = $this->settings['documentTypes'][$documentType]['fileExtensions'];
					foreach ($fileExtensionArray as $fileExtension) {
						$filterValues[] = $fileExtension;
					}
				}
				$filterValues = implode(' OR ', $filterValues);
				$query->addFilterQuery('fileExtension:(' . $filterValues . ')');
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
			if (empty($requestArguments['query'])) {
				// if we have no query string, we show the latest documents first
				$query->addSortField('changed', \SolrQuery::ORDER_DESC);
			} else {
				if (isset($requestArguments['sorting'])) {
					// sorting should be set by default
					$sortingField = (string)$requestArguments['sorting'];
					$query->addSortField($sortingField, \SolrQuery::ORDER_DESC);
				}
			}
			$queryResponse = $this->solrClient->query($query);
			$response = $queryResponse->getResponse();
			$this->view->assign('solrDocs', $response->response->docs);
			$this->view->assign('hl', $response->highlighting);
			$this->view->assign('numberOfDocuments', $response->response->numFound);
			$this->view->assign('firstDocumentIndex', $response->response->start+1);
			$lastCalculatedDocument = (int)$response->response->start + $this->settings['results']['resultsPerPage'];
			$lastDocument = $lastCalculatedDocument <= $response->response->numFound ? $lastCalculatedDocument : $response->response->numFound;
			$this->view->assign('lastDocumentIndex', $lastDocument);
			$this->view->assign('requestArguments', $requestArguments);
		}

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
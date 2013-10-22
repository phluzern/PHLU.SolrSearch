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
	 * @var
	 */
	protected $solrClient;

	/**
	 * @return void
	 */
	public function indexAction() {

		$requestArguments = $this->request->getArguments();
		\TYPO3\Flow\var_dump($requestArguments, 'requestArguments');
		if (!empty($requestArguments)) {
			$this->solrClient = $this->solrService->getSolrClient($this->settings['server']);

			// generate query and set general settings
			$query = new \SolrQuery();
			$query->setStart(0);
			$query->setRows(50);


			/* search based on query string */
			// TODO sanitize
			$queryString = !empty($requestArguments['query']) ? $requestArguments['query'] : '*.*';
			// We return an empty query string for the Fluid template
			$queryStringReturn = !empty($requestArguments['query']) ? $requestArguments['query'] : '';
			// general query settings
			$query->setQuery($queryString);

			/* search based on facets, all filters are OR for the moment */
			// TODO check if filter is valid
			if (is_array($requestArguments['filter'])) {
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

			// normal fields
			$query->addField('id')
				->addField('uuid')
				->addField('title')
				->addField('changed')
				->addField('author')
				->addField('fileRelativePath')
				->addField('fileSha1')
				->addField('fileMimeType')
				->addField('fileName');

			// configuration for highlighting the query string in results
			$query->setHighlight(TRUE);
			$query->setHighlightFormatter('html');
			$query->setHighlightSimplePre($this->settings['results']['highlighting']['prefix']);
			$query->setHighlightSimplePost($this->settings['results']['highlighting']['suffix']);
			$query->setHighlightFragsize($this->settings['results']['highlighting']['fragmentSize']);

			// highlight fields
			$query->addHighlightField('content');
			$queryResponse = $this->solrClient->query($query);

			// boost by relevance
			$query->addSortField('title');

			$response = $queryResponse->getResponse();
			$this->view->assign('solrDocs', $response->response->docs);
			$this->view->assign('hl', $response->highlighting);
			$this->view->assign('queryString', $queryStringReturn);

		}

	}

}

?>
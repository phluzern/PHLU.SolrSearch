<?php
namespace PHLU\SolrSearch\Domain\Model;

/*                                                                        *
 * This script belongs to the TYPO3 Flow package "PHLU.SolrSearch".       *
 *                                                                        *
 *                                                                        */

use TYPO3\Flow\Annotations as Flow;
use Doctrine\ORM\Mapping as ORM;

/**
 * @Flow\Entity
 */
class ResourceIndex {

	/**
	 * @var string
	 */
	protected $resourceModel;

	/**
	 * @var string
	 */
	protected $resource;

	/**
	 * @var \DateTime
	 */
	protected $changed;

	/**
	 * @var \DateTime
	 */
	protected $indexed;

	/**
	 * @var string
	 */
	protected $solrDocumentId;


	/**
	 * @return string
	 */
	public function getResourceModel() {
		return $this->resourceModel;
	}

	/**
	 * @param string $resourceModel
	 * @return void
	 */
	public function setResourceModel($resourceModel) {
		$this->resourceModel = $resourceModel;
	}

	/**
	 * @return \PHLU\Portal\Domain\Model\File
	 */
	public function getResource() {
		return $this->resource;
	}

	/**
	 * @param \PHLU\Portal\Domain\Model\File $resource
	 * @return void
	 */
	public function setResource(\PHLU\Portal\Domain\Model\File $resource) {
		$this->resource = $resource;
	}

	/**
	 * @return \DateTime
	 */
	public function getChanged() {
		return $this->changed;
	}

	/**
	 * @param \DateTime $changed
	 * @return void
	 */
	public function setChanged($changed) {
		$this->changed = $changed;
	}

	/**
	 * @return \DateTime
	 */
	public function getIndexed() {
		return $this->indexed;
	}

	/**
	 * @param \DateTime $indexed
	 * @return void
	 */
	public function setIndexed($indexed) {
		$this->indexed = $indexed;
	}

	/**
	 * @return string
	 */
	public function getSolrDocumentId() {
		return $this->solrDocumentId;
	}

	/**
	 * @param string $solrDocumentId
	 * @return void
	 */
	public function setSolrDocumentId($solrDocumentId) {
		$this->solrDocumentId = $solrDocumentId;
	}

}
?>
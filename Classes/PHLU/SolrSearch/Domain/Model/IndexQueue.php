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
class IndexQueue {

	/**
	 * @var string
	 */
	protected $resourceModel;

	/**
	 * @var string
	 * @ORM\Column(type="string", unique=true)
	 */
	protected $resource;

	/**
	 * @var \DateTime
	 * @ORM\Column(type="datetime", nullable=true)
	 */
	protected $deleted;

	/**
	 * @var \DateTime
	 * @ORM\Column(type="datetime", nullable=true)
	 */
	protected $indexed;

	/**
	 * @var \DateTime
	 * @ORM\Column(type="datetime", nullable=true)
	 */
	protected $error;

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
	 * @return string
	 */
	public function getResource() {
		return $this->resource;
	}

	/**
	 * @param string $resource
	 * @return void
	 */
	public function setResource($resource) {
		$this->resource = $resource;
	}

	/**
	 * @return \DateTime
	 */
	public function getDeleted() {
		return $this->deleted;
	}

	/**
	 * @param \DateTime $deleted
	 * @return void
	 */
	public function setDeleted($deleted) {
		$this->deleted = $deleted;
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
	 * @return \DateTime
	 */
	public function getError() {
		return $this->error;
	}

	/**
	 * @param \DateTime $error
	 * @return void
	 */
	public function setError($error) {
		$this->error = $error;
	}

}
?>
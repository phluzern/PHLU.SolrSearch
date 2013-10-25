<?php
namespace PHLU\SolrSearch\Service;

/*                                                                        *
 * This script belongs to the TYPO3 Flow package "PHLU.SolrSearch".       *
 *                                                                        *
 * It is free software; you can redistribute it and/or modify it under    *
 * the terms of the GNU Lesser General Public License, either version 3   *
 * of the License, or (at your option) any later version.                 *
 *                                                                        *
 * The TYPO3 project - inspiring people to share!                         *
 *                                                                        */

class TikaService {

	/**
	 * Extract text from a file using Apache Tika
	 *
	 * @param $inputFile string
	 * @param $tikaSettings array
	 * @return mixed
	 */
	public function extractText($inputFile, $tikaSettings) {

		$tikaCommand = 'java -Dfile.encoding=UTF8 -jar \'' . $tikaSettings['path'] . '\' -t ' . escapeshellarg($inputFile);
		$shellOutput = shell_exec($tikaCommand);

		// TODO Logging actions

		if (empty($shellOutput)) {
			return FALSE;
		} else {
			return $shellOutput;
		}

	}

	/**
	 * Extract metadata from a file using Apache Tika
	 *
	 * @param $inputFile string
	 * @param $tikaSettings array
	 * @return mixed
	 */
	public function extractMetadata($inputFile, $tikaSettings) {

		$tikaCommand = 'java -Dfile.encoding=UTF8 -jar \'' . $tikaSettings['path'] . '\' -m ' . escapeshellarg($inputFile);

		$shellOutput = array();
		exec($tikaCommand, $shellOutput);

		// TODO Logging actions

		if (empty($shellOutput)) {
			return FALSE;
		} else {
			$metaData = $this->shellOutputToArray($shellOutput);
			return $metaData;
		}

	}

	 /**
	 * Takes shell output from exec() and turns it into an array of key => value
	 * meta data pairs.
	 *
	 * @param       array   An array containing shell output from exec() with one line per entry
	 * @return      array   Array of key => value pairs of meta data
	 */
	 protected function shellOutputToArray(array $shellOutputMetaData) {
		 $metaData = array();

		 foreach ($shellOutputMetaData as $line) {
			 list($dataName, $dataValue) = explode(':', $line, 2);
			 $metaData[$dataName] = trim($dataValue);
		 }

		 return $metaData;
	 }

}

?>
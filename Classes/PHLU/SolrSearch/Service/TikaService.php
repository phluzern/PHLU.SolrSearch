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
	 * Unicode ranges which should get stripped before sending a document to solr.
	 * This is necessary if a document (PDF, etc.) contains unicode characters which
	 * are valid in the font being used in the document but are not available in the
	 * font being used for displaying results.
	 *
	 * This is often the case if PDFs are being indexed where special fonts are used
	 * for displaying bullets, etc. Usually those bullets reside in one of the unicode
	 * "Private Use Zones" or the "Private Use Area" (plane 15 + 16)
	 *
	 * @see http://en.wikipedia.org/wiki/Unicode_block
	 * @var array
	 */
	protected $stripUnicodeRanges = array(
		array('FFFD',   'FFFD'), // Replacement Character (�) @see http://en.wikipedia.org/wiki/Specials_%28Unicode_block%29
		array('E000',   'F8FF'), // Private Use Area (part of Plane 0)
		array('F0000',  'FFFFF'), // Supplementary Private Use Area (Plane 15)
		array('100000', '10FFFF'), // Supplementary Private Use Area (Plane 16)
	);
	
	/**
	 * Strips control characters that cause Jetty/Solr to fail.
	 *
	 * @param	string	the content to sanitize
	 * @return	string	the sanitized content
	 * @see	http://w3.org/International/questions/qa-forms-utf-8.html
	 */
	public function stripControlCharacters($content) {
			// Printable utf-8 does not include any of these chars below x7F
		return preg_replace('@[\x00-\x08\x0B\x0C\x0E-\x1F]@', ' ', $content);
	}

	/**
	 * Strips a UTF-8 character range
	 *
	 * @param string $content Content to sanitize
	 * @param string $start Unicode range start character as uppercase hexadecimal string
	 * @param string $end Unicode range end character as uppercase hexadecimal string
	 * @return string Sanitized content
	 */
	public function stripUnicodeRange($content, $start, $end) {
		return preg_replace('/[\x{' . $start . '}-\x{' . $end . '}]/u', '', $content);
	}

	/**
	 * Strips unusable unicode ranges
	 *
	 * @param string $content Content to sanitize
	 * @return string Sanitized content
	 */
	public function stripUnicodeRanges($content) {
		foreach ($this->stripUnicodeRanges as $range) {
			$content = $this->stripUnicodeRange($content, $range[0], $range[1]);
		}

		return $content;
	}

	/**
	 * Strips html tags, and tab, new-line, carriage-return, &nbsp; whitespace
	 * characters.
	 *
	 * @param	string	String to clean
	 * @return	string	String cleaned from tags and special whitespace characters
	 */
	public function cleanContent($content) {
		$content = $this->stripControlCharacters($content);

			// remove Javascript
		$content = preg_replace('@<script[^>]*>.*?<\/script>@msi', '', $content);

			// remove internal CSS styles
		$content = preg_replace('@<style[^>]*>.*?<\/style>@msi', '', $content);

			// prevents concatenated words when stripping tags afterwards
		$content = str_replace(array('<', '>'), array(' <', '> '), $content);
		$content = strip_tags($content);
		$content = str_replace(array("\t", "\n", "\r", '&nbsp;'), ' ', $content);
		$content = $this->stripUnicodeRanges($content);
		$content = trim($content);

		return $content;
	}

	/**
	 * Extract text from a file using Apache Tika
	 *
	 * @param $inputFile string
	 * @param $tikaSettings array
	 * @return mixed
	 */
	public function extractText($inputFile, $tikaSettings) {

		$tikaCommand = 'LC_ALL=de_CH.UTF-8 java -Dfile.encoding=UTF8 -jar \'' . $tikaSettings['path'] . '\' -t ' . escapeshellarg($inputFile);
		$shellOutput = shell_exec($tikaCommand);

		// TODO Logging actions

		if (empty($shellOutput)) {
			return FALSE;
		} else {
			return $this->cleanContent($shellOutput);
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

		$tikaCommand = 'LC_ALL=de_CH.UTF-8 java -Dfile.encoding=UTF8 -jar \'' . $tikaSettings['path'] . '\' -m ' . escapeshellarg($inputFile);

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
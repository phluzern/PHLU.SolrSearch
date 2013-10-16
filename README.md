PHLU.SolrSearch
===============

This package is work-in-progress.

This TYPO3 Flow 2.0 package is a Solr-based search engine for file resource associated with records of type PHLU_Portal_Domain_Model_File (in package PHLU.Portal).

Installation procedure
----------------------

Install package/add through composer.

This package needs the the PHP Solr PECL extension and a Solr 3.x server.
There is a fork of this extension that is supposedly compatible with Solr 4.x, but we did not test it yet: https://github.com/ecaron/php-pecl-solr

Add Configuration/Settings.yaml based on Configuration/Settings.yaml.example and adjust configuration.

The Solr configuration including a Schema can be found in Resources/Solr. The whole directory Resources/Solr/flow can be copied to /usr/share/flow.

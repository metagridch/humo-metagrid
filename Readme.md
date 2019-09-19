# Humo-metagrid
This small project adds a simple json-endpoint to the [humo-gen CMS](https://humogen.com/). It allows the metagrid-cralwer to simply pull the data from a humo-gen site and to integrate it into metagrid.

## Requirements
* You need at least php 7.1 to run the metagrid-router. 
* You need to activate mod_rewrite for apache or similar capacity on your webserver. 
* You need to activate the url_rewrite switch in the humo-gen CMS. You can find the configuration in your admin panel under "control" -> "settings"
## Installation
To install the metagrid-router just drop the `metagrid-router.php` into the cms root of humo-gen. Then you can access the api directly over http://domain.com/CMS-ROOT/metagrid-router.php
## Usage
You can send get requests to the metagrid-router.php to get a json-collection of a slice of persons in the project database

__Query strings__
* (int) start the offset to fetch data 
* (int) limit The limit of data to fetch (max 5000)
* (string) tree The family tree to fetch 
* (string) api-key The api key

metagrid-router.php?start=0&limit=100&tree=humo_&api-key=your-key
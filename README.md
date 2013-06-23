# cig2flickr

cig2flickr is a PHP script to automatically transfer pictures from Canon Image Gateway webservice to Flickr.

Different Canon cameras (like Canon 6D DSLR) allow pictures upload on Canon Image Gateway service. Unfortunately,
a lot of photographers prefer Flickr service. This script moves (uploads to flickr and **removes** from CIG) pictures from Canon Image Gateway to Flickr.


## Requirements
* PHP version >= 5.3
* cURL PHP extension installed

## Installation
Clone cig2flickr repository and change folder owner

	# git clone --recursive https://github.com/gpailler/cig2flickr.git cig2flickr
	# chown www-data: cig2flickr

## Configuration
Rename default configuration file

	cp cig2flickr.config.sample.php cig2flickr.config.php
	
Edit `cig2flickr.config.php`

* Fill your *Canon Image Gateway* credentials.
* Request a non commercial key on [Flickr App Garden](http://www.flickr.com/services/apps/create/apply/) and add your API key and API secret.
* Load `cig2flickr.php` script from your browser, validate Flickr authorization and copy displayed tokens.

Finally, you can add a CRON task to automate pictures transfer.

## Known limitations

* If you have a lot of pictures to transfer and/or your network bandwidth is low, you should increase PHP max execution time.
* This script is tested with **Canon Image Gateway Europe** service only ([http://www.cig.canon-europe.com/](http://www.cig.canon-europe.com/)).

<?php

define('BASE_DIRECTORY', dirname(__FILE__) . '/');
define('TMP_DIRECTORY', BASE_DIRECTORY . 'tmp/');
define('CONFIG_FILE', BASE_DIRECTORY . 'cig2flickr.config.php');
define('HTTP_TIMEOUT', 300);

require_once(BASE_DIRECTORY . 'lib/curl/curl.php');
require_once(BASE_DIRECTORY . 'lib/DPZFlickr/src/DPZ/Flickr.php');

set_time_limit(0);

use \DPZ\Flickr;


// ====================================================================
// Check prerequisites
// ====================================================================
if (!extension_loaded('curl'))
{
	die('cig2flickr requires CURL module.');
}

if (!file_exists(CONFIG_FILE))
{
	die(sprintf('Unable to find configuration file %s.', CONFIG_FILE));
}

if (!is_dir(TMP_DIRECTORY))
{
	if (!mkdir(TMP_DIRECTORY))
	{
		die(sprintf('Unable to create %s. Create this directory manually.', TMP_DIRECTORY));
	}
}

if (!is_writable(TMP_DIRECTORY))
{
	$perms = 0750;
	$oldumask = umask(0); 
	chmod(TMP_DIRECTORY, $perms);
	umask($oldumask);
	
	if (!is_writable(TMP_DIRECTORY))
	{
		die(sprintf('Unable to change permissions of %s. Change permissions to %o.', TMP_DIRECTORY, $perms));
	}
}


// ====================================================================
// Custom configuration
// ====================================================================
include(CONFIG_FILE);


// ====================================================================
// Validate Flickr authentication
// ====================================================================

$callback = sprintf('%s://%s:%d%s',
    (@$_SERVER['HTTPS'] == "on") ? 'https' : 'http',
    defined('HOSTNAME') ? HOSTNAME : $_SERVER['SERVER_NAME'],
    $_SERVER['SERVER_PORT'],
    $_SERVER['SCRIPT_NAME']
);

$flickr = new Flickr(FLICKR_API_KEY, FLICKR_API_SECRET, $callback);
$flickr->setHttpTimeout(HTTP_TIMEOUT);

if (strlen(FLICKR_ACCESS_TOKEN) == 0 || strlen(FLICKR_ACCESS_TOKEN_SECRET) == 0)
{
	if (!$flickr->authenticate('write'))
	{
		die("Unable to authenticate to Flickr.");
	}
	
	echo <<<EOT
		Please update your configuration file with following values<br />
		define('FLICKR_ACCESS_TOKEN', '{$flickr->getOauthData(Flickr::OAUTH_ACCESS_TOKEN)}');<br />
		define('FLICKR_ACCESS_TOKEN_SECRET', '{$flickr->getOauthData(Flickr::OAUTH_ACCESS_TOKEN_SECRET)}');<br/><br />
		And reload the script.
EOT;
	exit();
}
else
{
	// Push saved token in current session
	$data = $_SESSION[Flickr::SESSION_OAUTH_DATA];
	$data[Flickr::PERMISSIONS] = 'write';
	$data[Flickr::OAUTH_ACCESS_TOKEN] = FLICKR_ACCESS_TOKEN;
	$data[Flickr::OAUTH_ACCESS_TOKEN_SECRET] = FLICKR_ACCESS_TOKEN_SECRET;
	$data[Flickr::USER_NSID] = uniqid();
	
	$_SESSION[Flickr::SESSION_OAUTH_DATA] = $data;
	
	$flickr->authenticate('write');
}


// ====================================================================
// Retrieve images list on CIG
// ====================================================================

define('CIG_AUTH_URL', 'https://www.cig.canon-europe.com/pe/f/authenticate.do?UseNSession=No');
define('CIG_HOME_URL', 'http://www.cig.canon-europe.com/photoAlbum');
define('CIG_ITEMS_URL', 'http://opa.cig2.canon-europe.com/internal/timeline/grid');
define('CIG_ITEM_URL', 'http://opa.cig2.canon-europe.com/item/');
define('CIG_DELETE_URL', 'http://opa.cig2.canon-europe.com/item/delete');


// Authenticate to Canon Image Gateway
$curl = new Curl;
$curl->cookie_file = TMP_DIRECTORY . 'cig2flickr.cookies';

$response = $curl->post(CIG_AUTH_URL, array('username' => CIG_USERNAME, 'password' => CIG_PASSWORD));
if ($response->headers['Status-Code'] != 200 || stripos($response->body, 'error'))
{
	die('Unable to authenticate to CIG.');
}


// Retrieve session variable stored on homepage
$response = $curl->get(CIG_HOME_URL);
if ($response->headers['Status-Code'] != 200)
{
	die('Unable to access CIG homepage.');
}

if (preg_match('/name=\"ct__\" value=\"([\d\w]+?)\"/', $response->body, $matches))
{
	$ct = $matches[1];
}
else
{
	die('Unable to retrieve session variable ct__.');
}


// Retrieve items list
$response = $curl->post(CIG_ITEMS_URL, array('ct__' => $ct));
if ($response->headers['Status-Code'] != 200)
{
	die('Unable to retrieve items list.');
}

$data = json_decode($response->body);
if (count($data->itemList) == 0)
{
	die('No items found.');
}

// Sort items by date
usort($data->itemList, function($a, $b)
{
	return strcmp($a->shootingDate, $b->shootingDate);
});


// Transfer item from CIG to Flickr
foreach ($data->itemList as $item)
{
	$id = $item->it__;
	$title = $item->title;

	// Search full resolution image link
	$response = $curl->get(CIG_ITEM_URL . $id);
	if (preg_match('/id=\"itemDownloadStandByUrl00\" type=\"hidden\" value=\"(.*?)\"/', $response->body, $matches))
	{
		$downloadurl = $matches[1];
	}
	else
	{
		die('Unable to retrieve download link.');
	}
	
	// Download image
	$data = file_get_contents($downloadurl);
	$tmpfile = TMP_DIRECTORY . uniqid();
	$fp = fopen($tmpfile, 'w');
	fwrite($fp, $data);
	fclose($fp);


	// Upload on Flickr
	$parameters = array(
		'title' => $title,
		'tags' => 'cig2flickr',
		'photo' => '@' . $tmpfile
	);

	$response = $flickr->upload($parameters);
	unlink($tmpfile);
	$ok = $response['stat'];

	if ($ok == 'ok')
	{
		echo "Image $title uploaded on Flickr.<br />";
		
		// Remove photo on CIG
		$response = $curl->post(CIG_DELETE_URL, array('it__' => $id, 'ct__' => $ct));
		if ($response->headers['Status-Code'] != 200)
		{
			die('Unable to delete image on CIG.');
		}
	}
	else
	{
		die("Error: " . print_r($response));
	}
}

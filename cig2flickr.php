<?php

define('BASE_DIRECTORY', dirname(__FILE__) . '/');
define('TMP_DIRECTORY', BASE_DIRECTORY . 'tmp/');
define('CONFIG_FILE', BASE_DIRECTORY . 'cig2flickr.config.php');


require_once(BASE_DIRECTORY . 'lib/curl/curl.php');
require_once(BASE_DIRECTORY . 'lib/DPZFlickr/src/DPZ/Flickr.php');

use \DPZ\Flickr;

// ====================================================================
// Check prerequisites
// ====================================================================
if (!extension_loaded('curl'))
{
	die('cig2flickr requires CURL module');
}

if (!file_exists(CONFIG_FILE))
{
	die('Unable to find configuration file ' . CONFIG_FILE);
}

if (!is_dir(TMP_DIRECTORY))
{
	if (!mkdir(TMP_DIRECTORY))
	{
		die('Unable to create ' . TMP_DIRECTORY . '. Create this directory manually');
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
		die('Unable to change permissions of ' . TMP_DIRECTORY . '. Change permissions to ' . $perms);
	}
}



// ====================================================================
// Main
// ====================================================================
include(CONFIG_FILE);


// Validate Flickr Auth
$callback = sprintf('%s://%s:%d%s',
    (@$_SERVER['HTTPS'] == "on") ? 'https' : 'http',
    $_SERVER['SERVER_NAME'],
    $_SERVER['SERVER_PORT'],
    $_SERVER['SCRIPT_NAME']
);

$flickr = new Flickr(FLICKR_API_KEY, FLICKR_API_SECRET, $callback);
if (!$flickr->authenticate('write'))
{
	die("Unable to obtain flickr auth");
}



// CIG urls
DEFINE('CIG_AUTH_URL', 'https://www.cig.canon-europe.com/pe/f/authenticate.do?UseNSession=No');
DEFINE('CIG_HOME_URL', 'http://www.cig.canon-europe.com/photoAlbum');
DEFINE('CIG_ITEMS_URL', 'http://opa.cig2.canon-europe.com/internal/timeline/grid');
DEFINE('CIG_ITEM_URL', 'http://opa.cig2.canon-europe.com/item/');
DEFINE('CIG_DELETE_URL', 'http://opa.cig2.canon-europe.com/item/delete');

// Connect to Canon Image Gateway
$curl = new Curl;
$curl->cookie_file = TMP_DIRECTORY . 'cig2flickr.cookies';

$response = $curl->post(CIG_AUTH_URL, array('username' => CIG_USERNAME, 'password' => CIG_PASSWORD));
if ($response->headers['Status-Code'] != 200 || stripos($response->body, 'error'))
{
	die('Unable to properly authenticate to CIG');
}

$response = $curl->get(CIG_HOME_URL);
if ($response->headers['Status-Code'] != 200)
{
	die('Unable to access CIG homepage');
}

// Retrieve token
if (preg_match('/name=\"ct__\" value=\"([\d\w]+?)\"/', $response->body, $matches))
{
	$ct = $matches[1];
}
else
{
	die('Unable to retrieve session variable ct__');
}

$response = $curl->post(CIG_ITEMS_URL, array('ct__' => $ct));
if ($response->headers['Status-Code'] != 200)
{
	die('Unable to retrieve items list');
}




$data = json_decode($response->body);
if (count($data->itemList) == 0)
{
	die('No image to transfer');
}

foreach ($data->itemList as $item)
{
	$id = $item->it__;
	$title = $item->title;
	
	$response = $curl->get(CIG_ITEM_URL . $id);
	
	if (preg_match('/id=\"itemDownloadStandByUrl00\" type=\"hidden\" value=\"(.*?)\"/', $response->body, $matches))
	{
		$downloadurl = $matches[1];
	}
	else
	{
		die('Unable to retrieve download link');
	}
	
	$data = file_get_contents($downloadurl);
	
	$tmpfile = TMP_DIRECTORY . uniqid();
	$fp = fopen($tmpfile, 'w');
	fwrite($fp, $data);
	fclose($fp);


	$parameters = array(
		'title' => $title,
		'tags' => 'cig2flickr'
	);

	$parameters['photo'] = '@' . $tmpfile;
	
	$response = $flickr->upload($parameters);
	unlink($tmpfile);
	$ok = @$response['stat'];

	if ($ok == 'ok')
	{
		echo "Photo $title uploaded\n";
		
		// Remove photo on CIG
		$response = $curl->post(CIG_DELETE_URL, array('it__' => $id, 'ct__' => $ct));
		if ($response->headers['Status-Code'] != 200)
		{
			die('Unable to delete image');
		}
	}
	else
	{
		$err = @$response['err'];
		die("Error: " . @$err['msg']);
	}
	
	flush(); 
	ob_flush(); 
}

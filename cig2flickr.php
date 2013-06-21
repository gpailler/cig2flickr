﻿<?php

define('BASE_DIRECTORY', dirname(__FILE__) . '/');
define('TMP_DIRECTORY', BASE_DIRECTORY . 'tmp/');
define('CONFIG_FILE', BASE_DIRECTORY . 'cig2flickr.config.php');


require_once(BASE_DIRECTORY . 'lib/curl/curl.php');
require_once(BASE_DIRECTORY . 'lib/simple_html_dom/simple_html_dom.php');


// Check prerequisites
if (!extension_loaded('curl'))
{
	die('cig2flickr requires CURL module');
}

if (file_exists(CONFIG_FILE))
{
	include(CONFIG_FILE);
}
else
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

exit;

// Variables
const cig_auth_url = 'https://www.cig.canon-europe.com/pe/f/authenticate.do?UseNSession=No';
const cig_home_url = 'http://www.cig.canon-europe.com/photoAlbum';
const cig_items_url = 'http://opa.cig2.canon-europe.com/internal/timeline/grid';
const cig_item_url = 'http://opa.cig2.canon-europe.com/item/';

// Connect to Canon Image Gateway
$curl = new Curl;
$curl->cookie_file = TMP_DIRECTORY . 'cig2flickr.cookies';

$response = $curl->post(cig_auth_url, array('username' => CIG_USERNAME, 'password' => CIG_PASSWORD));
if ($response->headers['Status-Code'] != 200 || stripos($response->body, 'error'))
{
	die('Unable to properly authenticate to CIG');
}

$response = $curl->get(cig_home_url);
if ($response->headers['Status-Code'] != 200)
{
	die('Unable to access CIG homepage');
}

// Retrieve token
$html = str_get_html($response->body);
$ret = $html->find('input[name=ct__]', 0); 
$ct = $ret->value;

$response = $curl->post(cig_items_url, array('ct__' => $ct));
if ($response->headers['Status-Code'] != 200)
{
	die('Unable to retrieve items list');
}

$data = json_decode($response->body);
foreach ($data->itemList as $item)
{
	$id = $item->it__;
	$title = $item->title;
	
	$response = $curl->get(cig_item_url . $id);
	
	$html = str_get_html($response->body);
	$ret = $html->find('input[id=itemDownloadStandByUrl00]', 0); 
	$downloadurl = $ret->value;

	header('Content-Type: image/jpg');
	echo file_get_contents($downloadurl);
	exit;
}
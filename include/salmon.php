<?php

use Friendica\Network\Probe;

require_once 'include/crypto.php';

function get_salmon_key($uri, $keyhash) {
	$ret = array();

	logger('Fetching salmon key for '.$uri);

	$arr = Probe::lrdd($uri);

	if (is_array($arr)) {
		foreach ($arr as $a) {
			if ($a['@attributes']['rel'] === 'magic-public-key') {
				$ret[] = $a['@attributes']['href'];
			}
		}
	} else {
		return '';
	}

	// We have found at least one key URL
	// If it's inline, parse it - otherwise get the key

	if (count($ret) > 0) {
		for ($x = 0; $x < count($ret); $x ++) {
			if (substr($ret[$x], 0, 5) === 'data:') {
				if (strstr($ret[$x], ',')) {
					$ret[$x] = substr($ret[$x], strpos($ret[$x], ',') + 1);
				} else {
					$ret[$x] = substr($ret[$x], 5);
				}
			} elseif (normalise_link($ret[$x]) == 'http://') {
				$ret[$x] = fetch_url($ret[$x]);
			}
		}
	}


	logger('Key located: ' . print_r($ret, true));

	if (count($ret) == 1) {

		// We only found one one key so we don't care if the hash matches.
		// If it's the wrong key we'll find out soon enough because
		// message verification will fail. This also covers some older
		// software which don't supply a keyhash. As long as they only
		// have one key we'll be right.

		return $ret[0];
	} else {
		foreach ($ret as $a) {
			$hash = base64url_encode(hash('sha256', $a));
			if ($hash == $keyhash) {
				return $a;
			}
		}
	}

	return '';
}



function slapper($owner, $url, $slap) {

	// does contact have a salmon endpoint?

	if (! strlen($url)) {
		return;
	}

	if (! $owner['sprvkey']) {
		logger(sprintf("user '%s' (%d) does not have a salmon private key. Send failed.",
		$owner['username'], $owner['uid']));
		return;
	}

	logger('slapper called for '.$url.'. Data: ' . $slap);

	// create a magic envelope

	$data      = base64url_encode($slap);
	$data_type = 'application/atom+xml';
	$encoding  = 'base64url';
	$algorithm = 'RSA-SHA256';
	$keyhash   = base64url_encode(hash('sha256', salmon_key($owner['spubkey'])), true);

	$precomputed = '.' . base64url_encode($data_type) . '.' . base64url_encode($encoding) . '.' . base64url_encode($algorithm);

	// GNU Social format
	$signature   = base64url_encode(rsa_sign($data . $precomputed, $owner['sprvkey']));

	// Compliant format
	$signature2  = base64url_encode(rsa_sign(str_replace('=', '', $data . $precomputed), $owner['sprvkey']));

	// Old Status.net format
	$signature3  = base64url_encode(rsa_sign($data, $owner['sprvkey']));

	// At first try the non compliant method that works for GNU Social
	$xmldata = array("me:env" => array("me:data" => $data,
			"@attributes" => array("type" => $data_type),
			"me:encoding" => $encoding,
			"me:alg" => $algorithm,
			"me:sig" => $signature,
			"@attributes2" => array("key_id" => $keyhash)));

	$namespaces = array("me" => "http://salmon-protocol.org/ns/magic-env");

	$salmon = xml::from_array($xmldata, $xml, false, $namespaces);

	// slap them
	post_url($url, $salmon, array(
		'Content-type: application/magic-envelope+xml',
		'Content-length: ' . strlen($salmon)
	));

	$a = get_app();
	$return_code = $a->get_curl_code();

	// check for success, e.g. 2xx

	if ($return_code > 299) {

		logger('GNU Social salmon failed. Falling back to compliant mode');

		// Now try the compliant mode that normally isn't used for GNU Social
		$xmldata = array("me:env" => array("me:data" => $data,
				"@attributes" => array("type" => $data_type),
				"me:encoding" => $encoding,
				"me:alg" => $algorithm,
				"me:sig" => $signature2,
				"@attributes2" => array("key_id" => $keyhash)));

		$namespaces = array("me" => "http://salmon-protocol.org/ns/magic-env");

		$salmon = xml::from_array($xmldata, $xml, false, $namespaces);

		// slap them
		post_url($url, $salmon, array(
			'Content-type: application/magic-envelope+xml',
			'Content-length: ' . strlen($salmon)
		));
		$return_code = $a->get_curl_code();
	}

	if ($return_code > 299) {
		logger('compliant salmon failed. Falling back to old status.net');

		// Last try. This will most likely fail as well.
		$xmldata = array("me:env" => array("me:data" => $data,
				"@attributes" => array("type" => $data_type),
				"me:encoding" => $encoding,
				"me:alg" => $algorithm,
				"me:sig" => $signature3,
				"@attributes2" => array("key_id" => $keyhash)));

		$namespaces = array("me" => "http://salmon-protocol.org/ns/magic-env");

		$salmon = xml::from_array($xmldata, $xml, false, $namespaces);

		// slap them
		post_url($url, $salmon, array(
			'Content-type: application/magic-envelope+xml',
			'Content-length: ' . strlen($salmon)
		));
		$return_code = $a->get_curl_code();
	}

	logger('slapper for '.$url.' returned ' . $return_code);

	if (! $return_code) {
		return -1;
	}

	if (($return_code == 503) && (stristr($a->get_curl_headers(), 'retry-after'))) {
		return -1;
	}

	return ((($return_code >= 200) && ($return_code < 300)) ? 0 : 1);
}

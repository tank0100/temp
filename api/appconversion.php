<?php

require_once(dirname(dirname(__FILE__)).'/api/convertlib.php');
require_once(dirname(dirname(__FILE__)).'/api/apilib.php');
require_once(dirname(dirname(__FILE__)).'/classes/bc.php');

define('CONVERSION_STATUS_OK', 'ok');
define('CONVERSION_STATUS_DUPLICATE', 'duplicate');
define('CONVERSION_STATUS_TEST', 'test');
define('CONVERSION_STATUS_NO_CLICK', 'no click');
define('CONVERSION_STATUS_INVALID_UDID', 'invalid udid');
define('CONVERSION_STATUS_VALID_CLICKCODE', 'valid clickcode');
define('CONVERSION_STATUS_TEST_CLICKCODE', 'test clickcode');
define('CONVERSION_STATUS_ERROR_CLICKCODE', 'error clickcode');

define('CONVERSION_RESPONSE_OK', 'OK');
define('CONVERSION_RESPONSE_DUPLICATE', 'OK');
define('CONVERSION_RESPONSE_TEST', 'OK');
define('CONVERSION_RESPONSE_NO_CLICK', 'no click');
define('CONVERSION_RESPONSE_INVALID_UDID', 'invalid udid');

define('APP_CONVERSION_SOURCE', 'appconversion');
define('CPD_CONVERSION_SOURCE', 'costperdownload');

$testconvcid = isset($_GET['testconvcid']) && $_GET['testconvcid'] > 0 ? $_GET['testconvcid'] : 0;
if ($testconvcid) {
    define ('TEST_MODE', 1);
}
else {
    define ('TEST_MODE', 0);
}

$source = APP_CONVERSION_SOURCE;

$appId      = isset($_GET['appid'])     ? trim($_GET['appid']) : "";
$ip         = isset($_GET['ip'])        ? trim($_GET['ip']) : "" ;
$ua         = isset($_GET['ua'])        ? trim($_GET['ua']) : "";
$udid       = isset($_GET['udid'])      ? trim($_GET['udid']) : 
             (isset($_GET['deviceid'])  ? trim($_GET['deviceid']) : 0) ;
$platform   = isset($_GET['platform'])  ? trim($_GET['platform']) : "";
$format     = isset($_GET['fmt'])       ? trim($_GET['fmt']) : 'json';
$mode       = isset($_GET['mode'])      ? trim($_GET['mode']) : "";
$clickCode  = getClickCode();

// If clickcode supplied, check clickcode, else skip check.
$isValidClickCode = ($clickCode!=NULL) ? $bc->ads->isValidClickCode($clickCode) : TRUE;

if (FALSE == $isValidClickCode) {
    output(null, $format, array(
        'errcode' => '132',
        'errmsg'  => 'Invalid clickcode supplied.'
    ));
    exit;
}

apiSetContentType($format);

if (empty($appId)) {
    output(null, $format, array(
        'errcode' => '101',
        'errmsg'  => 'Application ID parameter required.'
    ));
    exit;
}

$bc = new BC;

if (ctype_digit($appId)) {  //Old conversion tracking - appId is advertiser partner ID
    $partnerId = $appId;
    $appId = '';
    $isNewConversionTracking = FALSE;
}
else {                      //New conversion tracking - appId is a hash string
    if ('djuzz_' == substr($appId, 0, 6) || $bc->security->isValidHash($appId)) {
        $partnerId = 0;
        $clickCode = getAppClickCode($appId);
        $isNewConversionTracking = TRUE;
    }
    else {
        output(null, $format, array(
            'errcode' => '101',
            'errmsg'  => 'Invalid Application ID parameter.'
        ));
        exit;
    }
}

if (!$isNewConversionTracking) {
    if (empty($ip)) {
        output(null, $format, array(
            'errcode' => '101',
            'errmsg'  => 'IP parameter required.'
        ));
        exit;
    }

    if (empty($ua)) {
        output(null, $format, array(
            'errcode' => '101',
            'errmsg'  => 'Agent parameter required.'
        ));
        exit;
    }
}

if (!empty($udid) && !$bc->generalValidation->isValidUdid($udid)) {
    $udid = 0;
}

$noIpUa = false;
if (empty($ip) || empty($ua)) {
    $noIpUa = true;
}

$userInfo    = getUserInfo($ua, $ip);

$conversionInfo = array(
    'partnerId'  => $partnerId,
    'appId'      => $appId,
    'ip'         => $userInfo['ip'],
    'cntr'       => $userInfo['cntr'],
    'longagent'  => $userInfo['longagent'],
    'shortagent' => $userInfo['shortagent'],
    'mode'       => $mode,
    'platform'   => $platform,
    'udid'       => $udid,
    'clickCode'  => $clickCode,
    'cid'        => $testconvcid,
    'no-ip-ua'   => $noIpUa,
);

if (TEST_MODE) {
    $resultData = $bc->ads->conversions->reportTestAppConversionForAppId($appId, $conversionInfo);
    if ($resultData['isFound']) {
        $source  = ('cpd' == $resultData['cptype']) ? CPD_CONVERSION_SOURCE : APP_CONVERSION_SOURCE;
        $comment = CONVERSION_STATUS_TEST;
    }
    else {
        $source  = APP_CONVERSION_SOURCE;
        $comment = CONVERSION_STATUS_NO_CLICK;
    }

    $resultData['source']     = $source;
    $resultData['comment']    = $comment;
    $bc->ads->conversions->recordConversion($resultData);
    
    $data['test'] = 1;
    $data['conversion'] = CONVERSION_RESPONSE_TEST;
    output($data, $format);
}
else {
    if ($isNewConversionTracking) {
        $resultData = $bc->ads->conversions->reportAppConversionForAppId($appId, $conversionInfo);
        if ($resultData['isFound']) {
            $source = ('cpd' == $resultData['cptype']) ? CPD_CONVERSION_SOURCE : APP_CONVERSION_SOURCE;

            if ('clickcode' == $resultData['matchBy'] && ($resultData['isTestClickCode'] || $resultData['isErrorClickCode'])) {
                if ($resultData['isTestClickCode']) {
                    $comment = CONVERSION_STATUS_TEST_CLICKCODE;
                }
                else if ($resultData['isErrorClickCode']) {
                    $comment = CONVERSION_STATUS_ERROR_CLICKCODE;
                }
            }
            else {
                if ($resultData['isClickIdRegistered']) {
                    $comment = CONVERSION_STATUS_DUPLICATE;
                    $statusResponse = CONVERSION_RESPONSE_DUPLICATE;
                }
                else {
                    $comment = CONVERSION_STATUS_OK;
                    $statusResponse = CONVERSION_RESPONSE_OK;

                    if ('cpd' == $resultData['cptype']) {
                        $bc->ads->conversions->chargeCPD($resultData['pubId'], $resultData['cid'], $resultData['cntr']);
                    }
                }
            }
        }
        else {
            $source = APP_CONVERSION_SOURCE;
            if ($resultData['isInvalidUdid']) {                
                $comment = CONVERSION_STATUS_INVALID_UDID;
                $statusResponse = CONVERSION_RESPONSE_INVALID_UDID;
            }
            else {
                if ($resultData['isRetrievedFromClickCode']) {
                    if ($resultData['isTestClickCode']) {
                        $comment = CONVERSION_STATUS_TEST_CLICKCODE;
                    }
                    else if ($resultData['isErrorClickCode']) {
                        $comment = CONVERSION_STATUS_ERROR_CLICKCODE;
                    }
                    else {
                        $comment = CONVERSION_STATUS_VALID_CLICKCODE;

                        if ('cpd' == $resultData['cptype']) {
                            $bc->ads->conversions->chargeCPD($resultData['pubId'], $resultData['cid'], $resultData['cntr']);
                        }
                    }
                }
                else {
                    $comment = CONVERSION_STATUS_NO_CLICK;
                }

                $statusResponse = CONVERSION_RESPONSE_NO_CLICK;
            }
        }
    }
    else {
        $resultData = $bc->ads->conversions->reportAppConversionForPartnerId($partnerId, $conversionInfo);
        if ($resultData['isFound']) {
            $source = ('cpd' == $resultData['cptype']) ? CPD_CONVERSION_SOURCE : APP_CONVERSION_SOURCE;

            if ('clickcode' == $resultData['matchBy'] && ($resultData['isTestClickCode'] || $resultData['isErrorClickCode'])) {
                if ($resultData['isTestClickCode']) {
                    $comment = CONVERSION_STATUS_TEST_CLICKCODE;
                }
                else if ($resultData['isErrorClickCode']) {
                    $comment = CONVERSION_STATUS_ERROR_CLICKCODE;
                }
            }
            else {
                if ($resultData['isClickIdRegistered']) {
                    $comment = CONVERSION_STATUS_DUPLICATE;
                    $statusResponse = CONVERSION_RESPONSE_DUPLICATE;
                }
                else {
                    $comment = CONVERSION_STATUS_OK;
                    $statusResponse = CONVERSION_RESPONSE_OK;

                    if ('cpd' == $resultData['cptype']) {
                        $bc->ads->conversions->chargeCPD($resultData['pubId'], $resultData['cid'], $resultData['cntr']);
                    }
                }
            }
        }
        else {
            $source = APP_CONVERSION_SOURCE;
            if ($resultData['isInvalidUdid']) {
                $comment = CONVERSION_STATUS_INVALID_UDID;
                $statusResponse = CONVERSION_RESPONSE_INVALID_UDID;
            }
            else {
                if ($resultData['isRetrievedFromClickCode']) {
                    if ($resultData['isTestClickCode']) {
                        $comment = CONVERSION_STATUS_TEST_CLICKCODE;
                    }
                    else if ($resultData['isErrorClickCode']) {
                        $comment = CONVERSION_STATUS_ERROR_CLICKCODE;
                    }
                    else {
                        $comment = CONVERSION_STATUS_VALID_CLICKCODE;

                        if ('cpd' == $resultData['cptype']) {
                            $bc->ads->conversions->chargeCPD($resultData['pubId'], $resultData['cid'], $resultData['cntr']);
                        }
                    }
                }
                else {
                    $comment = CONVERSION_STATUS_NO_CLICK;
                }
                
                $statusResponse = CONVERSION_RESPONSE_NO_CLICK;
            }
        }

        if ($mode == "test") {
            $data['test'] = true;
        }
    }

    $resultData['source']     = $source;
    $resultData['comment']    = $comment;
    $bc->ads->conversions->recordConversion($resultData);

    if (CONVERSION_RESPONSE_NO_CLICK == $statusResponse) {
        output(null, $format, array(
            'errcode' => '205',
            'errmsg'  => 'No related clicks can be found in our database.'
        ));
    }
    else if (CONVERSION_RESPONSE_INVALID_UDID == $statusResponse) {
        output(null, $format, array(
            'errmsg' => 'udid parameter is not available for the specified device.'
        ));
    }
    else {
        $data['conversion'] = $statusResponse;
        output($data, $format);
    }
}

function output ($data, $format, $error=null) {
    if ($format == 'json') {
        apiOutputJson($data, $error);
    }
}

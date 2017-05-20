<?
ob_start();
/*
 *  epay.php - Electronic Payment API
 *  v1.8.2009052502
 *
 *  Change History
 *  ~~~~~~~~~~~~~~
 *  V1.0  CC:  Release version (2006-08-28).
 *  V1.1  CC:  Changed paypal.ok to paypal.notify (2006-09-17).
 *  V1.2  CC:  Added txn.peek method (2006-10-26).
 *  V1.3  ZC:  Added worldp.notify and worldp.ok methods (2006-10-31).
 *  V1.4  ZC:  Added support for WorldPay Select, wpsl (2007-03-27).
 *  V1.5  CC:  Improved array_2addinfo function (2007-03-29).
 *  V1.6  ZC:  Added ppwap.notify and ppwap.ok methods for PayPal Mobile (2007-06-13).
 *  V1.7  ZC:  Return OK with v_txid in txn.check method (2007-06-25).
 *  V1.8  CC:  Added support for action_new retstatus in txn.new method (2009-05-25).
 */
header("X-Powered-By: PHP");
header("Expires: ".gmdate("D, d M Y H:i:s")." GMT");
header("Last-Modified: ".gmdate("D, d M Y H:i:s")." GMT");
header("Cache-Control: no-cache, must-revalidate");
header("Pragma: no-cache");
header('Content-Type: text/plain');

// CONFIGURATION VARIABLES
$cf_debug = FALSE || $debug;

include_once(dirname(dirname(dirname(__FILE__)))."/util/mysqlutil.php");
include_once("miscutil.php");     // required, miscellaneous util
include_once("EpayClient.php");   // Client for Electronic Gamma Payment
$script_name = getScriptname();
$vmode = NULL;    // visit mode
define ('PAYPAL_TXN_CHECK_API', 'https://www.paypal.com/cgi-bin/webscr');
define ('PAYPAL_TXN_CHECK_ID', 'a8C6oVany4GPO3P54baqwgM-PmBuK0nDiH0l6fhx6TQUND3PlIkBe_UnVC0');

// MAIN PROGRAM

$iq_array = array();
if (!empty($GLOBALS['HTTP_RAW_POST_DATA'])) {
   parse_str($GLOBALS['HTTP_RAW_POST_DATA'], $iq_post_array);
   if (is_array($iq_post_array)) $iq_array += $iq_post_array;
}
if (!empty($_SERVER['QUERY_STRING'])) {
   parse_str($_SERVER['QUERY_STRING'], $iq_get_array);
   if (is_array($iq_get_array)) $iq_array += $iq_get_array;
}
if ($cf_debug) {
   $epayc = new EpayClient($script_name);
   $epayc->genlog('epay: '.print_r($iq_array,TRUE));
}

// may need to perform stripslashes on some parms
if (empty($iq_array['type'])) $iq_array['type'] = 'SET';    // default type = SET
$resinfo = processRequest($iq_array);

/*
if ($cf_debug) {
   echo "DEBUG MODE\n<BR>";
   echo "Method: $method\n<BR>";
   echo "Location: ".$surl."\n<BR>";
   echo "To test redirection, visit ...\n<BR>";
   echo "<a href=\"$surl\">Proceed</a>";
}
*/

// END MAIN


/**
 *  Processes Client Request and returns result as an iq-structured
 *  array.
 *
 *  This function accepts $iq as an array, or HTTP_RAW_POST_DATA.
 **/
function processRequest ($iq)
{
   global $cf_debug;
   global $dfmt;

   preprocess($iq);
   if (!isset($iq) || empty($iq)) return;

   // Extract $type, $method and $parmarr
   if (is_array($iq)) {
      $type = strtolower(trim($iq['type']));
      $method = preg_match('%([a-z_][a-z_\d]*(?:[.][a-z_\d]+)*)%i', $iq['method'], $matches) ? strtolower($matches[1]) : '';

      $parmarr = $iq;
   }
   $dfmt = $parmarr['fmt'];

   $resinfo = array();   // prepare for Result

   // Assume TYPE Valid...

   // Clear critical variables
   if (!$cf_debug) $parmarr['mid'] = NULL;

   // Obtain and normalize encapsulated parameters
   if (strlen($parmarr['ia']) > 0) {
      $parmx = obj_gunserialize($parmarr['ia']);

      $parmarr['cntr'] = $parmx['cntr'];
      $parmarr['mid'] = $parmx['mid'];
      if (!empty($parmx['partnerid'])) $parmarr['partnerid'] = $parmx['partnerid'];
      $parmarr['model'] = $parmx['model'];
      $parmarr['vmode'] = $parmx['vmode'];
   }

   switch ($type) {
      case 'set':
         $resinfo = processSET($method, $parmarr);
         break;

      default:
         $resinfo = processGET($method, $parmarr);
   }
   if (!$resinfo['.report']) $resinfo['.report'] = "Invalid Access";

   return $resinfo;
}


/**
 *  Validate essential parameters if an array.
 *
 **/
function preprocess (&$iq)
{
   if (empty($iq) || !is_array($iq)) return;

   // Validate essential parameters
   $iq['cntr'] = preg_match('%([a-z]{2})%i', strtolower($iq['cntr']), $matches) ? $matches[1] : '';  // optional
   $iq['partnerid'] = intval($iq['partnerid']);
   $iq['akey'] = preg_match("%([a-z\d][_a-z\d]{1,19})%i", $iq['akey'], $matches) ? $matches[1] : '';    // optional
   $iq['amtcurrency'] = preg_match("%([a-z]{3})%i", $iq['amtcurrency'], $matches) ? strtoupper($matches[1]) : '';
   $iq['amtvalue'] = floatval($iq['amtvalue']);
   $iq['itemdesc'] = trim($iq['itemdesc']);
   $iq['fro'] = preg_match("%([a-z\d][_a-z\d]{1,19})%i", $iq['fro'], $matches) ? $matches[1] : '';
   $iq['svcid'] = preg_match("%([a-z][a-z_]{2,}[\d]+[a-z_]*)%i", $iq['svcid'], $matches) ? strtoupper($matches[1]) : '';
   $iq['service'] = preg_match("%^([a-z][a-z_]{2,})%i", (!empty($iq['service']) ? $iq['service'] : $iq['svcid']), $matches) ? strtoupper($matches[1]) : '';

   $iq['txnid'] = intval($iq['txnid']);

   return $iq;
}


function processSET($method, $parmarr)
{
   global $cf_debug;
   global $script_name;
   global $dfmt;     // desired output format

   // Initialize Epay Client
   $epayc = new EpayClient($script_name);
   $post_data = !empty($GLOBALS['HTTP_RAW_POST_DATA']) ? ";\n".$GLOBALS['HTTP_RAW_POST_DATA'] : '';
   $epayc->genlog($_SERVER["REQUEST_METHOD"].": ".$_SERVER["REQUEST_URI"].$post_data);

   $retstatus = '';
   switch ($method) {
      case 'svc.set':
         $svcinfo = $epayc->service_get($parmarr);  // obtain svcid
         $svcid = !empty($svcinfo) ? $svcinfo['svcid'] : '';
         if (!empty($svcid)) {
            echo "report=OK\n";     // keyval fmt
            echo "svcid=$svcid\n";
         }
         else
            echo "report=ERR\n";
         break;

      case 'paypal.notify':
      case 'paypal.ok':
         if (NULL == $parmarr['svcid']) {
             $parmarr['service'] = ($parmarr['service'] != NULL) ? $parmarr['service'] : 'ADSPAYP';
             $parmarr['amtvalue'] = ($parmarr['amtvalue'] != NULL) ? $parmarr['amtvalue'] : $parmarr['amt'];
             $parmarr['itemdesc'] = ($parmarr['itemdesc'] != NULL) ? $parmarr['itemdesc'] : round($parmarr['amtvalue']).' Ads Credits';
             $svcinfo = $epayc->service_get($parmarr);  // obtain svcid
             $svcid = !empty($svcinfo) ? $svcinfo['svcid'] : '';
             $parmarr['svcid'] = $svcid;
         }
      case 'ppwap.notify':
      case 'ppwap.ok':

         // re-express PayPal-specific parameters
         $txnid = intval($parmarr['custom']) ? intval($parmarr['custom']) : (intval($parmarr['cm']) ? intval($parmarr['cm']) : $parmarr['txnid']);
         $v_txid = trim($parmarr['txn_id'] ? $parmarr['txn_id'] : $parmarr['tx']);
         if (!empty($v_txid)) {
              if ('paypal.notify' == $method || 'paypal.ok' == $method) {
                  $transactionCheckFields = array(
                      'cmd' => '_notify-synch',
                      'tx' => $v_txid,
                      'at' => PAYPAL_TXN_CHECK_ID
                  );
      
                  $ch = curl_init();
                  curl_setopt($ch, CURLOPT_URL, PAYPAL_TXN_CHECK_API);
                  curl_setopt($ch, CURLOPT_POST, 1);
                  curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($transactionCheckFields));
                  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
                  curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                  curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
                  $transactionCheckResult = curl_exec($ch);
                  curl_close($ch);

                  if (strpos($transactionCheckResult,'SUCCESS') === FALSE)  {
                      $parmarr['payment_status'] = 'FAIL';
                      $parmarr['st'] = 'FAIL';
                  }
              }
          
              $parmarr += array(
                 'v_txid' => $v_txid,
                 'v_status' => ($parmarr['payment_status'] ? $parmarr['payment_status'] : $parmarr['st']),
                 'v_amount' => ($parmarr['mc_gross'] ? $parmarr['mc_gross'] : $parmarr['amt']),
                 'v_currency' => ($parmarr['mc_currency'] ? $parmarr['mc_currency'] : $parmarr['cc']),
                 );
              $parmarr['v_addinfo'] = empty($parmarr['v_addinfo']) ? '' : trim($parmarr['v_addinfo']).'&';
              $parmarr['v_addinfo'] .= array_2addinfo(array('custom','cm','payer_email','payer_id','payment_type','residence_country','mc_fee','pending_reason','reason_code'), $parmarr);
  
              if (in_array(strtoupper($parmarr['v_status']),array('COMPLETED'))) {
                 $epayc->action_start($txnid, $parmarr);
                 $parmarr['status'] = 'start';
                 $epayc->action_run($txnid, $parmarr);
                 // vendorTxn table is only updated by paypal_txncheck.php
              }
              else if (in_array(strtoupper($parmarr['v_status']),array('PENDING','PROCESSSED'))) {
                 // do nothing
              }
              else if (!empty($parmarr['v_status'])) {
                 $epayc->action_abort($txnid, $parmarr, 'fail');
              }
         }

         if (preg_match('%[.]ok$%i', $method)) {
            $txninfo = $epayc->action_read($txnid, $parmarr);
            $retstatus = 'ok';
            $toRedirect = TRUE;
         }
         break;

      case 'worldp.notify':
      case 'worldp.notifyagree':
      case 'worldp.ok':
      case 'wpsl.notify':  // WorldPay Select (Web, WAP)
      case 'wpsl.ok':

         // re-express WorldPay-specific parameters
         $txnid = !empty($parmarr['M_txnid']) ? $parmarr['M_txnid'] : $parmarr['txnid'];
         $v_txid = trim($parmarr['transId']);
         if (!empty($v_txid)) {

            $parmarr += array(
               'v_txid' => $v_txid,
               'v_status' => $parmarr['transStatus'],
               'v_amount' => $parmarr['amount'],
               'v_currency' => $parmarr['currency'],
               );
            $parmarr['v_addinfo'] = empty($parmarr['v_addinfo']) ? '' : trim($parmarr['v_addinfo']).'&';
            $parmarr['v_addinfo'] .= array_2addinfo(array('M_txnid','email','cardType','country','ipAddress'), $parmarr);
            if (!empty($parmarr['wafMerchMessage']))
            	$parmarr['v_addinfo'] .= "&wafMsg=".$parmarr['wafMerchMessage']."&AVS=".$parmarr['AVS'];

            $parmarr['agmtid'] = !empty($parmarr['futurePayId']) ? $parmarr['futurePayId'] : '';   // FuturePay Agreement

            if (in_array(strtoupper($parmarr['v_status']),array('Y'))) {
               $epayc->action_start($txnid, $parmarr);
               $parmarr['status'] = 'start';
               $txninfo = $epayc->action_run($txnid, $parmarr);

               if (!empty($txninfo) && !empty($parmarr['agmtid'])) {
                  $parmarr = $txninfo + $parmarr;
                  $epayc->action_agmtadd($txnid, $parmarr);
               }

               // optionally, updates vendorTxn table
               if (preg_match('%[.]notify$%i', $method)) {
                  $epayc->vendortxn_update($v_txid, array(
                     'txnid' => $txnid,
                     'svcid' => $parmarr['svcid'],
                     'itemcode' => $parmarr['cartId'],
                     'acctid' => $txninfo['acctid'],
                     'amount' => $parmarr['amount'],
                     'currency' => $parmarr['currency'],
                     'status' => $parmarr['transStatus'],
                     'payer' => $parmarr['email'],
                     'payerid' => '',
                     'payername' => $parmarr['name'],
                     'country' => $parmarr['country'],
                     ));
               }
            }
            else if (in_array(strtoupper($parmarr['v_status']),array('P'))) {
               // do nothing
            }
            else if (in_array(strtoupper($parmarr['v_status']),array('C'))) {
               $epayc->action_abort($txnid, $parmarr, 'cancel');
            }
            else if (!empty($parmarr['v_status'])) {
               $epayc->action_abort($txnid, $parmarr, 'fail');
            }
         }

         if (preg_match('%[.]ok$%i', $method)) {
            $txninfo = $epayc->action_read($txnid, $parmarr);
            $retstatus = 'ok';
            $toRedirect = TRUE;
         }
         break;

      case '':
      case 'txn.new':
         $txninfo = $epayc->action_new($parmarr);
         if (!empty($parmarr['vmode'])) $txninfo['vmode'] = $parmarr['vmode'];
         if (!empty($txninfo)) {    // valid transaction
            if (!empty($txninfo['retstatus']) && strcasecmp($txninfo['retstatus'],'ok')!=0) {   // some error condition
               $retstatus = $txninfo['retstatus'];
               $toRedirect = TRUE;
               break;
            }
            $txnsvc_info = $epayc->service_fillinfo($txninfo);
            $surl = $txnsvc_info['svcurl'].$txnsvc_info['svcparms'];
         }
         $text = 'Please wait while we process your order.  This may take awhile...';
         $epayc->closedb();   // release db
         location_redirect($surl, $parmarr['vmode'], $text);    // Redirect to target URL...
         break;

      case 'agmt.run':  // executes a pre-existing Agreement
         $runinfo = proc_agmt_run($epayc, $parmarr);
         if (!empty($runinfo))
            echo ($runinfo['v_status']."\n");
         else
            echo ("N\n");  // Invalid
         break;

      case 'agmt.cancel':
         $runinfo = $epayc->agmt_cancel($parmarr);
         if (!empty($runinfo))
            echo ("Y\n");
         else
            echo ("N\n");  // Invalid
         break;

      case 'txn.ok':
         $txninfo = $epayc->action_read($parmarr['txnid'], $parmarr);
         $retstatus = 'ok';
         $toRedirect = TRUE;
         break;

      case 'txn.err':
         $txnid = $parmarr['txnid'];
         $epayc->action_abort($txnid, $parmarr, 'fail');
         $txninfo = $epayc->action_read($txnid, $parmarr);
         $retstatus = 'err';
         $toRedirect = TRUE;
         break;

      case 'txn.cancel':
         $txnid = $parmarr['txnid'];
         $epayc->action_abort($txnid, $parmarr, 'cancel');
         $txninfo = $epayc->action_read($txnid, $parmarr);
         $retstatus = 'cancel';
         $toRedirect = TRUE;
         break;

      case 'txn.check':
         $txninfo = $epayc->txn_read($parmarr['txnid'], $parmarr, 'w');    // master db
         if (strcasecmp($txninfo['akey'],$parmarr['akey'])==0) {
            if (preg_match('%^(?:run|finalize)%i', $txninfo['status'])) {
               echo "OK\n";   // simple fmt
               echo $txninfo['v_txid']."\n";
            }
            else if (preg_match('%^(?:pending|start)%i', $txninfo['status']))
               echo "Pending\n";
            else
               echo "Invalid\n";
         }
         else
            echo "Invalid\n";
         return;
         break;

      default:    // invalid action
         if (!empty($parmarr['svcid'])) {
            $txninfo = array(
               'svcid' => $parmarr['svcid'],
               'fro' => $script_name,
               );
            $retstatus = 'invalid';
            $toRedirect = TRUE;
         }
   }

   if ($toRedirect) {
      $txninfo['retstatus'] = $retstatus;
      if (!empty($parmarr['vmode'])) $txninfo['vmode'] = $parmarr['vmode'];
      $txnsvc_info = $epayc->service_fillinfo($txninfo);
      $surl = $txnsvc_info['returl'];
      $epayc->closedb();   // release db
      if (!empty($surl)) {
         $epayc->genlog ("goUrl: $surl");
         location_redirect ($surl, $parmarr['vmode'], 'Please wait...');
      }
   }
   exit();
}


function processGET($method, $parmarr)
{
   global $cf_debug;
   global $script_name;
   global $dfmt;     // desired output format

   // Initialize Epay Client
   $epayc = new EpayClient($script_name);
   $post_data = !empty($GLOBALS['HTTP_RAW_POST_DATA']) ? ";\n".$GLOBALS['HTTP_RAW_POST_DATA'] : '';
   $epayc->genlog($_SERVER["REQUEST_METHOD"].": ".$_SERVER["REQUEST_URI"].$post_data);

   $retstatus = '';
   switch ($method) {
      case 'txn.peek':
         $actinfo = $epayc->action_peek($parmarr, TRUE);
         $svcid = !empty($actinfo) ? $actinfo['svcid'] : '';   // required parameter
         if (!empty($svcid)) {
            echo "report=OK\n";     // keyval fmt
            foreach ($actinfo as $key=>$val)
               if (!empty($actinfo[$key])) echo "$key=$val\n";
         }
         else
            echo "report=ERR\n";
         break;

      case 'acct.peek':
      case 'agmt.peek':
         if ($method=='acct.peek') {
            $acctid = $parmarr['acctid'];
            $resarr = $epayc->acct_peek($acctid);
         }
         else {
            $resarr = $epayc->agmt_get($parmarr, TRUE);
         }
         if (!empty($resarr) && is_array($resarr)) {
            echo "report=OK\n";
            if ($dfmt=='g2') {
               $ra = obj_gserialize($resarr);
               echo "ra=$ra\n";
               if ($cf_debug) echo print_r(obj_gunserialize($ra),TRUE)."\n";
            }
            else {   // default keyval fmt
               foreach ($resarr as $key=>$val)
                  if (!empty($resarr[$key])) echo "$key=$val\n";
            }
         }
         else echo "report=ERR\n";
         break;

      default: // no default
   }
   exit();
}


function array_2addinfo($needles, &$parmarr)
{
   $addinfo = '';
   foreach($needles as $key) {
      if (is_numeric($key) || empty($parmarr[$key])) continue;
      $val = $parmarr[$key];
      if (preg_match('%([_]{0,1}[a-z\d]+)$%i',$key,$matches)) {
         $s = $matches[1].'=';
         $s .= preg_match('%[\:\/\?\&\=\%\+\s\"\'\$\!]%',$val) ? urlencode($val) : $val;
         $addinfo = empty($addinfo) ? $s : ($addinfo.'&'.$s);
      }
   }
   return $addinfo;
}


function proc_agmt_run($epayc, $parmarr)
{
   if (empty($epayc) || !is_object($epayc)) return FALSE;
   if (empty($parmarr) || !is_array($parmarr)) return FALSE;

   $agmt_info = $epayc->agmt_get($parmarr);
   if (!empty($agmt_info)) {
      // $agmt_info contains agmtid, akey etc.
      $parmarr = $agmt_info + $parmarr;
      $txninfo = $epayc->action_new($parmarr);
      if (!empty($txninfo)) {
         $txnid = $txninfo['txnid'];
         $parmarr = $txninfo + $parmarr;
         $runinfo = $epayc->agmt_run($parmarr, TRUE);
         if (!empty($runinfo) && !empty($runinfo['service'])) {

            switch ($runinfo['service']) {
               case 'ADSFPAY':   // FuturePay Agreement
                  if (preg_match('%^([a-z]+)[,]([^,]+)%i', $runinfo['v_addinfo'], $matches)) {
                     $runinfo['v_status'] = strtoupper($matches[1]);
                     $arg2 = $matches[2];
                     if (strcmp($runinfo['v_status'],'Y')==0) {
                        $runinfo['v_txid'] = $arg2;
                        $runinfo['status'] = 'start';
                        $epayc->action_agmtupdate($txnid, $runinfo);
                     }
                  }
                  break;

               default:    // no default
                  $runinfo['v_status'] = '';
            }  // switch
            $epayc->action_run($txnid, $runinfo);
         }
         return $runinfo;
      }
   }

   return FALSE;
}

?>

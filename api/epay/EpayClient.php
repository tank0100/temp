<?
/*
 *  EpayClient.php - Atomic Client for Electronic Gamma Payment
 *  V1.19.20110621
 *
 *  - Client for accessing Associate Gamma Payment table(s)
 *
 *  Change History
 *  ~~~~~~~~~~~~~~
 *  V1.0  CC:  Release version based on AgpClient (2006-08-28).
 *  V1.1  CC:  Added service Cache (2006-09-13).
 *  V1.2  CC:  Added txn_read function (2006-09-18).
 *  V1.3  CC:  Added action_log (2006-09-21).
 *  V1.4  CC:  Added PCN functions (2006-10-03).
 *  V1.5  CC:  Improved authentication in pcn_issue function (2006-10-19).
 *  V1.6  CC:  Added pcn_resellerinfo function (2006-10-20).
 *  V1.7  CC:  Added action_peek function (2006-10-26).
 *  V1.8  CC:  Support Return-Notify API (returlnotify) for service delivery (2006-10-30).
 *  V1.9  CC:  Use txnid to prevent duplicate calls to pcn_request (2006-11-01).
 *  V1.10 ZC:  Added action_agmtadd function (2006-11-10).
 *  V1.11 CC:  Updated agmt_cancel function (2006-12-18).
 *  V1.12 CC:  Updated pcn_issue function to support 'pending' amode (2007-02-07).
 *  V1.13 CC:  Added pcn_start function to 'start' a PCN after charging (2007-02-08).
 *  V1.14 CC:  Added acctid attribute and acct_makeID function (2007-03-28).
 *  V1.15 CC:  Added vendortxn_update function (2007-03-30).
 *  V1.16 CC:  Support itemtxndesc attribute and new pcn_abort function (2007-04-02).
 *  V1.17 ET:  Fixed re-run bug in action_run function (2008-01-08).
 *  V1.18 CC:  Check matching svcid and akey in action_new function (2009-05-21).
 *  V1.19 ET:  Updated Configuration for migration to 1Net.
 */

// load files
include_once(dirname(dirname(dirname(__FILE__)))."/util/mysqlutil.php");
include_once("epay_cfg.php");     // configuration settings
include_once("miscutil.php");     // required, miscellaneous util

class EpayClient {
   var $debug = FALSE;

   var $sql;
   var $logfile;
   var $script_name;
   var $cf_reservedpattern = '[_a-z]{0,1}([\d]+)';

   var $cache_epay;  // local cache
   var $cache_svc;   // service Cache
   var $cache_user;  // user Cache
   var $cache_pcnsvc;   // PCN service Cache

// Constructor
   function EpayClient($script_name='')
   {
      global $cf_debug;

      $this->cache_svc = array();
      $this->cache_user = array();
      $this->cache_pcnsvc = array();

      $this->debug = $this->debug || $cf_debug;
      if (!empty($script_name))
         $this->script_name = $script_name;

      if ($this->debug) $this->genlog ("EpayClient: Init $script_name");
   }


   /**
    *  Adds a new action to the actionQ table.
    *
    *  @return txnid if successful; FALSE otherwise.
    **/
   function action_new($parmarr)
   {
      global $cf_actionQtable;
      global $cf_svcCfgtable;

      // Obtain parameters
      if (empty($parmarr) || !is_array($parmarr)) return FALSE;
      $cntr = preg_match('%([a-z]{2})%', strtolower($parmarr['cntr']), $matches) ? $matches[1] : '';  // optional
      $partnerid = intval($parmarr['partnerid']);  // optional
      $uid = intval($parmarr['uid']);     // optional
      $pass = preg_match("%([a-z\d]{3,})%i", $parmarr['pass'], $matches) ? strtolower($matches[1]) : '';    // optional
      $akey = preg_match("%([a-z\d][_a-z\d]{1,19})%i", $parmarr['akey'], $matches) ? $matches[1] : '';      // optional
      $amtcurrency = preg_match("%([a-z]{3})%i", $parmarr['amtcurrency'], $matches) ? $matches[1] : 'USD';  // default USD
      $amtvalue = floatval($parmarr['amtvalue']);  // assume float
      $itemdesc = trim($parmarr['itemdesc']);
      $txndesc = trim($parmarr['txndesc']);     // optional, txn-specific description
      $action = preg_match("%([a-z\d][_a-z\d]{1,15})%i", $parmarr['action'], $matches) ? $matches[1] : '';  // optional
      $agmtid = preg_match("%([a-z\d][_a-z\d]{1,19})%i", $parmarr['agmtid'], $matches) ? $matches[1] : '';  // optional, Agreement ID
      if (empty($agmtid) && $parmarr['isAgree']) $agmtid = 1;
      $fro = preg_match("%([a-z\d][_a-z\d]{1,19})%i", $parmarr['fro'], $matches) ? $matches[1] : '';
      $svcid = preg_match("%([a-z][a-z_]{2,}[\d]+[a-z_]*)%i", $parmarr['svcid'], $matches) ? strtoupper($matches[1]) : '';
      $service = preg_match("%^([a-z][a-z_]{2,})%i", (!empty($parmarr['service']) ? $parmarr['service'] : $svcid), $matches) ? strtoupper($matches[1]) : '';
      $transaction_subject = (intval($parmarr['transaction_subject']) > 0) ? intval($parmarr['transaction_subject']) : NULL;
      if (empty($service)) return FALSE;  // parms required

      $time = time();
      $timeAt = date('Y-m-d H:i:s', $time);  // current time

      if (!empty($this->sql))
         $sql = $this->sql;
      else {
         $sql = connectdb('appsdb');
         $this->sql = $sql;
      }
      if (empty($sql) || empty($cf_actionQtable)) return FALSE;

      $txnid = FALSE;

      if ($svcid) {
         $svcinfo = $this->service_getinfo($svcid);
         if (empty($svcinfo)) return FALSE;  // invalid svcid
         $amtcurrency = $svcinfo['amtcurrency'];
         $amtvalue = $svcinfo['amtvalue'];
         $itemdesc = $svcinfo['itemdesc'];
         $cntr = $svcinfo['cntr'];
      }
      else {
         $svcinfo = $this->service_get($parmarr);  // obtain svcid
         $svcid = !empty($svcinfo) ? $svcinfo['svcid'] : '';
      }
      if (strlen($itemdesc)==0 || empty($amtvalue)) return FALSE;    // parms required

      if (!empty($svcid)) {   // service available
         $isDone = FALSE;

         if (!$isDone) {   // new action
            // obtain remote ipaddr
            $ra = !empty($GLOBALS['HTTP_REMOTE_ADDR_REAL']) ? $GLOBALS['HTTP_REMOTE_ADDR_REAL'] : (!empty($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '');
            $ipaddr = !empty($_SERVER['HTTP_ORIGINALHOST']) ? $_SERVER['HTTP_ORIGINALHOST'] : $ra;

            // authenticate service
            $query = "select * from $cf_svcCfgtable where service='$service' and (host='%' or '$ipaddr' like host) and (pass='' or pass='$pass') order by id desc limit 1";
            if ($this->debug) $this->genlog ("action_new: $query");
            $sql->QueryRow($query);
            $svc_config = array();     // service configuration
            if (!empty($sql->data)) {     // authenticated
               foreach ($sql->data as $key=>$val)
                  if (!is_numeric($key)) $svc_config[$key] = $val;
            }
            else return FALSE;   // illegal service

            // Update service Cache
            if (empty($this->cache_svc[$service]) || !is_array($this->cache_svc[$service])) $this->cache_svc[$service] = array();
            $this->cache_svc[$service]['info'] = $svc_config + $svcinfo;
            $this->cache_svc[$service]['expires'] = $time + 5*60;    // cached for several mins

            // Check against matching svcid and akey
            $query = "select txnid, itemcode, expiresAt, status, status='run' as isRun from epay_actionQ where svcid='$svcid' and akey='$akey' order by isRun desc limit 1";
            if ($this->debug) $this->genlog ("action_new: $query");
            $sql->QueryRow($query);
            if (!empty($sql->data)) {
               if (in_array($sql->data['status'], array('run','fail','cancel'))) {
                  $txninfo = array('svcid' => $svcid, 'akey' => $akey,
                     'txnid' => $sql->data['txnid'],
                     'itemcode' => $sql->data['itemcode'],
                     'status' => $sql->data['status']);
                  $txninfo['retstatus'] = 'err';
                  return $txninfo;      // action is already completed or terminated
               }
            }

            $acctid = '';
            if ($partnerid) {
               $acctid = $this->acct_makeID($partnerid);
            }
            else if ($uid) {
                $acctid = $this->acct_makeID($uid);
            }

            $update = "replace into $cf_actionQtable set timeAt='$timeAt', expiresAt=adddate(now(), interval 7 day), service='$service', svcid='$svcid', amtvalue='$amtvalue'";
            if (!empty($akey)) $update .= ", akey='$akey'";
            if (!empty($cntr)) $update .= ", cntr='$cntr'";
            if (!empty($partnerid)) $update .= ", partnerid='$partnerid'";
            if (!empty($uid)) $update .= ", uid='$uid'";
            if (!empty($acctid)) $update .= ", acctid='$acctid'";
            if (!empty($agmtid)) $update .= ", agmtid='$agmtid'";
            if (!empty($action)) $update .= ", action='$action'";
            if (!empty($fro)) $update .= ", fro='$fro'";
            $update .= ", ipaddr='$ipaddr'";
            if ($this->debug) $this->genlog ("action_new: $update");
            $sql->Insert($update);
            $id = $sql->insert_id;
            $txnid = (NULL == $transaction_subject) ? $this->gen_makeID ($id, $svcid, $timeAt) : $transaction_subject;
            if ($txnid) {
               $itemcode = $this->acct_itemcode($acctid, $txnid);
               $update = "update $cf_actionQtable set txnid='$txnid', itemcode='$itemcode'";
               if (empty($akey)) $update .= ", akey='".($akey = $txnid)."'";
               $update .= " where id='$id'";
               if ($this->debug) $this->genlog ("action_new: $update");
               $sql->Update($update);
               $isDone = TRUE;

               $txninfo = array('svcid' => $svcid, 'txnid' => $txnid, 'akey' => $akey, 'itemcode'=>$itemcode);
               if (!empty($partnerid)) $txninfo['partnerid'] = $partnerid;
               if (!empty($acctid)) $txninfo['acctid'] = $acctid;
               if (!empty($agmtid) && $agmtid!=1) $txninfo['agmtid'] = $agmtid;
               $txninfo['itemtxndesc'] = empty($txndesc) ? $itemdesc : ($itemdesc.' '.$txndesc);
               return $txninfo;
            }
         }
      }

      return FALSE;
   }


   /**
    *  Retrieves transaction specified by txnid and service.
    *
    *  @mode 'w' to access the master database; defaults to read-only.
    *  @return transaction array if found; FALSE otherwise.
    **/
   function action_read($txnid, $parmarr=NULL, $mode='')
   {
      global $cf_actionQtable;

      // Obtain parameters
      $txnid = intval($txnid);
      if (empty($parmarr) || !is_array($parmarr)) return FALSE;
      $svcid = preg_match("%([a-z][a-z_]{2,}[\d]+[a-z_]*)%i", $parmarr['svcid'], $matches) ? strtoupper($matches[1]) : '';
      $i_service = preg_match("%^([a-z][a-z_]{2,})%i", (!empty($parmarr['service']) ? $parmarr['service'] : $svcid), $matches) ? strtoupper($matches[1]) : '';
      if (empty($i_service)) return FALSE;  // parms required

      // Check time
      $time = !empty($parmarr['timeAt']) ? strtotime($parmarr['timeAt']) : 0;
      if ($time <= 0) $time = time();  // invalid time
      $timeAt = date("Y-m-d H:i:s", $time);

      $isOK = $this->gen_checkID($txnid, $i_service, $timeAt);
      if (!$isOK) return FALSE;

      if (!empty($this->sql))
         $sql = $this->sql;
      else {
         $sql = connectdb('appsdb');
         $this->sql = $sql;
      }
      if (empty($sql) || empty($cf_actionQtable)) return FALSE;

      $query = "select * from $cf_actionQtable where txnid='$txnid' limit 1";
      $mode = !empty($mode) ? trim($mode) : '';
      if ($this->debug) $this->genlog ("action_read: $query".($mode ? " ($mode)":""));
      if (strcasecmp($mode,'w')==0)
         $sql->QueryRow($query,'w');
      else
         $sql->QueryRow($query);
      $actinfo = array();
      if (!empty($sql->data)) {
         foreach ($sql->data as $key=>$val)
            if (!is_numeric($key)) $actinfo[$key] = $val;

         if (in_array($actinfo['status'], array('start','pending'))) {
            if (strtotime($sql->data['expiresAt']) <= $time) {    // expired
               $actinfo['status'] .= '-expired';
            }
         }
      }
      if ($this->debug) $this->genlog ("action_read: ".print_r($actinfo, TRUE));
      return $actinfo;
   }


   function action_peek($parmarr, $isConcise=FALSE)
   {
      global $cf_actionQtable;

      // Obtain parameters
      if (empty($parmarr) || !is_array($parmarr)) return FALSE;
      $txnid = intval($parmarr['txnid']);
      $akey = preg_match("%([a-z\d][_a-z\d]{1,19})%i", $parmarr['akey'], $matches) ? $matches[1] : '';      // optional
      $svcid = preg_match("%([a-z][a-z_]{2,}[\d]+[a-z_]*)%i", $parmarr['svcid'], $matches) ? strtoupper($matches[1]) : '';
      if (empty($svcid) && empty($txnid)) return FALSE;  // parms required

      // Check time
      $time = !empty($parmarr['timeAt']) ? strtotime($parmarr['timeAt']) : 0;
      if ($time <= 0) $time = time();  // invalid time
      $timeAt = date("Y-m-d H:i:s", $time);

      if (!empty($this->sql))
         $sql = $this->sql;
      else {
         $sql = connectdb('appsdb');
         $this->sql = $sql;
      }
      if (empty($sql) || empty($cf_actionQtable)) return FALSE;

      $query = "select *, status='run' as isRun from epay_actionQ";
      if (!empty($txnid)) {
         $query .= " where txnid='$txnid'";
         if (!empty($svcid)) $query .= " and svcid='$svcid'";
      }
      else {   // require svcid
         $query .= " where svcid='$svcid'";
      }
      if (!empty($akey)) $query .= " and akey='$akey'";
      $query .= " order by isRun desc, timeAt desc limit 1";
      if ($this->debug) $this->genlog ("action_peek: $query");
      $sql->QueryRow($query);
      $actinfo = array();
      if (!empty($sql->data)) {
         $ainfo = array();
         foreach ($sql->data as $key=>$val)
            if (!is_numeric($key)) $ainfo[$key] = $val;

         if ($isConcise) {
            $actinfo = array(
               'txnid' => $ainfo['txnid'],
               'svcid' => $ainfo['svcid'],
               'akey' => $ainfo['akey'],
               'cntr' => $ainfo['cntr'],
               'partnerid' => intval($ainfo['partnerid']),
               'amtvalue' => $ainfo['amtvalue'],
               'acctid' => $ainfo['acctid'],
               'status' => $ainfo['status'],
               );
         }
         else $actinfo = $ainfo;    // all attributes
         $actinfo['itemcode'] = $this->acct_itemcode($ainfo['acctid'], $ainfo['txnid']);

         if (in_array($actinfo['status'], array('start','pending'))) {
            if (strtotime($sql->data['expiresAt']) <= $time) {    // expired
               $actinfo['status'] .= '-expired';
            }
         }
      }
      if ($this->debug) $this->genlog ("action_peek: ".print_r($actinfo, TRUE));
      return $actinfo;
   }


   /**
    *  Similar to action_read except only txnid required.
    *
    *  @mode 'w' to access the master database; defaults to read-only.
    *  @return transaction array if found; FALSE otherwise.
    **/
   function txn_read($txnid, $parmarr=NULL, $mode='')
   {
      global $cf_actionQtable;

      // Obtain parameters
      $txnid = intval($txnid);
      if (empty($parmarr) || !is_array($parmarr)) $parmarr = array();
      $akey = preg_match("%([a-z\d][_a-z\d]{1,19})%i", $parmarr['akey'], $matches) ? $matches[1] : '';   // optional

      // Check time
      $time = !empty($parmarr['timeAt']) ? strtotime($parmarr['timeAt']) : 0;
      if ($time <= 0) $time = time();  // invalid time
      $timeAt = date("Y-m-d H:i:s", $time);

      if (!empty($this->sql))
         $sql = $this->sql;
      else {
         $sql = connectdb('appsdb');
         $this->sql = $sql;
      }
      if (empty($sql) || empty($cf_actionQtable)) return FALSE;

      $query = "select * from $cf_actionQtable";
      if ($txnid)    // txnid takes precedence
         $query .= " where txnid='$txnid'";
      else if ($akey)
         $query .= " where akey='$akey'";
      $query .= " order by timeAt desc limit 1";
      $mode = !empty($mode) ? trim($mode) : '';
      if ($this->debug) $this->genlog ("txn_read: $query".($mode ? " ($mode)":""));
      if (strcasecmp($mode,'w')==0)
         $sql->QueryRow($query,'w');
      else
         $sql->QueryRow($query);
      $actinfo = array();
      if (!empty($sql->data)) {
         foreach ($sql->data as $key=>$val)
            if (!is_numeric($key)) $actinfo[$key] = $val;

         if (in_array($actinfo['status'], array('start','pending'))) {
            if (strtotime($sql->data['expiresAt']) <= $time) {    // expired
               $actinfo['status'] .= '-expired';
            }
         }
      }
      if ($this->debug) $this->genlog ("txn_read: ".print_r($actinfo, TRUE));
      return $actinfo;
   }


   /**
    *  Add transaction details to vendorTxn table.
    *
    **/
   function vendortxn_update($v_txid, $parmarr=NULL)
   {
      global $cf_vendorTxntable;

      if (empty($parmarr) || !is_array($parmarr)) $parmarr = array();   // optional

      // Parameters...
      $v_info['v_txid'] = !empty($v_txid) ? trim($v_txid) : '';
      if (empty($v_txid)) return FALSE;   // required parm
      $v_info['txnid'] = intval($parmarr['txnid']);
      $v_info['svcid'] = preg_match("%([a-z][a-z_]{2,}[\d]+[a-z_]*)%i", $parmarr['svcid'], $matches) ? strtoupper($matches[1]) : '';
      $v_info['itemcode'] = preg_match("%([\d]+[\d-]+)%", $parmarr['itemcode'], $matches) ? $matches[1] : '';
      $v_info['acctid'] = preg_match("%([\d]+[-][\d]+)%", $parmarr['acctid'], $matches) ? $matches[1] : '';    // optional
      $v_info['v_amount'] = floatval($parmarr['amount']);
      $v_info['v_currency'] = preg_match("%([a-z]{3})%i", $parmarr['currency'], $matches) ? $matches[1] : '';  // no default
      $v_info['v_status'] = trim($parmarr['status']);
      $v_info['v_payer'] = trim($parmarr['payer']);
      $v_info['v_payerid'] = trim($parmarr['payerid']);
      $v_info['v_payername'] = trim($parmarr['payername']);
      $v_info['v_country'] = trim($parmarr['country']);

      $time = !empty($parmarr['timeAt']) ? strtotime($parmarr['timeAt']) : 0;
      if ($time <= 0) $time = time();  // invalid time
      $v_info['v_timeAt'] = date("Y-m-d H:i:s", $time);

      // $v_info contains txnid, v_txid, etc.
      if ($this->debug) $this->genlog("vendortxn_update: ".print_r($v_info,TRUE));
      extract($v_info);   // into current symbol table

      if (!empty($this->sql))
         $sql = $this->sql;
      else {
         $sql = connectdb('appsdb');
         $this->sql = $sql;
      }
      if (empty($sql) || empty($cf_vendorTxntable)) return FALSE;

      // Find in vendorTxn table...
      $query = "select * from $cf_vendorTxntable where v_txid='$v_txid' order by updatedAt desc limit 1";
      $sql->QueryRow($query);
      if (!empty($sql->data)) return TRUE;

      // Save in vendorTxn table...
      $update = "insert into $cf_vendorTxntable set updatedAt=now()";
      $update .= ", txnid='$txnid', svcid='$svcid'";
      $update .= ", itemcode='".$sql->EscapeString($itemcode)."'";
      if (!empty($acctid)) $update .= ", acctid='$acctid'";
      $update .= ", v_txid='".$sql->EscapeString($v_txid)."', v_amount='$v_amount', v_currency='$v_currency', v_status='".$sql->EscapeString($v_status)."'";
      $update .= ", v_payer='".$sql->EscapeString($v_payer)."', v_payerid='".$sql->EscapeString($v_payerid)."', v_payername='".$sql->EscapeString($v_payername)."'";
      if (!empty($v_country)) $update .= ", v_country='".$sql->EscapeString($v_country)."'";
      $update .= ", v_timeAt='$v_timeAt'";
      if ($this->debug) $this->genlog ("vendortxn_update: $update");
      $sql->Update($update);

      return TRUE;
   }


   /**
    *  Changes transaction status to 'start', when user authorizes the
    *  transaction.
    *  NB: (svcid, txnid) should be supplied in $parmarr parameter.
    *
    *  @return TRUE if status updated; FALSE otherwise.
    **/
   function action_start($txnid, $parmarr=NULL)
   {
      global $cf_actionQtable;

      // Obtain parameters
      $txnid = intval($txnid);
      if (empty($parmarr) || !is_array($parmarr)) return FALSE;
      $svcid = preg_match("%([a-z][a-z_]{2,}[\d]+[a-z_]*)%i", $parmarr['svcid'], $matches) ? strtoupper($matches[1]) : '';
      $i_service = preg_match("%^([a-z][a-z_]{2,})%i", (!empty($parmarr['service']) ? $parmarr['service'] : $svcid), $matches) ? strtoupper($matches[1]) : '';
      if (empty($i_service)) return FALSE;   // parms required

      // $parmarr contains txnid, v_txid, v_status, v_addinfo etc.
      extract($parmarr);   // into current symbol table
      // Check time
      $time = !empty($timeAt) ? strtotime($timeAt) : 0;
      if ($time <= 0) $time = time();  // invalid time
      $timeAt = date("Y-m-d H:i:s", $time);

      $isOK = $this->gen_checkID($txnid, $i_service, $timeAt);
      if (!$isOK) return FALSE;

      if (!empty($this->sql))
         $sql = $this->sql;
      else {
         $sql = connectdb('appsdb');
         $this->sql = $sql;
      }
      if (empty($sql) || empty($cf_actionQtable)) return FALSE;

      $update = "update $cf_actionQtable set status='start', updatedAt='$timeAt'";
      $update .= ", v_txid='".$sql->EscapeString($v_txid)."', v_status='".$sql->EscapeString($v_status)."'";
      if (!empty($v_addinfo)) $update .= ", v_addinfo='".$sql->EscapeString($v_addinfo)."'";
      $update .= " where txnid='$txnid' and (status='pending')";
      $v_amount = floatval($v_amount);
      if (!empty($v_amount)) $update .= " and amtvalue='$v_amount'";
      if ($this->debug) $this->genlog ("action_start: $update");
      $sql->Update($update);
      $isOK = $sql->a_rows >= 1;

      return $isOK;
   }


   /**
    *  Changes transaction status from 'start' to 'run', when
    *  transaction is Completed.
    *
    *  @return TRUE if transaction Completed; FALSE otherwise.
    **/
   function action_run($txnid, $parmarr=NULL)
   {
      global $cf_actionQtable;

      // Obtain parameters
      $txnid = intval($txnid);
      if (empty($parmarr) || !is_array($parmarr)) return FALSE;
      $svcid = preg_match("%([a-z][a-z_]{2,}[\d]+[a-z_]*)%i", $parmarr['svcid'], $matches) ? strtoupper($matches[1]) : '';
      $i_service = preg_match("%^([a-z][a-z_]{2,})%i", (!empty($parmarr['service']) ? $parmarr['service'] : $svcid), $matches) ? strtoupper($matches[1]) : '';
      $v_txid = preg_match("%([a-z\d][_a-z\d]{1,19})%i", $parmarr['v_txid'], $matches) ? $matches[1] : '';
      $agmtid = preg_match("%([a-z\d][_a-z\d]{1,19})%i", $parmarr['agmtid'], $matches) ? $matches[1] : '';  // optional, AgreementID
      $status = preg_match("%([a-z]+)%i", $parmarr['status'], $matches) ? strtolower($matches[1]) : '';
      if (empty($txnid) || empty($svcid) || empty($i_service)) return FALSE;  // parms required

      // $parmarr contains v_txid, v_status, v_currency, v_amount etc.

      // Check time
      $time = !empty($parmarr['timeAt']) ? strtotime($parmarr['timeAt']) : 0;
      if ($time <= 0) $time = time();  // invalid time
      $timeAt = date("Y-m-d H:i:s", $time);

      $isOK = $this->gen_checkID($txnid, $i_service, $timeAt);
      if (!$isOK) return FALSE;

      if (!empty($this->sql))
         $sql = $this->sql;
      else {
         $sql = connectdb('appsdb');
         $this->sql = $sql;
      }
      if (empty($sql) || empty($cf_actionQtable)) return FALSE;

      // Check if already 'run'
      $query = "select * from $cf_actionQtable where txnid='$txnid' limit 1";
      $sql->QueryRow($query,'w');   // master database
      if (!empty($sql->data)) {
         $txninfo = array(
            'svcid' => $svcid,
            'txnid' => $txnid,
            'akey' => $sql->data['akey'],
            'v_txid' => $v_txid,
            );
         if (!empty($sql->data['partnerid'])) $txninfo['partnerid'] = $sql->data['partnerid'];
         if (!empty($sql->data['acctid'])) $txninfo['acctid'] = $sql->data['acctid'];
         $txninfo['itemcode'] = $this->acct_itemcode($sql->data['acctid'], $txninfo['txnid']);
         if (!empty($agmtid)) $txninfo['agmtid'] = $agmtid;

         if (strcasecmp($sql->data['status'],'run')==0) {
            $txninfo['status'] = 'run';
            return $txninfo;     // Completed
         }
      }
      else return FALSE;

      // Obtain service info
      $txninfo['fro'] = $this->script_name;
      $txnsvc_info = $this->service_fillinfo($txninfo);

      $v_status = trim($parmarr['v_status']);
      $v_addinfo = trim($parmarr['v_addinfo']);
      $isCompleted = (strcasecmp($status,'start')==0)
         && (empty($parmarr['v_currency']) || strcasecmp($parmarr['v_currency'], $txnsvc_info['amtcurrency'])==0)
         && (empty($parmarr['v_amount']) || abs(floatval($parmarr['v_amount'])-floatval($txnsvc_info['amtvalue'])) <= 0.01);

      // Call vendor API to check transaction...
      $cgiurl = $txnsvc_info['cgitxncheck'];
      if (!empty($cgiurl)) {
         $ret = trim(@file_get_contents($cgiurl));
         $infoarr = array();
         if (preg_match_all("%^([a-z].*)=(.*)$%im", $ret, $matches)) {  // keyval-format
            foreach ($matches[1] as $idx=>$key)
               $infoarr[$key] = $matches[2][$idx];
         }
         if (!empty($infoarr['v_addinfo'])) $v_addinfo = $infoarr['v_addinfo'];

         // Compare important parameters
         $isCompleted = (strcasecmp($infoarr['status'],'run')==0)
            && (strcasecmp($infoarr['v_txid'], $txnsvc_info['v_txid'])==0)
            && (strcasecmp($infoarr['v_currency'], $txnsvc_info['amtcurrency'])==0)
            && (abs(floatval($infoarr['v_amount'])-floatval($txnsvc_info['amtvalue'])) <= 0.01);
      }

      $status = $isCompleted ? 'run' : 'fail';
      $isOK = FALSE;

			// Impt: This prevents re-runs, please do not modify.
			$sql->Queryrow("select txnid from $cf_actionQtable where txnid='$txnid' and (status='run' or status='fail') limit 1", 'w');
			$isOK = empty($sql->data);

      $update = "update $cf_actionQtable set status='$status', updatedAt='$timeAt'";
      if (!empty($agmtid)) $update .= ", agmtid='".$sql->EscapeString($agmtid)."'";
      $update .= ", v_txid='".$sql->EscapeString($v_txid)."', v_status='".$sql->EscapeString($v_status)."'";
      if (!empty($v_addinfo)) $update .= ", v_addinfo='".$sql->EscapeString($v_addinfo)."'";
      $update .= " where txnid='$txnid'";
      if ($this->debug) $this->genlog ("action_run: $update");
      $sql->Update($update);
      $isOK = $isOK && ($sql->a_rows >= 1);

      if ($isOK) {
         $txninfo['status'] = $status;
         if ($status=='run') {   // completed charging
            $txnsvc_info['status'] = $status;
            $txnsvc_info['updatedAt'] = $timeAt;
            $txnsvc_info['v_addinfo'] = $v_addinfo;
            $this->action_log ($txnsvc_info, $timeAt);   // log action

            // Return-Notify API to perform service delivery...
            $cgiurl = $txnsvc_info['returlnotify'];
            if (!empty($cgiurl)) {
               $this->genlog ("action_run: File $cgiurl");
               $ret = trim(@file_get_contents($cgiurl));
               $isRet = preg_match('%^(OK|1[^\d])%im', $ret);  // Return-Notified

               if ($isRet) {  // Return-Notified
                  $update = "update $cf_actionQtable set isRet='Y' where txnid='$txnid'";
                  if ($this->debug) $this->genlog ("action_run: $update");
                  $sql->Update($update);
               }
            }
         }
      }

      // Auto-cleanup
      $update = "delete from $cf_actionQtable where expiresAt < subdate(now(), interval 1 month)";
      $sql->Update($update);

      return $isOK ? $txninfo : FALSE;
   }


   /**
    *  Changes transaction status when user or partner cancels the transaction.
    *
    *  @return TRUE if mid known and status updated; FALSE otherwise.
    **/
   function action_abort($txnid, $parmarr=NULL, $astatus='')
   {
      global $cf_actionQtable;

      // Obtain parameters
      $txnid = intval($txnid);
      if (empty($parmarr) || !is_array($parmarr)) return FALSE;
      $svcid = preg_match("%([a-z][a-z_]{2,}[\d]+[a-z_]*)%i", $parmarr['svcid'], $matches) ? strtoupper($matches[1]) : '';
      $i_service = preg_match("%^([a-z][a-z_]{2,})%i", (!empty($parmarr['service']) ? $parmarr['service'] : $svcid), $matches) ? strtoupper($matches[1]) : '';
      $astatus = preg_match("%([a-z][a-z_]{2,})%i", $astatus, $matches) ? strtolower($matches[1]) : 'cancel';
      if (empty($i_service) || strcmp($astatus,'run')==0) return FALSE;    // parms required

      // $parmarr contains txnid, v_txid, v_status, v_addinfo etc.
      extract($parmarr);   // into current symbol table
      // Check time
      $time = !empty($timeAt) ? strtotime($timeAt) : 0;
      if ($time <= 0) $time = time();  // invalid time
      $timeAt = date("Y-m-d H:i:s", $time);

      $isOK = $this->gen_checkID($txnid, $i_service, $timeAt);
      if (!$isOK) return FALSE;

      if (!empty($this->sql))
         $sql = $this->sql;
      else {
         $sql = connectdb('appsdb');
         $this->sql = $sql;
      }
      if (empty($sql) || empty($cf_actionQtable)) return FALSE;

      $update = "update $cf_actionQtable set status='$astatus', updatedAt='$timeAt'";
      if (!empty($v_txid)) $update .= ", v_txid='".$sql->EscapeString($v_txid)."', v_status='".$sql->EscapeString($v_status)."', v_addinfo='".$sql->EscapeString($v_addinfo)."'";
      $update .= " where txnid='$txnid' and (status='pending' || status='start' || status='suspend')";
      $sql->Update($update);
      $isOK = $sql->a_rows >= 1;

      return $isOK;
   }


   function action_log($parmarr, $timeAt='')
   {
      global $cf_actionlogtable;

      // Check time
      $time = !empty($timeAt) ? strtotime($timeAt) : 0;
      if ($time <= 0) $time = time();  // invalid time
      $timeAt = date("Y-m-d H:i:s", $time);

      if (!empty($this->sql))
         $sql = $this->sql;
      else {
         $sql = connectdb('appsdb');
         $this->sql = $sql;
      }
      if (empty($sql) || empty($cf_actionlogtable)) return FALSE;

      if (empty($cache_epay)) $cache_epay = array('isTableChecked'=>array());   // initialize Cache

      // Obtain tablename...
      $suffix = date("Ym", $time);
      $logtable = $cf_actionlogtable.$suffix;

      $attribarr = array('txnid', 'status', 'cntr', 'svclid', 'svcid', 'akey', 'amtcurrency', 'amtvalue', 'action', 'agmtid', 'acctid', 'v_txid', 'v_addinfo');

      $isNewtable = TRUE;  // assume new table
      while ($isNewtable) {
         $update = "insert into $logtable set timeAt='$timeAt'";
         foreach ($attribarr as $key) {
            if (!empty($parmarr[$key])) {
               $val = is_string($parmarr[$key]) ? $sql->EscapeString($parmarr[$key]) : $parmarr[$key];
               $update .= ", $key='$val'";
            }
         }
         if ($this->debug) $this->genlog ("action_log: $update");
         $sql->Insert($update);

         if ($isNewtable && $sql->errno) {   // MySQL Error: 1146 (ER_NO_SUCH_TABLE)
            if (empty($this->cache_epay['isTableChecked'][$logtable])) {    // not yet checked
               $this->checkTable($sql, $logtable, 'log');
               $this->cache_epay['isTableChecked'][$logtable] = TRUE;
               continue;   // $isNewtable still TRUE
            }
         }
         $isNewtable = FALSE;
      }
      return;
   }


   function service_get($parmarr, $timeAt='')
   {
      global $cf_svclibtable;

      // Obtain parameters
      if (empty($parmarr) || !is_array($parmarr)) return FALSE;
      $cntr = preg_match('%([a-z]{2})%', strtolower($parmarr['cntr']), $matches) ? $matches[1] : '';  // optional
      $amtcurrency = preg_match("%([a-z]{3})%i", $parmarr['amtcurrency'], $matches) ? $matches[1] : 'USD';  // default USD
      $amtvalue = floatval($parmarr['amtvalue']);  // assume float
      $gmdvalue = !empty($parmarr['gmdvalue']) ? intval($parmarr['gmdvalue']) : 0;  // optional
      $itemdesc = trim($parmarr['itemdesc']);
      $crc32 = crc32String($itemdesc);
      $msglen = strlen($itemdesc);
      $svcid = preg_match("%([a-z][a-z_]{2,}[\d]+[a-z_]*)%i", $parmarr['svcid'], $matches) ? strtoupper($matches[1]) : '';
      $service = preg_match("%^([a-z][a-z_]{2,})%i", (!empty($parmarr['service']) ? $parmarr['service'] : $svcid), $matches) ? strtoupper($matches[1]) : '';
      if (empty($service)) return FALSE;   // parms required

      // Check time
      $time = !empty($timeAt) ? strtotime($timeAt) : 0;
      if ($time <= 0) $time = time();  // invalid time
      $timeAt = date("Y-m-d H:i:s", $time);

      if (!empty($this->sql))
         $sql = $this->sql;
      else {
         $sql = connectdb('appsdb');
         $this->sql = $sql;
      }
      if (empty($sql) || empty($cf_svclibtable)) return FALSE;

      $svcinfo = array();
      if (!empty($svcid)) {
         $query = "select * from $cf_svclibtable where svcid='$svcid' order by svclid desc limit 1";
         if ($this->debug) $this->genlog ("service_get: $query");
         $sql->QueryRow($query,'w');
         if (!empty($sql->data)) {
            foreach ($sql->data as $key=>$val)
               if (!is_numeric($key)) $svcinfo[$key] = $val;
            return $svcinfo;
         }
         else
            return FALSE;  // invalid svcid
      }
      else {  // svcid not specified
         if (strlen($itemdesc)==0 || empty($amtvalue)) return FALSE;    // parms required

         $query = "select * from $cf_svclibtable where amtcurrency='$amtcurrency' and service='$service' and crc32='$crc32' and msglen='$msglen'";   // may have multiple matches
         if (!empty($cntr)) $query .= " and cntr='$cntr'";
         if ($this->debug) $this->genlog ("service_get: $query");
         $resultarr = array();
         $sql->Query($query);
         $sql->result_push($resultarr);
         $svcid = '';
         foreach ($resultarr as $row) {
            $svcdesc = $row['itemdesc'];
            $svcvalue = floatval($row['amtvalue']);
            if (strcasecmp($svcdesc,$itemdesc)==0 && abs($svcvalue-$amtvalue) < 0.01) {    // a match
               $svcid = $row['svcid'];
               $svcinfo = $row;
               break;
            }
         }

         if (empty($svcid)) {    // new Service
            $update = "insert into $cf_svclibtable set addedAt='$timeAt', service='$service'";
            $update .= ", amtcurrency='$amtcurrency', amtvalue='$amtvalue'";
            if (!empty($gmdvalue)) $update .= ", gmdvalue='$gmdvalue'";
            $update .= ", itemdesc='".$sql->EscapeString($itemdesc)."'";
            $update .= ", msglen='$msglen', crc32='$crc32'";
            if (!empty($cntr)) $update .= ", cntr='$cntr'";
            if ($this->debug) $this->genlog ("service_get: $update");
            $sql->Insert($update);
            $svclid = $sql->insert_id;

            // generate unique svcid...
            $isOK = FALSE;
            for ($j=0; $j<=27; $j++) {
               if ($j==0)
                  $svcid = $service.sprintf("%04u", $amtvalue).strtoupper($amtcurrency{0});
               else if ($j<=26)
                  $svcid = $service.sprintf("%04u", $amtvalue).chr(64 + intval($j));
               else
                  $svcid = $service.sprintf("%05u", $svclid);
               if (!empty($cntr)) $svcid .= '_'.strtoupper($cntr);

               $query = "select * from $cf_svclibtable where svcid='$svcid' limit 1";
               $sql->QueryRow($query,'w');
               $isExists = !empty($sql->data);

               if (!$isExists) {    // unique
                  $update = "update $cf_svclibtable set svcid='$svcid' where service = '$service' and svclid = '$svclid'";
                  $sql->Update($update);
                  $isOK = TRUE;
                  break;
               }
            }

            if ($isOK) {
               $svcinfo = array(
                  'svclid' => $svclid,
                  'addedAt' => $timeAt,
                  'service' => $service,
                  'svcid' => $svcid,
                  'amtcurrency' => $amtcurrency,
                  'amtvalue' => $amtvalue,
                  'itemdesc' => $itemdesc,
                  'cntr' => $cntr,
                  );
               if (!empty($gmdvalue)) $svcinfo['gmdvalue'] = $gmdvalue;
            }
         }
      }

      return $svcinfo;
   }


   /**
    *  Retrieves info associated with transaction specified by txnid.
    *  Uses service_getinfo to retrieve service-related info.
    **/
   function service_fillinfo($txninfo)
   {
      global $cf_agmtCfgtable;

      if (empty($txninfo) || !is_array($txninfo)) return FALSE;

      // $txninfo contains svcid, txnid, akey etc.
      extract($txninfo);   // into current symbol table
      if (empty($txnid)) return FALSE;

      $svcinfo = $this->service_getinfo($svcid);
      if (empty($svcinfo)) return NULL;  // invalid service

      // Retrieve Agreement information, if any...
      if (strcasecmp($svcinfo['hasAgmt'],'Y')==0) {
         if (empty($txninfo['agmtid'])) {
            $agmt_info = $this->agmt_get($txninfo);
            if (!empty($agmt_info))
               $txninfo['agmtid'] = $agmt_info['agmtid'];
         }
         $txninfo['isAgree'] = 'Y';    // assume 'Y'
      }

      // Gather parms
      $txnsvc_info = $svcinfo + $txninfo;
      $parms = array();
      foreach ($txnsvc_info as $key=>$val) {
         if (!is_numeric($key) && !in_array($key,array('svcurl', 'svcparms'))) {
            if (preg_match('%^(url|cgi)%', $key) || preg_match('%(url|cgi)$%', $key)) continue;
            $parms[$key] = urlencode($val);
         }
      }

      // Process urls first
      foreach ($txnsvc_info as $key=>$val) {
         if (preg_match('%^(url)%', $key)) {
            $txnsvc_info[$key] = msg_val($val, $parms);
            $parms[$key] = urlencode($txnsvc_info[$key]);
         }
      }

      // Process svcurl and svcparms
      foreach ($txnsvc_info as $key=>$val) {
         if (preg_match('%^(svc|agmt).*url$%', $key))
            if (!preg_match('%[?]%',$val)) $txnsvc_info[$key] .= '?';
         if (preg_match('%^(svc|agmt).*parms$%', $key))
            $txnsvc_info[$key] = msg_val($val, $parms);  // update $txnsvc_info
      }

      // Process cgis
      if (!empty($parms['v_txid'])) {
         foreach ($txnsvc_info as $key=>$val) {
            if (preg_match('%^(cgi)%', $key))
               $txnsvc_info[$key] = msg_val($val, $parms);
         }
      }

      // Process returls...
      if (!empty($parms['retstatus']))
         $txnsvc_info['returl'] = msg_val($txnsvc_info['returl'], $parms);
      $txnsvc_info['returlnotify'] = msg_val($txnsvc_info['returlnotify'], $parms);

      if ($this->debug) $this->genlog ("service_fillinfo: ".print_r($txnsvc_info, TRUE));
      return $txnsvc_info;
   }


   function service_getinfo($svcid, $timeAt='')
   {
      global $cf_svclibtable;
      global $cf_svcCfgtable;
      global $cf_agmtCfgtable;

      // Obtain parameters
      $svcid = preg_match("%([a-z][a-z_]{2,}[\d]+[a-z_]*)%i", $svcid, $matches) ? strtoupper($matches[1]) : '';
      if (empty($svcid)) return FALSE;
      $service = preg_match("%^([a-z][a-z_]{2,})%i", $svcid, $matches) ? strtoupper($matches[1]) : '';
      if (empty($service)) return FALSE;

      // Check service Cache
      $now = time();
      if (empty($this->cache_svc[$service]) || !is_array($this->cache_svc[$service])) $this->cache_svc[$service] = array();
      $svcinfo = $this->cache_svc[$service]['info'];
      if (is_array($svcinfo)) {
         if (!empty($this->cache_svc[$service]['expires']) && ($now < $this->cache_svc[$service]['expires'])) {   // cache valid
            if ($this->debug) $this->genlog ("service_getinfo: [Cache] ".print_r($svcinfo, TRUE));
            return $svcinfo;
         }
      }

      // Check time
      $time = !empty($timeAt) ? strtotime($timeAt) : 0;
      if ($time <= 0) $time = time();  // invalid time
      $timeAt = date("Y-m-d H:i:s", $time);

      if (!empty($this->sql))
         $sql = $this->sql;
      else {
         $sql = connectdb('appsdb');
         $this->sql = $sql;
      }
      if (empty($sql) || empty($cf_svcCfgtable) || empty($cf_svclibtable)) return FALSE;

      $hasInfo = FALSE;

      $query = "select * from $cf_svclibtable where svcid='$svcid' limit 1";
      if ($this->debug) $this->genlog ("service_getinfo: $query");
      $sql->QueryRow($query,'w');
      $svcinfo = array();
      if (!empty($sql->data)) {
         foreach ($sql->data as $key=>$val)
            if (!is_numeric($key)) $svcinfo[$key] = $val;
         $hasInfo = TRUE;
      }

      if ($hasInfo) {
         // retrieve service config
         $query = "select * from $cf_svcCfgtable where service='$service' order by id desc limit 1";
         if ($this->debug) $this->genlog ("service_getinfo: $query");
         $sql->QueryRow($query);
         $svc_config = array();     // service configuration
         if (!empty($sql->data)) {     // authenticated
            foreach ($sql->data as $key=>$val)
               if (!is_numeric($key)) $svc_config[$key] = $val;
            $svcinfo = $svc_config + $svcinfo;

            // Retrieve Agreement config, if any...
            if (strcasecmp($svcinfo['hasAgmt'],'Y')==0) {
               if (!empty($cf_agmtCfgtable)) {
                  $query = "select * from $cf_agmtCfgtable where service='".$svcinfo['service']."' limit 1";
                  if ($this->debug) $this->genlog ("service_getinfo: $query");
                  $sql->QueryRow($query);
                  if (!empty($sql->data)) {
                     foreach ($sql->data as $key=>$val)
                        if (!is_numeric($key)) $svcinfo[$key] = $val;
                  }
               }
            }
         }
      }

      // Update service Cache
      if (empty($this->cache_svc[$service]) || !is_array($this->cache_svc[$service])) $this->cache_svc[$service] = array();
      $this->cache_svc[$service]['info'] = $svcinfo;
      $this->cache_svc[$service]['expires'] = $now + (!empty($svcinfo) ? 5*60 : 1*60);    // cached for several mins

      //if ($this->debug) $this->genlog ("service_getinfo: ".print_r($svcinfo, TRUE));
      return $svcinfo;
   }


   /**
    *  Retrieves AgreementID corresponding to svcid and partnerid, if any.
    *
    *  NB: status - pending:pre-active; start:active; run:used
    **/
   function agmt_get($parmarr, $isConcise=FALSE)
   {
      global $cf_agmttable;

      // Obtain parameters
      if (empty($parmarr) || !is_array($parmarr)) return FALSE;
      $svcid = preg_match("%([a-z][a-z_]{2,}[\d]+[a-z_]*)%i", $parmarr['svcid'], $matches) ? strtoupper($matches[1]) : '';
      $partnerid = intval($parmarr['partnerid']);
      if (empty($svcid) || empty($partnerid)) return FALSE;    // parms required

      if (!empty($this->sql))
         $sql = $this->sql;
      else {
         $sql = connectdb('appsdb');
         $this->sql = $sql;
      }
      if (empty($sql) || empty($cf_agmttable)) return FALSE;

      $query = "select * from $cf_agmttable where svcid='$svcid' and partnerid='$partnerid' and (status='pending' or status='start')";
      $query .= " and (expiresAt=0 or expiresAt > now())";
      $query .= " order by startAt desc limit 1";
      if ($this->debug) $this->genlog ("agmt_get: $query");
      $sql->QueryRow($query);

      $agmt_info = array();
      if (!empty($sql->data)) {
         $ainfo = array();
         foreach ($sql->data as $key=>$val)
            if (!is_numeric($key)) $ainfo[$key] = $val;

         if ($isConcise) {
            $agmt_info = array(
               'agmtid' => $ainfo['agmtid'],
               'svcid' => $ainfo['svcid'],
               'partnerid' => $ainfo['partnerid'],
               'amtcurrency' => $ainfo['amtcurrency'],
               'amtvalue' => floatval($ainfo['amtvalue']),
               'minInterval' => intval($ainfo['minInterval']),
               'startAt' => $ainfo['startAt'],
               'akey' => $ainfo['akey'],
               'status' => $ainfo['status'],
               );
         }
         else $agmt_info = $ainfo;    // all attributes

         $agmt_info['action'] = 'charge';
      }

      return $agmt_info;
   }


   function agmt_run($parmarr)
   {
      global $cf_agmttable;

      // Obtain parameters
      if (empty($parmarr) || !is_array($parmarr)) return FALSE;
      $txnid = intval($parmarr['txnid']);
      $partnerid = intval($parmarr['partnerid']);
      $akey = preg_match("%([a-z\d][_a-z\d]{1,19})%i", $parmarr['akey'], $matches) ? $matches[1] : '';      // optional
      $svcid = preg_match("%([a-z][a-z_]{2,}[\d]+[a-z_]*)%i", $parmarr['svcid'], $matches) ? strtoupper($matches[1]) : '';
      $i_service = preg_match("%^([a-z][a-z_]{2,})%i", (!empty($parmarr['service']) ? $parmarr['service'] : $svcid), $matches) ? strtoupper($matches[1]) : '';
      $agmtid = preg_match("%([a-z\d][_a-z\d]{1,19})%i", $parmarr['agmtid'], $matches) ? $matches[1] : '';  // optional, AgreementID
      $fro = preg_match("%([a-z\d][_a-z\d]{1,19})%i", $parmarr['fro'], $matches) ? $matches[1] : '';
      if (empty($agmtid) || empty($svcid) || empty($partnerid)) return FALSE;    // parms required

      if (!empty($this->sql))
         $sql = $this->sql;
      else {
         $sql = connectdb('appsdb');
         $this->sql = $sql;
      }
      if (empty($sql) || empty($cf_agmttable)) return FALSE;

      $txninfo = array(
         'svcid' => $svcid,
         'txnid' => $txnid,
         'akey' => $akey,
         'partnerid' => $partnerid,
         'agmtid' => $agmtid,
         'fro' => $fro,
         );

      // Obtain service info
      $txnsvc_info = $this->service_fillinfo($txninfo);

      // Call vendor API to perform transaction...
      $cgiurl = $txnsvc_info['agmturl'].$txnsvc_info['agmtparms'];
      if (!empty($cgiurl)) {
         $this->genlog ("agmt_run: File $cgiurl");
         $ret = trim(@file_get_contents($cgiurl));
         $v_addinfo = $ret;
         $isCompleted = TRUE;

         $txninfo['v_addinfo'] = $v_addinfo;
         // Useful attributes...
         $txninfo['service'] = $i_service;
         $txninfo['v_amount'] = $txnsvc_info['amtvalue'];
         $txninfo['v_currency'] = $txnsvc_info['amtcurrency'];
      }
      else {   // assume fail
         $v_addinfo = '';
         $isCompleted = FALSE;
      }

      return $isCompleted ? $txninfo : FALSE;
   }


   function agmt_cancel($parmarr)
   {
      global $cf_agmttable;

      // Obtain parameters
      if (empty($parmarr) || !is_array($parmarr)) return FALSE;
      $svcid = preg_match("%([a-z][a-z_]{2,}[\d]+[a-z_]*)%i", $parmarr['svcid'], $matches) ? strtoupper($matches[1]) : '';
      $partnerid = intval($parmarr['partnerid']);
      $agmtid = preg_match("%([a-z\d][_a-z\d]{1,19})%i", $parmarr['agmtid'], $matches) ? $matches[1] : '';  // optional, AgreementID
      $fro = preg_match("%([a-z\d][_a-z\d]{1,19})%i", $parmarr['fro'], $matches) ? $matches[1] : '';
      if (empty($svcid) || (empty($partnerid) && empty($agmtid))) return FALSE;  // parms required

      if (!empty($this->sql))
         $sql = $this->sql;
      else {
         $sql = connectdb('appsdb');
         $this->sql = $sql;
      }
      if (empty($sql) || empty($cf_agmttable)) return FALSE;

      $query = "select * from $cf_agmttable where svcid='$svcid'";
      if (empty($agmtid)) {
         $query .= " and partnerid='$partnerid' and (status='pending' or status='start')";
         $query .= " and (expiresAt=0 or expiresAt > now())";
      }
      else {
         if (!empty($partnerid)) $query .= " and partnerid='$partnerid'";
         $query .= " and agmtid='$agmtid'";
      }
      $query .= " order by startAt desc limit 1";
      if ($this->debug) $this->genlog ("agmt_cancel: $query");
      $sql->QueryRow($query);

      if (empty($sql->data)) return FALSE;   // nothing to do
      $id = $sql->data['id'];
      $agmt_info = array(
         'txnid' => 1,  // dummy transaction
         'svcid' => $svcid,
         'partnerid' => $sql->data['partnerid'],
         'agmtid' => $sql->data['agmtid'],
         'fro' => $fro,
         );
      $agmt_info['action'] = 'cancel';

      // Obtain service info
      $agmtsvc_info = $this->service_fillinfo($agmt_info);

      // Call vendor API to perform transaction...
      $cgiurl = $agmtsvc_info['agmtcancelurl'].$agmtsvc_info['agmtcancelparms'];
      if (!empty($cgiurl)) {
         $this->genlog ("agmt_cancel: File $cgiurl");
         $ret = trim(@file_get_contents($cgiurl));
         $v_addinfo = $ret;
         if ($this->debug) $this->genlog ("agmt_cancel: Ret $ret");
         $isCompleted = TRUE;

         $agmt_info['v_addinfo'] = $v_addinfo;
      }
      else {   // assume fail
         $v_addinfo = '';
         $isCompleted = FALSE;
      }

      if ($isCompleted) {
         $update = "update $cf_agmttable set updatedAt=now(), endedAt=now(), status='cancel'";
         $update .= " where svcid='$svcid' and id='$id'";
         if ($this->debug) $this->genlog ("agmt_cancel: $update");
         $sql->Update($update);
         return ($sql->a_rows >= 1) ? $agmt_info : FALSE;
      }

      return FALSE;
   }


   /**
    *  Adds a new Agreement.
    *
    *  @return TRUE if added; FALSE otherwise.
    **/
   function action_agmtadd($txnid, $parmarr=NULL)
   {
      global $cf_agmttable;

      // Obtain parameters
      $txnid = intval($txnid);
      if (empty($parmarr) || !is_array($parmarr)) return FALSE;
      $partnerid = intval($parmarr['partnerid']);
      $akey = preg_match("%([a-z\d][_a-z\d]{1,19})%i", $parmarr['akey'], $matches) ? $matches[1] : '';
      $amtcurrency = !empty($parmarr['amtcurrency']) ? $parmarr['amtcurrency'] : $parmarr['v_currency'];
      $amtcurrency = preg_match("%([a-z]{3})%i", $amtcurrency, $matches) ? $matches[1] : 'USD';    // default USD
      $amtvalue = !empty($parmarr['amtvalue']) ? $parmarr['amtvalue'] : $parmarr['v_amount'];
      $amtvalue = floatval($amtvalue);  // assume float
      $minInterval = !empty($parmarr['minInterval']) ? intval($parmarr['minInterval']) : 7;
      $agmtid = preg_match("%([a-z\d][_a-z\d]{1,19})%i", $parmarr['agmtid'], $matches) ? $matches[1] : '';  // optional, AgreementID
      $svcid = preg_match("%([a-z][a-z_]{2,}[\d]+[a-z_]*)%i", $parmarr['svcid'], $matches) ? strtoupper($matches[1]) : '';
      $i_service = preg_match("%^([a-z][a-z_]{2,})%i", (!empty($parmarr['service']) ? $parmarr['service'] : $svcid), $matches) ? strtoupper($matches[1]) : '';
      $v_txid = preg_match("%([a-z\d][_a-z\d]{1,19})%i", $parmarr['v_txid'], $matches) ? $matches[1] : '';
      $v_status = preg_match("%([a-z\d][a-z\d\-_]{0,19})%i", $parmarr['v_status'], $matches) ? $matches[1] : '';
      if (empty($agmtid) || empty($txnid) || empty($svcid)) return FALSE;  // parms required

      // Check time
      $time = !empty($parmarr['timeAt']) ? strtotime($parmarr['timeAt']) : 0;
      if ($time <= 0) $time = time();  // invalid time
      $timeAt = date("Y-m-d H:i:s", $time);

      $isOK = $this->gen_checkID($txnid, $i_service, $timeAt);
      if (!$isOK) return FALSE;

      if (!empty($this->sql))
         $sql = $this->sql;
      else {
         $sql = connectdb('appsdb');
         $this->sql = $sql;
      }
      if (empty($sql) || empty($cf_agmttable)) return FALSE;

      $update = "replace into $cf_agmttable set addedAt='$timeAt', agmtid='$agmtid', startAt=adddate('$timeAt', interval 1 day), svcid='$svcid', partnerid='$partnerid'";
      $update .= ", amtcurrency='$amtcurrency', amtvalue='$amtvalue', minInterval='$minInterval'";
      $update .= ", isAgree='Y', starttxnid='$txnid', lasttxnid='$txnid', updatedAt=now()";
      $update .= ", v_status='".$sql->EscapeString($v_status)."', v_txid='".$sql->EscapeString($v_txid)."'";
      $update .= ", akey='$akey'";
      if ($this->debug) $this->genlog ("action_agmtadd: $update");
      $sql->Insert($update);
      $id = $sql->insert_id;
      $isOK = $sql->a_rows >= 1;

      if ($id) {
         // Override all prior agreements
         $update = "update $cf_agmttable set status='run', updatedAt=now() where svcid='$svcid' and partnerid='$partnerid'";
         $update .= " and id < $id and (status='pending' or status='start')";
         if ($this->debug) $this->genlog ("action_agmtadd: $update");
         $sql->Update($update);
      }

      return $isOK;
   }


   function action_agmtupdate($txnid, $parmarr=NULL)
   {
      global $cf_agmttable;

      // Obtain parameters
      $txnid = intval($txnid);
      if (empty($parmarr) || !is_array($parmarr)) return FALSE;
      $partnerid = intval($parmarr['partnerid']);
      $agmtid = preg_match("%([a-z\d][_a-z\d]{1,19})%i", $parmarr['agmtid'], $matches) ? $matches[1] : '';  // optional, AgreementID
      $svcid = preg_match("%([a-z][a-z_]{2,}[\d]+[a-z_]*)%i", $parmarr['svcid'], $matches) ? strtoupper($matches[1]) : '';
      $i_service = preg_match("%^([a-z][a-z_]{2,})%i", (!empty($parmarr['service']) ? $parmarr['service'] : $svcid), $matches) ? strtoupper($matches[1]) : '';
      $v_txid = preg_match("%([a-z\d][_a-z\d]{1,19})%i", $parmarr['v_txid'], $matches) ? $matches[1] : '';
      $v_status = preg_match("%([a-z\d][a-z\d\-_]{0,19})%i", $parmarr['v_status'], $matches) ? $matches[1] : '';
      $status = preg_match("%([a-z]+)%i", $parmarr['status'], $matches) ? strtolower($matches[1]) : '';
      if (empty($agmtid) || empty($txnid) || empty($svcid)) return FALSE;  // parms required

      // Check time
      $time = !empty($parmarr['timeAt']) ? strtotime($parmarr['timeAt']) : 0;
      if ($time <= 0) $time = time();  // invalid time
      $timeAt = date("Y-m-d H:i:s", $time);

      $isOK = $this->gen_checkID($txnid, $i_service, $timeAt);
      if (!$isOK) return FALSE;

      if (!empty($this->sql))
         $sql = $this->sql;
      else {
         $sql = connectdb('appsdb');
         $this->sql = $sql;
      }
      if (empty($sql) || empty($cf_agmttable)) return FALSE;

      $update = "update $cf_agmttable set updatedAt='$timeAt', lasttxnid='$txnid', status='$status'";
      if (strcmp($status,'start')==0) $update .= ", numCharge=numCharge+1";
      $update .= ", v_txid='".$sql->EscapeString($v_txid)."', v_status='".$sql->EscapeString($v_status)."'";
      $update .= " where agmtid='$agmtid' and svcid='$svcid'";
      if ($this->debug) $this->genlog ("action_agmtupdate: $update");
      $sql->Update($update);
      $isOK = $sql->a_rows >= 1;

      return $isOK;
   }


   /**
    *  Adds a new request to the pcnReq table.
    *
    *  @return reqid if successful; FALSE otherwise.
    **/
   function pcn_request($parmarr)
   {
      global $cf_pcnReqtable, $cf_pcntable;
      global $cf_pcnCfgtable;

      // Obtain parameters
      if (empty($parmarr) || !is_array($parmarr)) return FALSE;
      $cntr = preg_match('%([a-z]{2})%', strtolower($parmarr['cntr']), $matches) ? $matches[1] : '';
      $uidr = !empty($parmarr['uidr']) ? intval($parmarr['uidr']) : (($partnerid==1) ? 1 : 0);  // uid of reseller
      $partnerid = !empty($parmarr['partnerid']) ? intval($parmarr['partnerid']) : (($uidr==1) ? 1 : 0);
      $num = max(1, intval($parmarr['num']));
      $gmdvalue = !empty($parmarr['gmdvalue']) ? intval($parmarr['gmdvalue']) : 200;   // defaults to 200
      $txnid = intval($parmarr['txnid']);    // optional - used to check for duplicate Request
      $akey = preg_match("%([a-z\d][_a-z\d]{1,19})%i", $parmarr['akey'], $matches) ? $matches[1] : '';      // optional
      $pass = preg_match("%([a-z\d]{3,})%i", $parmarr['pass'], $matches) ? strtolower($matches[1]) : '';    // optional
      $fro = preg_match("%([a-z\d][_a-z\d]{1,19})%i", $parmarr['fro'], $matches) ? $matches[1] : '';
      $service = preg_match("%^([a-z][a-z_]{2,})%i", $parmarr['service'], $matches) ? strtoupper($matches[1]) : '';
      if (empty($service) || empty($cntr) || empty($partnerid)) return FALSE;    // parms required

      $time = time();
      $timeAt = date('Y-m-d H:i:s', $time);  // current time

      if (!empty($this->sql))
         $sql = $this->sql;
      else {
         $sql = connectdb('appsdb');
         $this->sql = $sql;
      }
      if (empty($sql) || empty($cf_pcnCfgtable) || empty($cf_pcnReqtable)) return FALSE;

      // obtain remote ipaddr
      $ra = !empty($GLOBALS['HTTP_REMOTE_ADDR_REAL']) ? $GLOBALS['HTTP_REMOTE_ADDR_REAL'] : (!empty($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '');
      $ipaddr = !empty($_SERVER['HTTP_ORIGINALHOST']) ? $_SERVER['HTTP_ORIGINALHOST'] : $ra;

      // Authenticate service
      $query = "select * from $cf_pcnCfgtable where service='$service' and (host='%' or '$ipaddr' like host) and (pass='' or pass='$pass')";
      $query .= " and (pa_allow='' or pa_allow='$partnerid') and (cntr_allow='' or cntr_allow='$cntr')";
      $query .= " and numlimit>=$num";
      $query .= " order by id desc limit 1";
      if ($this->debug) $this->genlog ("pcn_request: $query");
      $sql->QueryRow($query);

      $svcinfo = array();     // service configuration
      if (!empty($sql->data)) {     // authenticated
         foreach ($sql->data as $key=>$val)
            if (!is_numeric($key)) $svcinfo[$key] = $val;
      }
      else return FALSE;   // illegal service

      // Update service Cache
      if (empty($this->cache_pcnsvc[$service]) || !is_array($this->cache_pcnsvc[$service])) $this->cache_pcnsvc[$service] = array();
      $this->cache_pcnsvc[$service]['info'] = $svcinfo;
      $this->cache_pcnsvc[$service]['expires'] = $time + (!empty($svcinfo) ? 5*60 : 1*60);   // cached for several mins

      if (!empty($txnid)) {   // Check for duplicate Request
         $query = "select reqid from $cf_pcnReqtable where txnid='$txnid' and timeAt >= subdate(now(), interval 24 hour) limit 1";
         if ($this->debug) $this->genlog ("pcn_request: $query");
         $sql->QueryRow($query,'w');
         if (!empty($sql->data)) return FALSE;  // duplicate Request
      }

      $update = "replace into $cf_pcnReqtable set timeAt='$timeAt', expiresAt=adddate(now(), interval 7 day), service='$service', cntr='$cntr', partnerid='$partnerid'";
      $update .= ", uidr='$uidr', gmdvalue='$gmdvalue', num='$num'";
      if (!empty($txnid)) $update .= ", txnid='$txnid'";
      if (!empty($akey)) $update .= ", akey='$akey'";    // for audit purpose
      if (!empty($fro)) $update .= ", fro='$fro'";
      $update .= ", ipaddr='$ipaddr'";
      if ($this->debug) $this->genlog ("pcn_request: $update");
      $sql->Insert($update);
      $id = $sql->insert_id;
      $reqid = $this->gen_makeID ($id, $service, $timeAt, 'pcn');
      if ($reqid) {
         $update = "update $cf_pcnReqtable set reqid='$reqid' where id='$id'";
         if ($this->debug) $this->genlog ("pcn_request: $update");
         $sql->Update($update);
         $isOK = $sql->a_rows >= 1;
         if ($isOK) return $reqid;
      }

      return FALSE;
   }


   /**
    *  Upon a valid Request, issues PCNs into the pcntable.
    *
    *  @return result as an array; FALSE otherwise.
    **/
   function pcn_issue($reqid, $parmarr, $amode='')
   {
      global $cf_pcnReqtable, $cf_pcntable;
      global $cf_pcnExpireInterval;

      $pcnExpireInterval = max($cf_pcnExpireInterval, '1 month');

      // Obtain parameters
      $reqid = intval($reqid);
      if (empty($parmarr) || !is_array($parmarr)) return FALSE;
      $service = preg_match("%^([a-z][a-z_]{2,})%i", $parmarr['service'], $matches) ? strtoupper($matches[1]) : '';
      $cntr = preg_match('%([a-z]{2})%', strtolower($parmarr['cntr']), $matches) ? $matches[1] : '';
      if (empty($service) || empty($reqid) || empty($cntr)) return FALSE;  // parms required

      $time = time();
      $i_timeAt = date('Y-m-d H:i:s', $time);  // current time

      if (empty($amode)) {
         // Obtain PCN-service config
         $svcinfo = $this->pcn_svcinfo($service, $i_timeAt);
         if (empty($svcinfo)) return FALSE;

         // Authenticate Request...
         $reqarr = array('reqid'=>$reqid, 'service'=>$service, 'cntr'=>$cntr);
         $cgiurl = msg_val($svcinfo['cgireqcheck'], $reqarr);
         if (!empty($cgiurl)) {     // caller authentication
            if ($this->debug) $this->genlog ("pcn_issue: cgiurl=$cgiurl");
            $ret = trim(@file_get_contents($cgiurl));
            if (!preg_match('%^OK%im', $ret)) return FALSE;    // invalid Request
         }
         // else assume authentic
      }

      if (!empty($this->sql))
         $sql = $this->sql;
      else {
         $sql = connectdb('appsdb');
         $this->sql = $sql;
      }
      if (empty($sql) || empty($cf_pcnReqtable) || empty($cf_pcntable)) return FALSE;

      // Retrieve request...
      $query = "select * from $cf_pcnReqtable where status='pending' and reqid='$reqid' and cntr='$cntr' order by id desc limit 1";
      if ($this->debug) $this->genlog ("pcn_issue: $query");
      $reqarr = array();
      $sql->QueryRow($query,'w');
      if (!empty($sql->data)) {     // authenticated
         foreach ($sql->data as $key=>$val)
            if (!is_numeric($key)) $reqarr[$key] = $val;
      }
      if (empty($reqarr)) return FALSE;   // invalid Request

      // $reqarr contains cntr, partnerid, uidr, num, gmdvalue etc.
      extract($reqarr);   // into current symbol table
      if (empty($cntr) || empty($num)) return FALSE;    // parms required

      // Lock request for processing...
      $update = "update $cf_pcnReqtable set status='start' where id='$id' and status='pending' and reqid='$reqid'";
      if ($this->debug) $this->genlog ("pcn_issue: $update");
      $sql->Update($update);
      $isOK = $sql->a_rows >= 1;
      if (!$isOK) return array();

      $resultarr = array('reqid' => $reqid);

      $numIssue = 0;
      for ($i=0; $i<$num; $i++) {

         $update = "replace into $cf_pcntable set timeAt='$i_timeAt', expiresAt=adddate('$i_timeAt', interval $pcnExpireInterval), reqid='$reqid', cntr='$cntr', partnerid='$partnerid'";
         $update .= ", uidr='$uidr', gmdvalue='$gmdvalue'";
         if ($this->debug) $this->genlog ("pcn_issue: $update");
         $sql->Insert($update);
         $id = $sql->insert_id;
         if (!$id) return FALSE;

         $numTries = 0;
         while ($numTries < 9) {
            $pcnarr = array(
               'cntr' => $cntr,
               'partnerid' => $partnerid,
               'service' => $service,
               );
            $pcnid = $this->pcn_makeID ($id, $pcnarr);
            $pcn32 = $this->long2base32(substr($pcnid,-12));

            $numTries++;
            $query = "select count(*) as num from $cf_pcntable where pcn32='$pcn32'";
            if ($this->debug) $this->genlog ("pcn_issue: $query");
            $sql->QueryRow($query,'w');
            $isNew = empty($sql->data) || (intval($sql->data['num']) == 0);

            if ($isNew) {  // new PCN
               $update = "update $cf_pcntable set pcn='$pcnid', pcn32='$pcn32'";
               if (strcasecmp($amode,'express')==0) $update .= ", status='start'";
               $update .= " where id='$id'";
               if ($this->debug) $this->genlog ("pcn_issue: $update");
               $sql->Update($update);
               $numIssue += $sql->a_rows;
               break;   // exit while-loop
            }
         }  // while
      }  // for

      if ($numIssue==$num) {  // full request
         if (empty($amode)) {    // 'express' request already 'start'
            $update = "update $cf_pcntable set status='start' where reqid='$reqid' and status='pending'";
            if ($this->debug) $this->genlog ("pcn_issue: $update");
            $sql->Update($update);     // PCNs are now live
            $numIssue = $sql->a_rows;
         }

         if (empty($amode) || strcasecmp($amode,'express')==0) {
            // Update pcnReq table...
            $update = "update $cf_pcnReqtable set status='run' where reqid='$reqid' and status='start'";
            if ($this->debug) $this->genlog ("pcn_issue: $update");
            $sql->Update($update);
         }
      }

      // Auto-cleanup
      $update = "delete from $cf_pcnReqtable where status='pending' and expiresAt < subdate(now(), interval 1 month)";
      $sql->Update($update);

      if ($numIssue) {
         $resultarr['numIssue'] = $numIssue;
         if (!empty($amode) && $numIssue == 1) {
            $resultarr['pcn'] = $pcnid;
            $resultarr['pcn32'] = $pcn32;
            if (strcasecmp($amode,'express')!=0) $resultarr['status'] = 'pending';
         }
      }
      return $resultarr;
   }


   function pcn_svcinfo($service, $timeAt='')
   {
      global $cf_pcnCfgtable;

      // Obtain parameters
      $service = preg_match("%^([a-z][a-z_]{2,})%i", $service, $matches) ? strtoupper($matches[1]) : '';
      if (empty($service)) return FALSE;

      // $timeAt not used

      // Check service Cache
      $now = time();
      if (empty($this->cache_pcnsvc[$service]) || !is_array($this->cache_pcnsvc[$service])) $this->cache_pcnsvc[$service] = array();
      $svcinfo = $this->cache_pcnsvc[$service]['info'];
      if (is_array($svcinfo)) {
         if (!empty($this->cache_pcnsvc[$service]['expires']) && ($now < $this->cache_pcnsvc[$service]['expires'])) {  // cache valid
            if ($this->debug) $this->genlog ("pcn_svcinfo: [Cache] ".print_r($svcinfo, TRUE));
            return $svcinfo;
         }
      }

      if (!empty($this->sql))
         $sql = $this->sql;
      else {
         $sql = connectdb('appsdb');
         $this->sql = $sql;
      }
      if (empty($sql) || empty($cf_pcnCfgtable)) return FALSE;

      // retrieve service config
      $svcinfo = array();     // service configuration
      $query = "select * from $cf_pcnCfgtable where service='$service' order by id desc limit 1";
      if ($this->debug) $this->genlog ("pcn_svcinfo: $query");
      $sql->QueryRow($query);
      if (!empty($sql->data)) {     // authenticated
         foreach ($sql->data as $key=>$val)
            if (!is_numeric($key)) $svcinfo[$key] = $val;
      }

      // Update service Cache
      if (empty($this->cache_pcnsvc[$service]) || !is_array($this->cache_pcnsvc[$service])) $this->cache_pcnsvc[$service] = array();
      $this->cache_pcnsvc[$service]['info'] = $svcinfo;
      $this->cache_pcnsvc[$service]['expires'] = $now + (!empty($svcinfo) ? 5*60 : 1*60);    // cached for several minutes

      //if ($this->debug) $this->genlog ("pcn_svcinfo: ".print_r($svcinfo, TRUE));
      return $svcinfo;
   }


   /**
    *  Retrieves a Reseller's PCN inventory available from the pcntable.
    *
    *  @parm parmarr - search parameters
    *  @return result array if successful; NULL otherwise.
    **/
   function pcn_inventory($parmarr)
   {
      global $cf_pcntable;

      // Obtain search parameters
      if (empty($parmarr) || !is_array($parmarr)) return NULL;
      $cntr = preg_match('%([a-z]{2})%', strtolower($parmarr['cntr']), $matches) ? $matches[1] : '';
      $partnerid = intval($parmarr['partnerid']);  // optional
      $uidr = intval($parmarr['uidr']);   // uid of reseller
      $gmdvalue = !empty($parmarr['gmdvalue']) ? intval($parmarr['gmdvalue']) : 0;

      $time = time();
      $timeAt = date('Y-m-d H:i:s', $time);  // current time

      if (!empty($this->sql))
         $sql = $this->sql;
      else {
         $sql = connectdb('appsdb');
         $this->sql = $sql;
      }
      if (empty($sql) || empty($cf_pcntable)) return NULL;

      $query = "select uidr, cntr, gmdvalue, min(reqid) as minreqid, count(*) as num, sum(gmdvalue) as sumValue from $cf_pcntable where expiresAt>'$timeAt' and status='start'";
      if (!empty($cntr)) $query .= " and cntr='$cntr'";
      if (!empty($partnerid)) $query .= " and partnerid='$partnerid'";
      if (!empty($uidr)) $query .= " and uidr='$uidr'";
      if (!empty($gmdvalue)) $query .= " and gmdvalue='$gmdvalue'";
      $query .= " group by 1,2,3";
      if ($this->debug) $this->genlog ("pcn_inventory: $query");
      $resultarr = array();
      $sql->Query($query);
      $sql->result_push($resultarr);

      if ($this->debug) $this->genlog ("pcn_inventory: ".print_r($resultarr,TRUE));
      return $resultarr;
   }


   function pcn_summary($parmarr)
   {
      global $cf_pcntable;

      // Obtain search parameters
      if (empty($parmarr) || !is_array($parmarr)) return NULL;
      $cntr = preg_match('%([a-z]{2})%', strtolower($parmarr['cntr']), $matches) ? $matches[1] : '';
      $partnerid = intval($parmarr['partnerid']);  // optional
      $uidr = intval($parmarr['uidr']);   // uid of reseller
      $gmdvalue = !empty($parmarr['gmdvalue']) ? intval($parmarr['gmdvalue']) : 0;

      $time = time();
      $timeAt = date('Y-m-d H:i:s', $time);  // current time

      if (!empty($this->sql))
         $sql = $this->sql;
      else {
         $sql = connectdb('appsdb');
         $this->sql = $sql;
      }
      if (empty($sql) || empty($cf_pcntable)) return NULL;

      $query = "select left(timeAt,10) as dayAt, uidr, cntr, uid, gmdvalue, min(reqid) as minreqid, count(*) as num, sum(gmdvalue) as sumValue from $cf_pcntable where status='run'";
      if (!empty($cntr)) $query .= " and cntr='$cntr'";
      if (!empty($partnerid)) $query .= " and partnerid='$partnerid'";
      if (!empty($uidr)) $query .= " and uidr='$uidr'";
      if (!empty($uid)) $query .= " and uid='$uid'";
      if (!empty($gmdvalue)) $query .= " and gmdvalue='$gmdvalue'";
      $query .= " group by 1,2,3,4,5 order by dayAt desc";
      if ($this->debug) $this->genlog ("pcn_summary: $query");
      $resultarr = array();
      $sql->Query($query);
      $sql->result_push($resultarr);

      if ($this->debug) $this->genlog ("pcn_summary: ".print_r($resultarr,TRUE));
      return $resultarr;
   }


   /**
    *  Updates a 'pending' PCN status to 'start' after charging is done.
    *
    *  @return PCN info if successful; FALSE otherwise.
    **/
   function pcn_start($parmarr)
   {
      global $cf_pcntable;
      global $cf_pcnReqtable;

      // Obtain parameters
      if (empty($parmarr) || !is_array($parmarr)) return FALSE;
      $cntr = preg_match('%([a-z]{2})%', strtolower($parmarr['cntr']), $matches) ? $matches[1] : '';
      $pcnid = !empty($parmarr['pcnid']) ? strtolower(trim($parmarr['pcnid'])) : '';
      $gmdvalue = !empty($parmarr['gmdvalue']) ? intval($parmarr['gmdvalue']) : 200;   // defaults to 200
      $fro = preg_match("%([a-z\d][_a-z\d]{1,19})%i", $parmarr['fro'], $matches) ? $matches[1] : '';
      if (empty($pcnid) || empty($cntr)) return FALSE;   // parms required
      $txnid = intval($parmarr['txnid']);    // txnid of charging

      $time = time();
      $timeAt = date('Y-m-d H:i:s', $time);  // current time

      if (!empty($this->sql))
         $sql = $this->sql;
      else {
         $sql = connectdb('appsdb');
         $this->sql = $sql;
      }
      if (empty($sql) || empty($cf_pcntable)) return FALSE;

      // obtain remote ipaddr
      $ra = !empty($GLOBALS['HTTP_REMOTE_ADDR_REAL']) ? $GLOBALS['HTTP_REMOTE_ADDR_REAL'] : (!empty($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '');
      $ipaddr = !empty($_SERVER['HTTP_ORIGINALHOST']) ? $_SERVER['HTTP_ORIGINALHOST'] : $ra;

      // validate PCN
      $isOK = FALSE;
      if (strlen($pcnid)>=12) {    // pcn
         $pcn = $pcnid;
         $isOK = $this->pcn_checkID($pcn, array('cntr'=>$cntr));
      }
      else if (strlen($pcnid)==8) {   // pcn32
         $pcn = $this->base32_float($pcnid);
         $isOK = $this->pcn_checkID($pcn, array('cntr'=>$cntr));
      }
      if (!$isOK) return FALSE;  // invalid PCN

      $pcn32 = $this->long2base32(substr($pcn,-12));
      $query = "select * from $cf_pcntable where cntr='$cntr' and expiresAt>'$timeAt'";
      $query .= " and (status='start' or status='pending') and pcn32='$pcn32'";
      $query .= " and gmdvalue = '$gmdvalue' order by id limit 1";

      if ($this->debug) $this->genlog ("pcn_start: $query");
      $sql->QueryRow($query,'w');

      if (!empty($sql->data)) {
         $resultarr = array(
            'status' => $sql->data['status'],
            'cntr' => $sql->data['cntr'],
            'partnerid' => $sql->data['partnerid'],
            'pcn' => $sql->data['pcn'],
            'pcn32' => $sql->data['pcn32'],
            'gmdvalue' => $sql->data['gmdvalue'],
            'reqid' => $sql->data['reqid'],
            );

         if (strcasecmp($resultarr['status'],'start')==0)
            return $resultarr;   // already 'start'

         $update = "update $cf_pcntable set status='start', fro='$fro', ipaddr='$ipaddr'";
         $update .= " where status='pending' and pcn='".$resultarr['pcn']."' limit 1";
         if ($this->debug) $this->genlog ("pcn_start: $update");
         $sql->Update($update);

         if (empty($sql->errno)) {
            $resultarr['status'] = 'start';

            // Update pcnReq table...
            $update = "update $cf_pcnReqtable set status='run'";
            if (!empty($txnid)) $update .= ", txnid='$txnid'";
            $update .= " where status='start' and reqid='".$resultarr['reqid']."'";
            if ($this->debug) $this->genlog ("pcn_start: $update");
            $sql->Update($update);
            $isOK = $sql->a_rows >= 1;

            return $resultarr;   // OK
         }
      }

      return NULL;   // ERR
   }


   /**
    *  Aborts a PCN in pre-run status.
    *
    *  @return PCN info if successful; FALSE otherwise.
    **/
   function pcn_abort($parmarr, $astatus='')
   {
      global $cf_pcntable;
      global $cf_pcnReqtable;

      // Obtain parameters
      if (empty($parmarr) || !is_array($parmarr)) return FALSE;
      $cntr = preg_match('%([a-z]{2})%', strtolower($parmarr['cntr']), $matches) ? $matches[1] : '';
      $pcnid = !empty($parmarr['pcnid']) ? strtolower(trim($parmarr['pcnid'])) : '';
      $fro = preg_match("%([a-z\d][_a-z\d]{1,19})%i", $parmarr['fro'], $matches) ? $matches[1] : '';
      $astatus = preg_match("%([a-z][a-z_]{2,})%i", $astatus, $matches) ? strtolower($matches[1]) : 'cancel';
      if (empty($pcnid) || empty($cntr) || strcmp($astatus,'run')==0) return FALSE;    // parms required
      $txnid = intval($parmarr['txnid']);    // txnid of charging

      $time = time();
      $timeAt = date('Y-m-d H:i:s', $time);  // current time

      if (!empty($this->sql))
         $sql = $this->sql;
      else {
         $sql = connectdb('appsdb');
         $this->sql = $sql;
      }
      if (empty($sql) || empty($cf_pcntable)) return FALSE;

      // obtain remote ipaddr
      $ra = !empty($GLOBALS['HTTP_REMOTE_ADDR_REAL']) ? $GLOBALS['HTTP_REMOTE_ADDR_REAL'] : (!empty($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '');
      $ipaddr = !empty($_SERVER['HTTP_ORIGINALHOST']) ? $_SERVER['HTTP_ORIGINALHOST'] : $ra;

      // validate PCN
      $isOK = FALSE;
      if (strlen($pcnid)>=12) {    // pcn
         $pcn = $pcnid;
         $isOK = $this->pcn_checkID($pcn, array('cntr'=>$cntr));
      }
      else if (strlen($pcnid)==8) {   // pcn32
         $pcn = $this->base32_float($pcnid);
         $isOK = $this->pcn_checkID($pcn, array('cntr'=>$cntr));
      }
      if (!$isOK) return FALSE;  // invalid PCN

      $pcn32 = $this->long2base32(substr($pcn,-12));
      $query = "select * from $cf_pcntable where cntr='$cntr'";
      $query .= " and status!='run' and pcn32='$pcn32'";
      $query .= " order by id limit 1";

      if ($this->debug) $this->genlog ("pcn_abort: $query");
      $sql->QueryRow($query,'w');

      if (!empty($sql->data)) {
         $resultarr = array(
            'status' => $sql->data['status'],
            'cntr' => $sql->data['cntr'],
            'partnerid' => $sql->data['partnerid'],
            'pcn' => $sql->data['pcn'],
            'pcn32' => $sql->data['pcn32'],
            'gmdvalue' => $sql->data['gmdvalue'],
            'reqid' => $sql->data['reqid'],
            );

         if (in_array(strtolower($resultarr['status']), array('cancel','fail')))
            return $resultarr;   // already aborted

         $update = "update $cf_pcntable set status='$astatus', fro='$fro', ipaddr='$ipaddr'";
         $update .= " where status!='run' and pcn='".$resultarr['pcn']."' limit 1";
         if ($this->debug) $this->genlog ("pcn_abort: $update");
         $sql->Update($update);

         if (empty($sql->errno)) {
            $resultarr['status'] = $astatus;

            // Update pcnReq table...
            $update = "update $cf_pcnReqtable set status='$astatus'";
            if (!empty($txnid)) $update .= ", txnid='$txnid'";
            $update .= " where status!='run' and reqid='".$resultarr['reqid']."'";
            if ($this->debug) $this->genlog ("pcn_abort: $update");
            $sql->Update($update);
            $isOK = $sql->a_rows >= 1;

            return $resultarr;   // OK
         }
      }

      return NULL;   // ERR
   }


   /**
    *  Activates a PCN from the pcntable.
    *  Status is updated to 'run' before G$ is added.
    *
    *  @parm nmode - 0:requires valid PCN; 1:required matching uidr
    **/
   function pcn_run($parmarr, $nmode=0)
   {
      global $cf_pcntable;

      // Obtain parameters
      $nmode = intval($nmode);
      if (empty($parmarr) || !is_array($parmarr)) return FALSE;
      $cntr = preg_match('%([a-z]{2})%', strtolower($parmarr['cntr']), $matches) ? $matches[1] : '';
      $pcnid = !empty($parmarr['pcnid']) ? strtolower(trim($parmarr['pcnid'])) : '';
      $uidr = intval($parmarr['uidr']);   // uid of reseller
      $sidr = strtolower(trim($parmarr['sidr']));  // sid of reseller
      $uid = intval($parmarr['uid']);     // uid of buyer
      $num = intval($parmarr['num']);
      $gmdvalue = !empty($parmarr['gmdvalue']) ? intval($parmarr['gmdvalue']) : 200;   // defaults to 200
      $sumValue = !empty($parmarr['sumValue']) ? intval($parmarr['sumValue']) : (!empty($parmarr['sumvalue']) ? intval($parmarr['sumvalue']) : 0);
      $fro = preg_match("%([a-z\d][_a-z\d]{1,19})%i", $parmarr['fro'], $matches) ? $matches[1] : '';
      if ((empty($pcnid) && empty($uidr)) || empty($cntr) || empty($uid)) return FALSE;   // parms required

      $time = time();
      $timeAt = date('Y-m-d H:i:s', $time);  // current time

      if (!empty($this->sql))
         $sql = $this->sql;
      else {
         $sql = connectdb('appsdb');
         $this->sql = $sql;
      }
      if (empty($sql) || empty($cf_pcntable)) return FALSE;

      // obtain remote ipaddr
      $ra = !empty($GLOBALS['HTTP_REMOTE_ADDR_REAL']) ? $GLOBALS['HTTP_REMOTE_ADDR_REAL'] : (!empty($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '');
      $ipaddr = !empty($_SERVER['HTTP_ORIGINALHOST']) ? $_SERVER['HTTP_ORIGINALHOST'] : $ra;

      $query1 = "select * from $cf_pcntable where cntr='$cntr' and expiresAt>'$timeAt'";

      if ($nmode==0) {   // require valid PCN
         $isOK = FALSE;
         if (strlen($pcnid)>=12) {    // pcn
            $pcn = $pcnid;
            $isOK = $this->pcn_checkID($pcn, array('cntr'=>$cntr));
         }
         else if (strlen($pcnid)==8) {   // pcn32
            $pcn = $this->base32_float($pcnid);
            $isOK = $this->pcn_checkID($pcn, array('cntr'=>$cntr));
         }
         if (!$isOK) return FALSE;  // invalid PCN
         $pcn32 = $this->long2base32(substr($pcn,-12));
         $query1 .= " and status='start' and pcn32='$pcn32'";
         $num = 1;
      }

      else if ($nmode==1) {    // PCN not required, matching uidr
         $query1 .= " and status='start' and uidr='$uidr'";
      }

      $numRun = 0;
      $run_sumVal = 0;

      $qmode = $num > 0 ? 'fixed' : ($sumValue > 0 ? 'variable' : '');
      $toDo = !empty($qmode);
      while ($toDo) {
         $numlimit = ($num > 0) ? min(intval($num), 10) : 10;
         if ($qmode=='fixed')
            $query = $query1 . " and gmdvalue = '$gmdvalue' order by id limit $numlimit";
         else
            $query = $query1 . " and gmdvalue <= '$sumValue' order by gmdvalue desc, id limit $numlimit";
         if ($this->debug) $this->genlog ("pcn_run: $query");
         $resultarr = array();
         $sql->Query($query,'w');
         $sql->result_push($resultarr);
         if (empty($resultarr)) break;    // no more results

         $toDo = FALSE;    // prevent infinite loop
         foreach ($resultarr as $row) {
            // $row contains $cntr, $uidr, pcn, pcn32, gmdvalue
            if ($qmode=='variable') {
               if ($sumValue < $row['gmdvalue']) {
                   break;  // exit for-loop
               }
            }
            $update = "update $cf_pcntable set status='run', uid='$uid'";
            $update .= ", fro='$fro', ipaddr='$ipaddr'";
            $update .= " where status='start' and pcn='".$row['pcn']."' limit 1";
            if ($this->debug) $this->genlog ("pcn_run: $update");
            $sql->Update($update);

            if ($sql->a_rows >= 1) {   // lock
               $run_val = $this->call_gmd_debit ($uid, $row);
               if ($run_val) {
                  $run_sumVal += $run_val;
                  $numRun++;
               }

               $row['status'] = 'run';
               $row['uid'] = $uid;
               $row['ipaddr'] = $ipaddr;
               $this->pcn_log ($row, $timeAt);   // log action

               // reduce to prevent infinite loop
               if ($qmode=='fixed') {
                  $num -= 1;
                  $toDo = $num > 0;
                  if ($this->debug) $this->genlog ("pcn_run: qmode=fixed, remaining num=$num");
               }
               else {   // 'variable'
                  $sumValue -= $row['gmdvalue'];
                  $toDo = $sumValue > 0;
                  if ($this->debug) $this->genlog ("pcn_run: qmode=variable, remaining sumValue=$sumValue");
               }
               if (!$toDo) break;   // exit for-loop
            }
         }  // foreach
      }  // while

      return array(
         'numRun' => $numRun,
         'sumValue' => $run_sumVal,
         );
   }


   function pcn_log($parmarr, $timeAt='')
   {
      global $cf_pcnlogtable;

      // Check time
      $time = !empty($timeAt) ? strtotime($timeAt) : 0;
      if ($time <= 0) $time = time();  // invalid time
      $timeAt = date("Y-m-d H:i:s", $time);

      if (!empty($this->sql))
         $sql = $this->sql;
      else {
         $sql = connectdb('appsdb');
         $this->sql = $sql;
      }
      if (empty($sql) || empty($cf_pcnlogtable)) return FALSE;

      if (empty($cache_epay)) $cache_epay = array('isTableChecked'=>array());   // initialize Cache

      // Obtain tablename...
      $suffix = date("Ym", $time);
      $logtable = $cf_pcnlogtable.$suffix;

      $attribarr = array('status', 'partnerid', 'cntr', 'uidr', 'pcn', 'pcn32', 'gmdvalue', 'uid', 'reqid', 'ipaddr');

      $isNewtable = TRUE;  // assume new table
      while ($isNewtable) {
         $update = "insert into $logtable set timeAt='$timeAt'";
         foreach ($attribarr as $key) {
            if (!empty($parmarr[$key])) {
               $val = is_string($parmarr[$key]) ? $sql->EscapeString($parmarr[$key]) : $parmarr[$key];
               $update .= ", $key='$val'";
            }
         }
         if ($this->debug) $this->genlog ("pcn_log: $update");
         $sql->Insert($update);

         if ($isNewtable && $sql->errno) {   // MySQL Error: 1146 (ER_NO_SUCH_TABLE)
            if (empty($this->cache_epay['isTableChecked'][$logtable])) {    // not yet checked
               $this->checkTable($sql, $logtable, 'pcnlog');
               $this->cache_epay['isTableChecked'][$logtable] = TRUE;
               continue;   // $isNewtable still TRUE
            }
         }
         $isNewtable = FALSE;
      }
      return;
   }


   function pcn_resellerinfo($uidr, $parmarr)
   {
      global $cf_pcntable;

      // Obtain parameters
      $uidr = intval($uidr);
      if (empty($parmarr) || !is_array($parmarr)) return FALSE;
      $cntr = preg_match('%([a-z]{2})%', strtolower($parmarr['cntr']), $matches) ? $matches[1] : '';
      $sidr = strtolower(trim($parmarr['sidr']));  // sid of reseller
      if (empty($cntr) || empty($uidr) || ($uidr != 1 && empty($sidr))) return FALSE;  // parms required

      if (!empty($this->sql))
         $sql = $this->sql;
      else {
         $sql = connectdb('appsdb');
         $this->sql = $sql;
      }
      if (empty($sql) || empty($cf_pcntable)) return NULL;

      $query = "select cntr, status, min(reqid) as minreqid, count(*) as num, sum(gmdvalue) as sumValue from $cf_pcntable where uidr='$uidr'";
      if (!empty($cntr)) $query .= " and cntr='$cntr'";
      $query .= " group by 1,2";
      if ($this->debug) $this->genlog ("pcn_resellerinfo: $query");
      $resultarr = array();
      $sql->Query($query);
      $sql->result_push($resultarr);
      if (empty($resultarr)) return FALSE;

      $userinfo['summary'] = $resultarr;
      return $userinfo;
   }


   /**
    *  Add G$ ($parmarr['gmdvalue']) to specified user.
    *
    **/
   function call_gmd_debit($i_uid, $parmarr, $type='debit')
   {
      global $cf_cgipoints;

      // avoid similar-named variables in $parmarr
      $i_uid = intval($i_uid);
      $isCredit = strcasecmp($type,'debit')!=0;

      // $parmarr contains num, srctype
      extract($parmarr);   // into current symbol table
      $gmdvalue = abs(intval($gmdvalue));
      $cntr = preg_match('%([a-z]{2})%', strtolower($parmarr['cntr']), $matches) ? $matches[1] : '';
      if ($isCredit) $gmdvalue = -$gmdvalue;    // 'credit' is auto-negative
      $srctype = !empty($srctype) ? strtolower(trim($srctype)) : 'epay';

      if (!$i_uid || empty($cf_cgipoints)) return FALSE;

      $uidr = intval($uidr);
      $partnerid = intval($partnerid);
      if (!empty($uidr))
         $srctype .= ':'.$uidr;  // embed reseller uid
      else
         $srctype .= ':'.$partnerid;   // embed partnerid
      $cgiurl = msg_val($cf_cgipoints, array('uid'=>$i_uid, 'diff'=>$gmdvalue, 'srctype'=>$srctype));
      if ($this->debug) $this->genlog ("call_gmd_$type: $cgiurl");
      $ret = @file_get_contents ($cgiurl);
      $ret = $ret ? trim($ret) : '';
      $isOK = preg_match("%^[1]$%im", $ret);
      $points = preg_match('%^([\d]+)%', $ret, $matches) ? intval($matches[1]) : 0;
      if ($this->debug) $this->genlog ("call_gmd_$type: isOK=".($isOK ? 'TRUE' : 'FALSE').", $points");
      return ($isOK ? $gmdvalue : 0);
   }

   /**
    *  Deduct G$ ($parmarr['gmdvalue']) from specified user.
    *
    **/
   function call_gmd_credit($i_uid, $parmarr, $type='credit')
   {
      return call_gmd_debit($i_uid, $parmarr, $type);
   }

   /**
    *  Obtain most recent transaction details, if any, for specified acct.
    *
    **/
   function acct_peek ($acctid)
   {
      global $cf_vendorTxntable;

      if (!empty($acctid) && preg_match("%^([\d]+)[-]([\d]{3})%", $acctid, $matches)) {
         $acctid = trim($acctid);
         $itemcode_suffix = $matches[1].'-'.(intval($matches[2]) ? '1':'0');
         $upid = intval($matches[1]);
         $intcntr = intval($matches[2]);  // 0 if partner
      }
      else return NULL;

      if (!empty($this->sql))
         $sql = $this->sql;
      else {
         $sql = connectdb('appsdb');
         $this->sql = $sql;
      }
      if (empty($sql) || empty($cf_vendorTxntable)) return NULL;

      // Find in vendorTxn table...
      $query = "select * from $cf_vendorTxntable where itemcode like '%".$itemcode_suffix."' order by updatedAt desc limit 1";
      $acctinfo = array();
      $sql->QueryRow($query);
      if (!empty($sql->data)) {
         $ainfo = array();
         foreach ($sql->data as $key=>$val)
            if (!is_numeric($key)) $ainfo[$key] = $val;

         $acctinfo = array(
            'acctid' => $acctid,
            'v_payer' => $ainfo['v_payer'],
            'v_payername' => $ainfo['v_payername'],
            'v_country' => $ainfo['v_country'],
            'v_status' => $ainfo['v_status'],
            'updatedAt' => $ainfo['updatedAt'],
            );
      }

      return $acctinfo;
   }


   /**
    *  Makes an acct-ID from the specified xid (uid or partnerid) and optional cntr.
    *  Max: 99999999-99999
    **/
   function acct_makeID ($xid, $cntr='')
   {
      $xid = intval($xid);
      $cntr = preg_match('%([a-z]{2})%', strtolower($cntr), $matches) ? $matches[1] : '';
      if (!$xid) return NULL;

      $s1 = sprintf("%08u", $xid);
      $s2 = sprintf("%03u", $this->cntr2int($cntr));  // digits [000,999]
      $hash = md5('epay'.$xid.$cntr);
      $s3 = sprintf("%02u", 10+(hexdec(substr($hash,-6)) % 90));  // digits [10,99]
      return $s1.'-'.$s2.$s3;
   }


   /**
    *  Partner: 001234133-00000118-0
    *     User: 001235443-00000118-1
    **/
   function acct_itemcode($acctid, $txnid)
   {
      if (empty($txnid)) return '';
      $itemcode = sprintf("%09u",intval($txnid));

      if (empty($acctid)) return $itemcode;
      if (preg_match("%^([\d]+)[-]([\d]{3})%", $acctid, $matches))
         $itemcode .= '-'.$matches[1].'-'.(intval($matches[2]) ? '1':'0');
      return $itemcode;
   }


   /**
    *  Makes a generic-ID from the specified parameters.
    *  Max: 2000000-999
    **/
   function gen_makeID($id, $service='', $timeAt='', $atype='')
   {
      // Obtain parameters
      $id = intval($id);
      if (!$id) return FALSE;    // cannot make genid using invalid ID
      if ($id > 2000000) $id = ($id-1) % 2000000 + 1;
      $service = preg_match("%^([a-z][a-z_]{2,})%i", $service, $matches) ? strtoupper($matches[1]) : '';
      $atype = preg_match("%^([a-z][a-z_\d]+)%i", $atype, $matches) ? strtolower($matches[1]) : '';

      $hash = md5('epay'.$id.$service.$atype);
      $s1 = sprintf("%02u", 10+(hexdec(substr($hash,-6)) % 90));  // digits [10,99]
      if (!empty($timeAt) || $timeAt=='') {
         // Check time
         $time = strlen($timeAt) ? strtotime($timeAt) : time();
         if ($time <= 0) $time = time();  // invalid time
         $h2 = md5(date('Y-W',$time));    // Week in Year
         $s2 = sprintf("%01u", 1+(hexdec(substr($h2,-6)) % 9));   // digit [1,9]
      }
      else {
         $s2 = '0';
      }
      return $id.$s1.$s2;
   }


   /**
    *  Checks if generic-ID is valid.
    *
    *  @return row_id if valid; FALSE otherwise.
    **/
   function gen_checkID($genid, $service='', $timeAt='', $atype='')
   {
      if (empty($genid) || !preg_match('%^([\d]+)([\d]{2})([\d])$%', $genid, $matches))
         return FALSE;
      if (empty($service)) return FALSE;

      $id = intval($matches[1]);
      $s1 = $matches[2];
      $s2 = $matches[3];

      if (!empty($timeAt) || $timeAt=='') {
         // Check time
         $time = strlen($timeAt) ? strtotime($timeAt) : time();
         if ($time <= 0) $time = time();  // invalid time
         $numTry = 0;
         while ($numTry < 2) {
            $timeAt = date('Y-m-d H:i:s', $time - 168*3600*$numTry);
            if ($genid == $this->gen_makeID($id, $service, $timeAt, $atype))  // match
               return $id;
            $numTry++;
         }
      }
      else {
         if ($genid == $this->gen_makeID($id, $service, 0))   // match
            return $id;
      }
      return FALSE;
   }


   /**
    *  Makes a secure pcn-ID from the specified parameters.
    *  Max: 9999-9999-9999-9999
    **/
   function pcn_makeID($id, $parmarr, $ntype=1)
   {
      // Obtain parameters
      $id = $this->int_range($id, 1, 9999);
      if (!$id || empty($parmarr) || !is_array($parmarr)) return FALSE;    // required parameters

      $partnerid = intval($parmarr['partnerid']);
      $service = trim($parmarr['service']);  // optional
      $ntype = intval($ntype);
      if ($ntype < 1 || $ntype > 9) $ntype = 1;    // digits [1,9]
      $cntr = preg_match('%([a-z]{2})%', strtolower($parmarr['cntr']), $matches) ? $matches[1] : '';

      $nrandom = !empty($parmarr['nrandom']) ? intval($parmarr['nrandom']) : 0;
      $nrandom = ($nrandom <= 0) ? mt_rand(10,99) : $this->int_range($nrandom, 10, 99);   // integer [10,99]
      $hash = md5('epay'.$ntype.$cntr.$nrandom.$id);

      if ($partnerid==1 && !empty($service))
         $s1 = sprintf("%04u", 9000 + $this->int_range(crc32($service), 1, 9));  // digits [9001,9009]
      else
         $s1 = sprintf("%04u", $this->int_range($partnerid, 1000, 9999));  // digits [1000,9999]
      $s2 = $ntype . sprintf("%03u", $this->cntr2int($cntr));  // digits [1000,9999]
      $s3 = sprintf("%02u", 10+(hexdec(substr($hash,-6)) % 90)) . $nrandom;   // digits [1010,9999]
      $s4 = sprintf("%04u", $id);   // digits [0001,9999]

      return $s1.$s2.$s3.$s4;
   }


   /**
    *  Checks if pcn-ID is valid.
    *
    *  @return row_id if valid; FALSE otherwise.
    **/
   function pcn_checkID($pcnid, $parmarr)
   {
      $pcnid = strtolower(trim($pcnid));
      if (empty($pcnid) || empty($parmarr) || !is_array($parmarr)) return FALSE;

      // backward-compatible
      if (preg_match('%([\d]{4})([0][\d]{3})([1][\d]{3})$%', $pcnid, $matches))
         $pcnid = $matches[3].$matches[1].$matches[2];

      if (preg_match('%^([\d]{0,4})([\d]{4})(?:[\d]{2})([\d]{2})([\d]{4})$%', $pcnid, $matches)) {
         $s1 = $matches[1];   // for ntype==1, this can be blank
         $s2 = $matches[2];
         $ntype = intval($s2{0});
         $parmarr['nrandom'] = $matches[3];
         $id = $matches[4];
      }
      else return FALSE;

      $pcnid_full = $this->pcn_makeID($id, $parmarr, $ntype);
      if (preg_match("%".substr($pcnid,-12)."$%", $pcnid_full))
         return $id;

      return FALSE;
   }


   function int_range($num, $limit_lo, $limit_hi)
   {
      $mod = (intval($num) - $limit_lo) % ($limit_hi - $limit_lo + 1);
      while ($mod < 0) $mod += ($limit_hi - $limit_lo + 1);
      return intval($limit_lo + $mod);
   }


   function cntr2int($cntr)
   {
      $cntr = preg_match('%([a-z]{2})%', strtolower($cntr), $matches) ? $matches[1] : '';
      $num = !empty($cntr) ? 32 * (ord($cntr{0})-96) + (ord($cntr{1})-96) : 0;
      return $num;
   }


   function base32_float($s)
   {
      global $base_charset;

      $s = strtolower(trim($s));
      $pattern = array('%[il]%', '%[o]%');
      $replacement = array('1', '0');
      $s = preg_replace($pattern, $replacement, $s);

      $num = 0.00;
      while (strlen($s) > 0) {
         $n32 = intval(strpos($base_charset[32], $s{0}));   // treat unfound as 0
         $num = $num * 32 + $n32;
         $s = substr($s,1);
      }
      return round($num);
   }


   /**
    *  Convert string representing integer (base10) to base32.
    *
    **/
   function long2base32($long, $pad=0)
   {
      global $base_charset;

      $num = floatval($long);
      if ($num < 1) return $base_charset[32][0];
      if (empty($pad)) $pad = 1;

      $s_out = '';
      while ($num >= 1) {
         $n32 = intval($num % 32);
         $num = floor(($num - $n32) / 32);
         $s_out = $base_charset[32][$n32].$s_out;
      }
      while (strlen($s_out)<$pad) $s_out = $base_charset[32][0].$s_out;

      return $s_out;
   }


   function checkTable($sql, $tablename, $type='')
   {
      if (empty($sql) || empty($tablename)) return FALSE;

      if ($this->debug) $this->genlog ("checkTable: $tablename");
      $isExist = $sql->table_exists($tablename);
      if (!$isExist)
         $this->createTable ($sql, $tablename, $type);
      return TRUE;
   }


   function createTable($sql, $tablename, $type='')
   {
      if (empty($sql) || empty($tablename)) return FALSE;

      if ($type=='log')    // log table
         $update = "CREATE TABLE $tablename (id int NOT NULL auto_increment primary key, timeAt datetime NOT NULL, txnid bigint NOT NULL, status enum('run','cancel','fail') NOT NULL default 'run', cntr char(4) NOT NULL, svclid int NOT NULL, svcid char(20) NOT NULL, akey char(20) NOT NULL, amtcurrency char(3) NOT NULL, amtvalue decimal(10,2) NOT NULL, acctid char(20) NOT NULL, agmtid char(20) NOT NULL, action enum('','buy','sell') NOT NULL, v_txid char(20) NOT NULL, v_addinfo char(160) NOT NULL, KEY timeAt (timeAt), UNIQUE KEY txnid (txnid), KEY v_txid (v_txid))";
      else if ($type=='pcnlog')  // pcnlog table
         $update = "CREATE TABLE $tablename (id int NOT NULL auto_increment primary key, timeAt datetime NOT NULL, status enum('run','cancel','fail') NOT NULL default 'run', partnerid int NOT NULL, cntr char(4) NOT NULL, uidr int NOT NULL, pcn bigint NOT NULL, pcn32 char(12) NOT NULL, gmdvalue int NOT NULL, uid int NOT NULL, reqid int NOT NULL, ipaddr char(15) NOT NULL, KEY timeAt (timeAt), UNIQUE KEY pcn32 (pcn32), KEY uidr (uidr), KEY uid (uid))";
      else
         return FALSE;  // ERROR

      //if ($this->debug) $this->genlog ("createTable: $update");
      $sql->Update($update);
      if ($sql->result == FALSE) return FALSE;  // ERROR

      // Initialize with a blank record
      $timeAt = substr($tablename,-6,4)."-".substr($tablename,-2)."-01";
      $id = intval(substr($tablename,-2))*10000000;
      $update = "insert into $tablename set id=$id, timeAt='$timeAt'";

      $sql->Insert($update);
   }


   function closedb()
   {
      if (!empty($this->sql)) {
         closedb($this->sql);
         $this->sql = NULL;
      }
   }


   function setlog($logfile)
   {
      if (!empty($logfile)) $this->logfile = $logfile;
   }


   function genlog($msg)
   {
      $ts = time();
      $w = ((date("W",$ts)-1) % 4) + 1;
      if (!empty($this->script_name)) {
         $fn = "logs/".$this->script_name."_w$w.log";
         $fs = @stat($fn);
         if (!empty($fs) && ($ts - $fs['mtime']) >= (6 * 86400))  // more than 6 days
            unlink($fn);

         $fp = @fopen($fn,'a');
         @fputs($fp, "[".date("d/M/Y H:i:s T")."] ".$msg."\n");
         @fclose($fp);
      }
   }

}  // class EpayClient

?>

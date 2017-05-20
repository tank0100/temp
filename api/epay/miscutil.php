<?
/*
 *  miscutil.php - Miscellaneous Utilities.
 *  v1.11.2010052501
 *
 *  Change History
 *  ~~~~~~~~~~~~~~
 *  V1.0  CC:  Release version (2004-09-06).
 *  V1.1  CC:  Updated isValidName to allow digit as first character (2005-12-02).
 *  V1.2  CC:  Added WAP-friendly location_redirect function (2006-01-24).
 *  V1.3  CC:  Added pmid_make, pmid_check functions (2006-02-27).
 *  V1.4  CC:  Added obj_gserialize, obj_gunserialize functions (2006-10-13).
 *  V1.5  CC:  Added uri_extract function (2006-10-26).
 *  V1.6  CC:  Adopted urlencode-neutral Base64-variant in obj_gserialize (2006-10-27).
 *  V1.7  CC:  Added uid_makeID, uid_checkID for uid applications (2007-04-23).
 *  V1.8  CC:  Improved makeRP, checkRP to secure obj parameter (2007-05-11).
 *  V1.9  CC:  Added long2base32, base32_float, n_makeID, n_checkID functions (2007-06-28).
 *  V1.10 CC:  Added ipextract function for handling HTTP_X_FORWARDED_FOR (2008-07-14).
 *  V1.11 ET:  Added HTTP/1.1 301 Moved Permanently (2010-05-25).
 */

$GLOBALS['base_charset'][64] = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';
$GLOBALS['base_charset'][32] = '0123456789abcdefghjkmnpqrstvwxyz';

function Misc_Util() {
   // Dummy presence indicator
}


function isValidNick($gnick)
{
   global $cf_reservedpattern;

   $reservedpattern = $cf_reservedpattern ? $cf_reservedpattern : '[_a-z]{0,1}([\d]+)';

   // check for reserved patterns
   if (strlen($gnick)<=2) return FALSE;  // too short

   if (preg_match("%^".$reservedpattern."$%i", $gnick, $matcharr))
      return FALSE;   // not permitted

   return TRUE;
}


/**
 *  A valid name consists of at most 24 alphanumeric characters.
 *
 *  It must also not use any reserved patterns, which might be generated
 *  automatically.
 **/
function isValidName ($name)
{
   global $cf_reservedpattern;

   $reservedpattern = $cf_reservedpattern ? $cf_reservedpattern : '[_a-z]{0,1}([\d]+)';

   if (preg_match("%^".$reservedpattern."$%i", $name, $matches))
      return FALSE;  // reserved - not permitted

   $pattern = '[a-z\d][_a-z\d]{2,23}';
   if (preg_match('%^'.$pattern.'$%i', $name))
      if (!preg_match("%^(api|com|biz|def|gov|net|org|wap|web|www|gamma|mobile|mygamma|buzzcity|netbeacon)[\d]*$%i", $name))
         return TRUE;

   return FALSE;  // not permitted
}


/**
 *  Intelligently determines most likely country the specified $mid
 *  belongs to, given the user friend's cntr ($usercntr).
 *
 *  This function will use the mobile id functions if available,
 *  or the generic validity check otherwise.
 *
 *  @return as an array the cntr, opercode(if any) and normalized mid;
 *     if mid may be valid but a match not found, $usercntr is assumed;
 *     if mid is invalid, NULL is returned.
 **/
function mid_imatch($mid, $usercntr='')
{
   $usercntr = strtolower(trim($usercntr));

   if (function_exists('Mid_Util')) {     // mobile id functions available
      $midinfo = mid_match($mid, $usercntr);
      if (!empty($midinfo))    // isFound, but may be unknown
         return $midinfo;
      else   // invalid mid
         return NULL;
   }

   // intellgent mobile id functions offline - use generic one
   if ($mid = getValidMid($mid)) {
      $midinfo = array(
         'mid' => $mid,
         'cntr' => $usercntr,
      );
      return $midinfo;
   }
}


/**
 *  Returns the valid mid; FALSE otherwise.
 *
 **/
function getValidMid($mid)
{
   //$def_prefix = "[1-9]\d{0,2}";    // up to 3-digit international-prefix
   $def_prefix = "1|2(?:[07]|[1-9]\d)|3(?:[0-469]|[578]\d)|4(?:[013-9]|2\d)|5(?:[09]\d|[1-8])|6(?:[0-6]|[7-9]\d)|7|8(?:[057]\d|[1246]|80|81|821\d)|9(?:[0-58]|[679]\d)";
   $def_format = "0{0,1}[1-9]\d{6,14}";   // up to 16 digits

   $pattern = "(?:\+{0,1}".$def_prefix."){0,1}(".$def_format.")";
   $mid = preg_replace("%[-, ]%", "", trim($mid));   // replace typical number separators

   if (!$mid) return NULL;    // simple shortcut

   if (preg_match('%^'.$pattern.'$%', $mid, $matcharr)) {
      $mid = $matcharr[1];
      return $mid;
   }
   return FALSE;
}


/**
 *  Performs case-insensitive attribute replacements using $args array.
 *
 **/
function msg_val($msg, $args='')
{
   global $cf_debug;

   if (!is_array($args)) {
      $rs = str_replace("%1", $args, $msg);
   }
   else {
      $rs = $msg;

      if (preg_match_all("%\<[$]([a-z][a-z\d\_]*)\>%i", $rs, $matcharr)) {  // text parms
         $matcharr_done = array();
         foreach ($matcharr[1] as $attrib) {
            if (!in_array($attrib, $matcharr_done)) {
               $matcharr_done[] = $attrib;
               $value = isset($args[$attrib]) ? $args[$attrib] : '';
               //if ($cf_debug) echo ("msg_val: <$".$attrib."> -> $value\n");
               $rs = str_replace('<$'.$attrib.'>', $value, $rs);
            }
         }
      }
      else if (preg_match_all("/[%]([\d]+)/", $rs, $matcharr)) {  // position-indexed parms
         $matcharr_done = array();
         foreach ($matcharr[1] as $match) {
            $attrib = intval($match - 1);
            if (!in_array($attrib, $matcharr_done)) {
               $matcharr_done[] = $attrib;
               $value = isset($args[$attrib]) ? $args[$attrib] : '';
               //if ($cf_debug) echo ("msg_val: %$attrib -> $value\n");
               $rs = str_replace("%".$attrib, $value, $rs);
            }
         }
      }
   }
   return $rs;
}


function base64_urlencode($str, $isLegacy=0)
{
   $s = base64_encode($str);
   if (!$isLegacy) {
      $pattern = array('%\+%', '%\/%', '%\=%');
      $replacement = array('-', '_', '.');
      $s = preg_replace($pattern, $replacement, $s);
   }
   return $s;
}


function base64_urldecode($str)
{
   $pattern = array('%\-%', '%\_%', '%\.%');
   $replacement = array('+', '/', '=');
   $s = base64_decode(preg_replace($pattern, $replacement, $str));
   return $s;
}


function obj_gserialize($obj, $isLegacy=0)
{
   $s = base64_urlencode(@gzcompress(@serialize($obj),9), $isLegacy);
   return 'g'.$s;
}


function obj_gunserialize($str)
{
   $arr = NULL;
   if (!empty($str) && is_string($str) && strlen($str) > 0) {
      if (substr($str,0,1) == 'g')
         $s = @gzuncompress(base64_urldecode(substr($str, 1)));
      else
         $s = base64_urldecode($str);
      $arr = @unserialize($s);
      return $arr;
   }
   return $arr;
}


function makeRP ($modname='', $time=0, $obj=NULL, $oldRP='')
{
   $modname = preg_match("%([a-z]+)%", strtolower($modname), $matches) ? $matches[1] : 'foo';
   $crc32 = !empty($obj) ? crc32String(is_string($obj) ? $obj : @serialize($obj)) : '00000000';
   if (preg_match("%([a-z]+)(\d)(\d{7})(\d+)$%", $oldRP, $matches)) {
      $isCheck = TRUE;
      $modname = $matches[1];
      $rn = $matches[2];
      $tc = $matches[3];
      $scheck = $matches[4];
   }
   else {
      $rn = dechex(mt_rand(1,9));
      $t = intval($time); if (!$t) $t = time();
      $tc = floor($t/60) - 3154560;
      if ($tc < 0) return '';    // from 1976
      while ($tc > 6311520) $tc -= 6311520;
   }
   $s1 = sprintf("%06x", $tc);   // < 7FFFFF
   $hash = md5("c$r".$modname.$crc32);
   $s2 = sprintf("%02u", hexdec(substr($hash,-6)) % 90 + 10);  // digits [10,99]
   $s3 = empty($obj) ? '0' : sprintf("%01u", hexdec(substr($crc32,-4)) % 9 + 1);
   if ($isCheck && strcmp($s2.$s3, $scheck) != 0) return '';
   for ($i=0, $so=''; $i<6; $i++) {
      $h = ($i == 0) ? ($hash{$i} & 7) : $hash{$i};
      $so .= dechex(hexdec($s1{$i}) ^ hexdec($h));
   }
   if (!$isCheck)
      $rp = $modname.$rn.sprintf("%07u",hexdec($so)).$s2.$s3;
   else
      $rp = $modname.$rn.sprintf("%07u",hexdec($so)+3154560).$s2.$s3.".$crc32";
   return $rp;
}


function checkRP ($rp, $time=0, $obj=NULL)
{
   if (preg_match("%([a-z]+)(\d)(\d{7})(\d+)$%", $rp, $matches)) {
      if (!$time) $time = time();
      $checkRP = makeRP('', $time, $obj, $rp);
      if (!empty($checkRP) && preg_match("%([a-z]+)(\d)(\d{7})(\d+)([.][a-z\d]{8}){0,1}$%", $checkRP, $matches)) {
         $modname = $matches[1];
         $tc = $matches[3];
         $crc32 = substr($matches[5],-8);
         for ($numTry=0; $numTry<6; $numTry++) {
            $t = $tc * 60;
            if (abs($t - $time) < 3600)
               return array('modname'=>$modname, 'timeAt'=>date("Y-m-d H:i", $t), 'crc32'=>$crc32);
            $tc += 6311520;
         }
      }
   }
   return FALSE;
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


function cntr2id($cntr)
{
   global $base_charset;

   $base = 64;
   $cntr = strtolower(trim($cntr));
   $val = 0;
   if (preg_match("%^([a-z\d]{2})%", $cntr, $matches)) {
      $str = $matches[1];
      $len = strlen($str);

      $val = 0;
      for ($i=0; $i<$len; $i++) {
         $p = strpos($base_charset[$base], $str[$i]);  // treat unfound as 0
         $val = $val * 64 + $p;
      }
   }
   return sprintf("%04u", $val);
}


function id2cntr ($val)
{
   global $base_charset;

   $base = 64;
   $val = intval($val);
   $str = '';
   while ($val > 0) {
      $p = $val % 64;
      $str = $base_charset[$base]{$p} . $str;
      $val = ($val - $p) >> 6;
   }
   return $str;
}


/**
 *  Makes a key-ID from the specified parameters.
 *
 *  @parm parms containing optional service and random values
 *
 *  @return numeric string which can be converted to a base32 code
 *
 *    ntype=0
 *    pattern:   0-R-999999-HH
 *    features:  id range [0,999999]
 *    output:    9-digit integer
 *    base32:    6-char alphanumeric
 *    usage:     simple PIN
 *
 *    ntype=1
 *    pattern:   1-R-99999-T-HH
 *    features:  id range [0,99999] with day-check
 *    output:    10-digit integer
 *    base32:    7-char alphanumeric
 *    usage:     simple day Pass
 *
 *    ntype=2
 *    pattern:   2-R-9999999-HH
 *    features:  id range [0,9999999]
 *    output:    11-digit bigint or string
 *    base32:    7-char alphanumeric
 *    usage:     medium PIN
 *
 *    ntype=3
 *    pattern:   3-R-9999999-T-HH
 *    features:  id range [0,9999999] with day-check
 *    output:    12-digit bigint or string
 *    base32:    8-char alphanumeric
 *    usage:     txnid (id can include cntr info)
 **/
function n_makeID($id, $parms=NULL, $ntype=0)
{
   // Obtain parameters
   $id = intval($id);
   if ($id <= 0) return FALSE;   // cannot make using invalid ID

   $ntype = intval($ntype);
   if ($ntype==0) {
      $id = ($id - 1) % 999999 + 1;
      $s1 = sprintf("%06u", $id);
   }
   else if ($ntype==1) {
      $id = ($id - 1) % 99999 + 1;
      $s1 = sprintf("%05u", $id);
   }
   else if ($ntype==2 || $ntype==3) {
      $id = ($id - 1) % 9999999 + 1;
      $s1 = sprintf("%07u", $id);
   }
   else return FALSE;   // unsupported ntype

   if (is_array($parms)) {
      $service = trim($parms['service']);
      $rn = intval($parms['rn']);
      $timeAt = trim($parms['timeAt']);
   }
   else if (is_string($parms))
      $service = $parms;
   $service = preg_match("%^([a-z][a-z_]{2,})%i", $service, $matches) ? strtoupper($matches[1]) : '';
   $rn = (empty($rn) || $rn <= 0) ? mt_rand(1,9) : ($rn - 1) % 9 + 1;   // digits [1,9]

   if ($ntype % 2 == 0) {
      $hash = md5('n'.$service.$ntype.$rn.$id);
      $s2 = sprintf("%02u", hexdec(substr($hash,-6)) % 99 + 1);   // digits [01,99]
   }
   else {   // day-check
      if ($timeAt==='0') {
         $dayt = '';
         $t = '0';
      }
      else {
         $time = strlen($timeAt) ? strtotime($timeAt) : time();
         if ($time <= 0) $time = time();  // invalid time
         $dayt = floor(strtotime(date('Y-m-d', $time)) / 86400);
         $t = sprintf("%01u", $dayt % 9 + 1);   // digit [1,9]
      }

      $hash = md5('n'.$service.$ntype.$rn.$id.$dayt);
      $s2 = $t.sprintf("%02u", hexdec(substr($hash,-6)) % 99 + 1);   // digits [01,99]
   }
   $sout = $ntype.$rn.$s1.$s2;

   return $sout;
}


/**
 *  Checks if key-ID is valid.  Useful for reducing actual database checks.
 *  IMPT:  return value may not be the original row_id
 *
 *  @return a non-zero integer if valid; FALSE otherwise.
 **/
function n_checkID($genid, $parms=NULL)
{
   if (empty($genid)) return FALSE;
   if (preg_match('%^([a-z\d]{6,8})$%i', $genid, $matches))   // base32 code
      $genid = base32_float($matches);

   if (is_array($parms)) {
      $service = trim($parms['service']);
      $timeAt = trim($parms['timeAt']);
   }
   else if (is_string($parms))
      $service = $parms;
   $service = preg_match("%^([a-z][a-z_]{2,})%i", $service, $matches) ? strtoupper($matches[1]) : '';

   if (preg_match('%^([0]{0,1})([\d])([\d]{6})([\d]{2})$%', $genid, $matcharr)
      || preg_match('%^([1])([\d])([\d]{5})([\d][\d]{2})$%', $genid, $matcharr)
      || preg_match('%^([2])([\d])([\d]{7})([\d]{2})$%', $genid, $matcharr)
      || preg_match('%([3])([\d])([\d]{7})([\d][\d]{2})$%', $genid, $matcharr)) {
      $ntype = intval($matcharr[1]);
      $rn = intval($matcharr[2]);
      $id = $matcharr[3];
      $s2 = $matcharr[4];
      //if ($cf_debug) echo ("n_checkID: ntype=$ntype, rn=$rn, id=$id\n");
   }

   if (strlen($s2)==3) {   // with day-check
      $t = $s2{0};
      if ($timeAt!=='0' && $t!='0') {
         $time = strlen($timeAt) ? strtotime($timeAt) : time();
         if ($time <= 0) $time = time();  // invalid time
         $numTry = 0;
         while ($numTry < 2) {
            $parmarr = array(
               'service' => $service,
               'rn' => $rn,
               'timeAt' => date('Y-m-d H:i:s', $time - 43200*$numTry),
               );
            $s = n_makeID($id, $parmarr, $ntype);
            //if ($cf_debug) echo ("n_checkID: s=$s, msig=".-strlen($s)."\n");
            if ($s && preg_match("%".substr($genid, -strlen($s))."$%", $s))
               return $id;
            $numTry++;
         }
         return FALSE;
      }
   }

   $parmarr = array(
      'service' => $service,
      'rn' => $rn,
      'timeAt' => '0',
      );
   $s = n_makeID($id, $parmarr, $ntype);
   //if ($cf_debug) echo ("n_checkID: s=$s, msig=".-strlen($s)."\n");
   if (($ntype==0 && intval($s)==intval($genid))
      || ($s && preg_match("%".substr($genid, -strlen($s))."$%", $s)))
      return $id;

   return FALSE;
}


/**
 *  Makes a secure pseudo-mid from the specified parameters.
 *
 **/
function pmid_make($id, $cntr, $timeAt=0)
{
   // Obtain parameters
   $id = intval($id);
   $cntr = strtolower(trim($cntr));
   if (empty($id) || empty($cntr)) return FALSE;   // cannot make using invalid ID

   $s1 = cntr2id($cntr).sprintf("%08u", $id);
   $t = '0';
   $hash = md5('pmid'.$id.$cntr);
   $s2 = $t.sprintf("%02u", hexdec(substr($hash,-6)) % 90 + 10);  // digits [10,99]
   return $s1.$s2;   // 15-char
}


/**
 *  Checks if pseudo-mid is valid.
 *
 *  @return row_id if valid; FALSE otherwise.
 **/
function pmid_check($pmid, $cntr, $timeAt=0)
{
   $cntr = strtolower(trim($cntr));
   if (empty($pmid) || empty($cntr) || !preg_match('%^0{0,1}([\d]{4})([\d]{8})([\d])([\d]{2})$%', $pmid, $matches))
      return FALSE;

   $id = intval($matches[2]);
   if ($pmid == pmid_make($id, $cntr, 0))   // match
      return $id;

   return FALSE;
}


function uid_makeID($uid)
{
   global $base_charset;

   $uid = intval($uid);
   if (!empty($uid)) {
      $hash = md5('cmail'.$uid);
      $cchar = $base_charset[32][10+(hexdec(substr($hash,-4)) % 22)];   // chars [a,z]
      return ('u'.$uid.$cchar);
   }
   return FALSE;
}


function uid_checkID($uidc)
{
   $uidc = strtolower(trim($uidc));
   if (empty($uidc) || !preg_match('%^[u]([\d]+)[a-z]$%i', $uidc, $matches))
      return FALSE;

   $uid = intval($matches[1]);
   if (strcasecmp($uidc, uid_makeID($uid))==0)
      return $uid;
   return FALSE;
}


/**
 *  Extracts non-private IP addr from a list.
 *
 *  Returns FALSE if none found.
 **/
function ipextract ($str, $remote_ip='')
{
   global $ip_private_arr;

   if (empty($ip_private_arr) || !is_array($ip_private_arr)) {
      $ip_private_arr = array();
      $ip_private_arr[] = array('from' => '0.0.0.0', 'to' => '9.255.255.255');
      $ip_private_arr[] = array('from' => '10.0.0.0', 'to' => '10.255.255.255');
      $ip_private_arr[] = array('from' => '97.160.0.0', 'to' => '97.255.255.255');
      $ip_private_arr[] = array('from' => '100.0.0.0', 'to' => '111.255.255.255');
      $ip_private_arr[] = array('from' => '127.0.0.0', 'to' => '127.255.255.255');
      $ip_private_arr[] = array('from' => '145.0.0.0', 'to' => '145.0.255.255');
      $ip_private_arr[] = array('from' => '163.0.0.0', 'to' => '163.0.255.255');
      $ip_private_arr[] = array('from' => '169.254.0.0', 'to' => '169.254.255.255');
      $ip_private_arr[] = array('from' => '172.0.0.0', 'to' => '172.127.255.255');
      $ip_private_arr[] = array('from' => '175.0.0.0', 'to' => '185.255.255.255');
      $ip_private_arr[] = array('from' => '191.0.0.0', 'to' => '192.0.255.255');
      $ip_private_arr[] = array('from' => '192.88.0.0', 'to' => '192.88.255.255');
      $ip_private_arr[] = array('from' => '192.101.0.0', 'to' => '192.114.255.255');
      $ip_private_arr[] = array('from' => '192.140.0.0', 'to' => '192.145.255.255');
      $ip_private_arr[] = array('from' => '192.168.0.0', 'to' => '192.178.255.255');
      $ip_private_arr[] = array('from' => '194.55.0.0', 'to' => '194.55.255.255');
      $ip_private_arr[] = array('from' => '198.17.0.0', 'to' => '198.20.255.255');
      $ip_private_arr[] = array('from' => '224.0.0.0', 'to' => '239.255.255.255');
   }

   $iplong = ip2long(trim($remote_ip));
   $valarr = empty($str) ? array() : preg_split('/[,\s]+/', trim($str));
   foreach ($valarr as $ipval) {
      if (preg_match('%(\d{1,3}(?:[.]\d{1,3}){3})%', $ipval, $matches)) {
         $iptest = ip2long($matches[1]);
         if ($iptest) {
            if (empty($iplong)) $iplong = $iptest;
            if (!empty($ip_private_arr)) {
               foreach ($ip_private_arr as $ip_range) {
                  $ipfrom = ip2long(trim($ip_range['from']));
                  $ipto = ip2long(trim($ip_range['to']));
                  if ($ipfrom<=$iptest && $iptest<=$ipto) {
                     $iptest = 0;
                     break;
                  }
               }
               if ($iptest) $iplong = $iptest;
            }
         }
      }
   }
   return (!$iplong || $iplong==-1) ? FALSE : long2ip($iplong);
}


/**
 *  Connects to the database just when it is required.
 *
 *  IMPT: Connection to same named database is shared, so use only
 *  one sql operation at a time.  Eg. use $sql->result_push function.
 *
 *  @returns the sql connection object.
 **/
function connectdb($dbname='', $isDirty=FALSE)
{
   global $cf_db, $cf_db_default;
   global $cache_databases;

   if (!$dbname) {
      $dbname = $cf_db_default;
      if (!$dbname) return NULL;
   }

   if (!isset($cache_databases) || !is_array($cache_databases)) $cache_databases = array();  // initialize cache

   if (isset($cache_databases[$dbname])) {
      $sql = $cache_databases[$dbname];
      if (!$isDirty && is_resource($sql->id) && @mysql_ping($sql->id)) return $sql;

      // Close resources
      $sql->Close();
      $sql->mode_id('r');  // switch to alternate mode, if any
      $sql->Close();

      unset($cache_databases[$dbname]);   // remove from cache
   }

   if (!$cf_db || !is_array($cf_db[$dbname])) return NULL;  // config invalid or missing

   // Connect to DB
   $sql = new MySQL_class;
   $sql->InitOpen($cf_db[$dbname]);
   if (isset($cf_db[$dbname.'_r']))    // replica-DB defined
      $sql->InitOpen($cf_db[$dbname.'_r']);
   $sql->option_err_suppress = TRUE;

   if ($sql->errno != 0)   // Error: Unable to connect to Database
      return NULL;

   $cache_databases[$dbname] = $sql;    // cache it
   return $cache_databases[$dbname];
}


/**
 *  Closes the active database to the database just when it is required.
 *
 *  IMPT: $sql is shared, so use only one sql operation at a time.
 **/
function closedb($sql)
{
   if ($sql) {
      // Close resources
      $sql->Close();
      $sql->mode_id('r');  // switch to alternate mode, if any
      $sql->Close();
   }
}


function location_redirect($surl, $vmode='', $text='')
{
   $vmode = !empty($vmode) ? trim($vmode) : '';
   $surl = trim($surl);
   if (empty($surl)) return FALSE;
   ob_end_clean();   // discard output buffer

   if (strcasecmp($vmode,'wap')==0) {  // wap-mode
      header("Content-Type: text/vnd.wap.wml; charset=utf-8");

      echo ("<?xml version=\"1.0\" encoding=\"utf-8\" ?>\n");
      echo ("<!DOCTYPE wml PUBLIC \"-//WAPFORUM//DTD WML 1.1//EN\" \"http://www.wapforum.org/DTD/wml_1.1.dtd\">\n");
      echo ("<wml>\n");
      echo ("<head>\n");
      echo ("<meta http-equiv=\"Cache-Control\" content=\"max-age=0\" forua=\"true\" />");
      echo ("</head>\n");
      echo ("<card id=\"main\" title=\"Processing\" ontimer=\"".xml_encode_wap($surl)."\">\n");
      echo ("<timer value=\"1\" />");
      if (!empty($text)) echo ("<p>".xml_encode_wap($text)."</p>\n");
      echo ("</card>\n");
      echo ("</wml>\n");
   }
   else {   // assume web-mode
        header("HTTP/1.1 301 Moved Permanently");
        header("Location: ".$surl);
   }
   exit();  // end parent-script
}


function xml_encode_wap($text)
{
   $encstrs=array("&amp;", "&gt;", "&lt;", "&apos;", "&quot;", '$$');
   $decstrs=array("&", ">", "<", "'", "\"", '$');
   return str_replace($decstrs, $encstrs, $text);
}


function uri_extract($key)
{
   $key = preg_match("%([_a-z\d]{1,20})%i", $key, $matches) ? $matches[1] : '';
   if ($key && preg_match("%[^a-z]".$key."[=]([^&$]+)%i", $_SERVER["REQUEST_URI"], $matches))
      return $matches[1];
   return FALSE;
}


function getScriptname()
{
   $script_name = !empty($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : (!empty($_SERVER['argv'][0]) ? $_SERVER['argv'][0] : '');
   return (preg_match("%([a-z][_a-z\d]*)(?:\.php){0,1}$%i", $script_name, $matches) ? $matches[1] : 'unknown');
}


function isValidTime($timeAt)
{
   return $timeAt && substr($timeAt,0,4)!='0000' && (strtotime($timeAt)!==-1);
}


function crc32String($str)
{
   return sprintf("%08x",crc32($str));
}

?>
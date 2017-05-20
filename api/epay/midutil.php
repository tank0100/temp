<?
/*
 *  midutil.php - An algorithm to determine the country from the mid.
 *  v2.48.2011042503
 *
 *  @parm uidcntr - user's country
 *  @parm mid - friend's mobile id
 *  @return cntr - most likely country
 *  @return opercode - most likely operator, if any
 *
 *  Change History
 *  ~~~~~~~~~~~~~~
 *  V1.0  CC:  Release version (2004-08-07).
 *  V1.1  CC:  Added recognition for au and ph mobile ids (2004-08-08).
 *  V1.2  CC:  Updated new au mobile patterns (2004-12-03).
 *  V1.3  CC:  Added in mobile patterns (2004-01-11).
 *  V1.4  CC:  Added cnct and cntest mobile patterns (2005-06-06).
 *  V1.5  CC:  Added uk mobile patterns (2005-08-23).
 *  V1.6  CC:  Updated id mobile patterns and added various cntr prefixes (2005-09-14).
 *  V1.7  CC:  Return international format MSISDN in addition to mid (2005-10-21).
 *  V1.8  CC:  Added unconfirmed patterns for Algeria, Nigeria, Cyprus, Brazil, Paraguay, Uruguay, Vietnam, Laos, Pakistan (2005-11-18).
 *  V1.9  CC:  Added bn Telco mobile formats (2005-12-15).
 *  V2.0  CC:  Added za Telco mobile formats (2006-01-18).
 *  V2.1  CP:  Confirmed Ecuador mobile formats (2006-02-17).
 *  V2.2  CC:  Support pseudo-mid (2006-02-27).
 *  V2.3  CC:  Updates to cn and th mobile formats (2006-03-23).
 *  V2.4  CC:  Confirmed Romania mobile formats (2006-05-04).
 *  V2.5  CC:  Updated cn and ec mobile formats (2006-05-11).
 *  V2.6  CC:  Added unconfirmed patterns for Mauritius, Maldives, Kuwait, Egypt, Hungary, Ukraine, Jordan, Saudi Arabia, Finland, Bangladesh, Greece (2006-05-23).
 *  V2.7  CC:  Added unconfirmed patterns for Morocco, Russia (2006-05-23).
 *  V2.8  CC:  Added patterns for Argentina, France, Ireland, Italy, Mexico, Spain, Sweden, UAE (2006-06-07).
 *  V2.9  CC:  Support regions eg. NANP using region files such as midutil_nanp (2006-06-28).
 *  V2.10 CC:  Updates to za, lk, pk mobile formats (2006-07-06).
 *  V2.11 CC:  Added patterns for Peru, Israel and UAE-Dubai (2006-08-21).
 *  V2.12 CC:  Interim provision for Thailand for migration to 10-digit number format (2006-08-22).
 *  V2.13 CC:  Fixed NANP region for Canada, Jamaica (2006-10-11).
 *  V2.14 CC:  Updated Serbia, Montenegro, Vietnam, Brazil, Venezuela mobile formats (2006-10-20).
 *  V2.15 CC:  Serbia cntr changed from 'cs' to 'rs' (2006-11-28).
 *  V2.16 CC:  Updated Germany, Italy mobile formats (2006-12-11).
 *  V2.17 CC:  Added Kenya, Taiwan mobile formats (2006-12-22).
 *  V2.18 CC:  Updated HongKong, added Sudan, Turkey mobile formats (2007-02-22).
 *  V2.19 CC:  Updated Saudi Arabia, added Belgium mobile formats (2007-03-14).
 *  V2.20 CP:  Updated Saudi Arabia's pattern to handle 08 prefix and variable length (2007-03-16).
 *  V2.21 CC:  Updated Belgium, Norway mobile formats; added Cote D'Ivoire and Libya formats (2007-04-16).
 *  V2.22 CC:  Updated Austria, Switzerland mobile formats (2007-05-29).
 *  V2.23 CC:  Updated Guyana, Tunisia, Turkey mobile formats (2007-06-25).
 *  V2.24 CC:  Updated Panama, New Zealand, Tanzania, Uganda, Azerbaijan mobile formats (2007-07-16).
 *  V2.25 CC:  Updated Denmark, Norway, Democratic Congo, Namibia, Finland mobile formats (2007-07-16).
 *  V2.26 CC:  Updated Estonia, Latvia mobile formats (2007-08-27).
 *  V2.27 CC:  Updated New Zealand, Denmark, added Netherlands mobile formats (2007-10-15).
 *  V2.28 CC:  Updated Nigeria, added Poland, Portugal mobile formats (2007-12-13).
 *  V2.29 CC:  Added Croatia, Yemen mobile formats; updated UAE formats (2008-02-01).
 *  V2.30 CC:  Updated NANP (US), UAE (Dubai); added Iran, Nepal mobile formats (2008-03-10).
 *  V2.31 CC:  Added Luxembourg, Qatar, Iceland mobile formats; updated Kuwait, Greece (2008-04-08).
 *  V2.32 CC:  Updated Croatia, Hungary, Iran, Martinique, Mauritius, Ukraine, Uruguay mobile formats (2008-05-12).
 *  V2.33 CC:  Added Bolivia, Bosnia, Syria, Reunion mobile formats; updated Brazil, Vietnam (2008-05-13).
 *  V2.34 CC:  Added Ghana, Czech Republic (2008-06-12).
 *  V2.35 CC:  Added Malawi; updated Malaysia, Uganda, Guyana (2008-08-11).
 *  V2.36 CC:  Added Costa Rica, Guatemala, Monaco; updated Bosnia & Hercegovina (2008-11-25).
 *  V2.37 CC:  Added Armenia, Liberia, Mozambique, Senegal; updated Brunei, Kuwait (2009-02-02).
 *  V2.38 CC:  Added Zambia; updated Malaysia (2009-02-10).
 *  V2.39 CC:  Updated Malawi, HongKong (2009-08-20).
 *  V2.40 CC:  Updated India 8141 series for Vodafone (2009-11-19).
 *  V2.41 CC:  Added Oman and Lebanon (2009-11-24).
 *  V2.42 CC:  Updated India, Tanzania, Ghana, Malawi, Cote D'Ivoire, Morocco, Pakistan (2010-01-18).
 *  V2.43 CC:  Added Swaziland; updated Qatar (2010-01-20).
 *  V2.44 CC:  Updated Japan (2010-06-08).
 *  V2.45 CC:  Updated India 7-series mobile numbers (2010-06-28).
 *  V2.46 CC:  Updated Qatar to support 8-digit mobile numbers (2010-11-18).
 *  V2.47 CP:  Added Colombia and Korea (2011-03-02).
 *  V2.48 CC:  Updated Egypt, Thailand mobile formats (2011-04-25).
 */

function Mid_Util() {
   // Dummy presence indicator
}

if (empty($cf_utldir)) {
   // discover the utldir
   $cf_utldir = dirname(__FILE__).'/';
   if (!file_exists($cf_utldir.'midutil.php')) $cf_utldir = "../util/";
   if (!file_exists($cf_utldir.'midutil.php')) {
      echo ("ERROR LOCATING UTIL DIRECTORY... \n");
      exit();
   }
}

// CONFIGURATION VARIABLES
if (!isset($cf_debug)) $cf_debug = FALSE;

$cf_midformat ['mu']['prefix'] = "230";   // Mauritius
$cf_midformat ['mu']['format'] = "(?:[2479]\d|87)\d{5}";
$cf_midformat ['mu']['fixlen'] = 7;

$cf_midformat ['is']['prefix'] = "354";   // Iceland
$cf_midformat ['is']['format'] = "[678]\d{6}";
$cf_midformat ['is']['fixlen'] = 7;

$cf_midformat ['ee']['prefix'] = "372";   // Estonia
$cf_midformat ['ee']['format'] = "[5]\d{6}";
$cf_midformat ['ee']['fixlen'] = 7;

$cf_midformat ['bn']['prefix'] = "673";   // Brunei
$cf_midformat ['bn']['format'] = array (
   'bnbm' => "8[1]\d{5}",           // Brunei-Bmobile
   'bnds' => "(?:7[1]|8[26789])\d{5}",    // Brunei-DST
   'bnxx' => "(?:7[2-9]|8[345])\d{5}",    // Brunei-Unknown
);
$cf_midformat ['bn']['fixlen'] = 7;

$cf_midformat ['pa']['prefix'] = "507";   // Panama
$cf_midformat ['pa']['format'] = "[6][56789]\d{5}";
$cf_midformat ['pa']['fixlen'] = 7;

$cf_midformat ['gy']['prefix'] = "592";   // Guyana
$cf_midformat ['gy']['format'] = "[6][1-9]\d{5}";
$cf_midformat ['gy']['fixlen'] = 7;

$cf_midformat ['qa']['prefix'] = "974";   // Qatar
$cf_midformat ['qa']['format'] = "[567]\d{6,7}";
// Qatar MSISDNs have 7 (old format) or 8 digits
// Refer to www.qtel.qa/NumberChange.do

$cf_midformat ['mv']['prefix'] = "960";   // Maldives - unconfirmed
$cf_midformat ['mv']['format'] = "[1-9]\d{6}";
$cf_midformat ['mv']['fixlen'] = 7;

$cf_midformat ['lr']['prefix'] = "231";   // Liberia
$cf_midformat ['lr']['format'] = "(?:[456]|[7]\d)\d{6}";
// Liberia MSISDNs can vary from 7 to 8 digits

$cf_midformat ['dk']['prefix'] = "45";    // Denmark
$cf_midformat ['dk']['format'] = "(?:[2]\d|[3456][01])\d{6}";
$cf_midformat ['dk']['fixlen'] = 8;

$cf_midformat ['no']['prefix'] = "47";    // Norway
$cf_midformat ['no']['format'] = "[469]\d{7}";
$cf_midformat ['no']['fixlen'] = 8;

$cf_midformat ['sg']['prefix'] = "65";    // Singapore
$cf_midformat ['sg']['format'] = "[89]\d{7}";
$cf_midformat ['sg']['fixlen'] = 8;

$cf_midformat ['ci']['prefix'] = "225";   // Cote d'Ivoire, formerly Ivory Coast
$cf_midformat ['ci']['format'] = "[04][156789]\d{6}";   // 0-prefix compulsory
$cf_midformat ['ci']['fixlen'] = 8;

$cf_midformat ['mw']['prefix'] = "265";   // Malawi
$cf_midformat ['mw']['format'] = "0(?:[89]|(?:77|88|99)[1-9])\d{6}";   // 0-prefix compulsory
// Malawi MSISDNs have 8 (old format) or 10 digits

$cf_midformat ['lt']['prefix'] = "370";   // Lithuania
$cf_midformat ['lt']['format'] = "[6]\d{7}";
$cf_midformat ['lt']['fixlen'] = 8;

$cf_midformat ['lv']['prefix'] = "371";   // Latvia
$cf_midformat ['lv']['format'] = "[2]\d{7}";
$cf_midformat ['lv']['fixlen'] = 8;

$cf_midformat ['gt']['prefix'] = "502";   // Guatemala
$cf_midformat ['gt']['format'] = "[45]\d{7}";
$cf_midformat ['gt']['fixlen'] = 8;

$cf_midformat ['cr']['prefix'] = "506";   // Costa Rica
$cf_midformat ['cr']['format'] = "[38]\d{7}";
$cf_midformat ['cr']['fixlen'] = 8;

$cf_midformat ['hk']['prefix'] = "852";   // HongKong, China
$cf_midformat ['hk']['format'] = "[569]\d{7}";
$cf_midformat ['hk']['fixlen'] = 8;

$cf_midformat ['kw']['prefix'] = "965";   // Kuwait
$cf_midformat ['kw']['format'] = "[569][1-9]\d{6}";
$cf_midformat ['kw']['fixlen'] = 8;

$cf_midformat ['om']['prefix'] = "968";   // Oman
$cf_midformat ['om']['format'] = "[9][1-9]\d{6}";
$cf_midformat ['om']['fixlen'] = 8;

$cf_midformat ['mc']['prefix'] = "377";   // Monaco
$cf_midformat ['mc']['format'] = "(?:[4]\d{7}|[6]\d{8})";
// Monaco MSISDNs can vary from 8 to 9 digits

$cf_midformat ['lb']['prefix'] = "961";   // Lebanon
$cf_midformat ['lb']['format'] = "0{0,1}(?:[3]|[7][01])\d{6}";
// Lebanon MSISDNs can vary from 8 to 9 digits

$cf_midformat ['es']['prefix'] = "34";    // Spain
$cf_midformat ['es']['format'] = "[6]\d{8}";
$cf_midformat ['es']['fixlen'] = 9;

$cf_midformat ['ma']['prefix'] = "212";   // Morocco
$cf_midformat ['ma']['format'] = "0{0,1}(?:[6][1-9]|[679])\d{7}";
// Morocco MSISDNs have 9 (old format) or 10 digits

$cf_midformat ['tn']['prefix'] = "216";   // Tunisia
$cf_midformat ['tn']['format'] = "0{0,1}[29]\d{7}";
$cf_midformat ['tn']['fixlen'] = 9;

$cf_midformat ['sn']['prefix'] = "221";   // Senegal
$cf_midformat ['sn']['format'] = "[7][1-9][1-9]\d{6}";
$cf_midformat ['sn']['fixlen'] = 9;

$cf_midformat ['mz']['prefix'] = "258";   // Mozambique
$cf_midformat ['mz']['format'] = "[8][1-7]\d{7}";
$cf_midformat ['mz']['fixlen'] = 9;

$cf_midformat ['sz']['prefix'] = "268";   // Swaziland
$cf_midformat ['sz']['format'] = "0{0,1}[7][1-9]\d{6}";
$cf_midformat ['sz']['fixlen'] = 9;

$cf_midformat ['pt']['prefix'] = "351";   // Portugal
$cf_midformat ['pt']['format'] = "[9]\d{8}";
$cf_midformat ['pt']['fixlen'] = 9;

$cf_midformat ['lu']['prefix'] = "352";   // Luxembourg
$cf_midformat ['lu']['format'] = "[6][269]\d{7}";
$cf_midformat ['lu']['fixlen'] = 9;

$cf_midformat ['am']['prefix'] = "374";   // Armenia
$cf_midformat ['am']['format'] = "0{0,1}[9][1-9]\d{6}";
$cf_midformat ['am']['fixlen'] = 9;

$cf_midformat ['ua']['prefix'] = "380";   // Ukraine
$cf_midformat ['ua']['format'] = "(?:[3][9]|[5][0]|6[3678]|9[1-9])\d{7}";
$cf_midformat ['ua']['fixlen'] = 9;

$cf_midformat ['ba']['prefix'] = "387";   // Bosnia and Hercegovina
$cf_midformat ['ba']['format'] = "0{0,1}[6][1-9]\d{6}";
$cf_midformat ['ba']['fixlen'] = 9;

$cf_midformat ['cz']['prefix'] = "420";   // Czech Republic
$cf_midformat ['cz']['format'] = "(?:[6]0|[789]\d)\d{7}";
$cf_midformat ['cz']['fixlen'] = 9;

$cf_midformat ['bo']['prefix'] = "591";   // Bolivia
$cf_midformat ['bo']['format'] = "0{0,1}[7]\d{7}";
$cf_midformat ['bo']['fixlen'] = 9;

$cf_midformat ['ec']['prefix'] = "593";   // Ecuador
$cf_midformat ['ec']['format'] = "0{0,1}[89]\d{7}";
$cf_midformat ['ec']['fixlen'] = 9;

$cf_midformat ['mq']['prefix'] = "596";   // Martinique, French Antilles
$cf_midformat ['mq']['format'] = "[6][9]\d{7}";
$cf_midformat ['mq']['fixlen'] = 9;

$cf_midformat ['uy']['prefix'] = "598";   // Uruguay
$cf_midformat ['uy']['format'] = "0{0,1}9[1-9]\d{6}";
$cf_midformat ['uy']['fixlen'] = 9;

$cf_midformat ['dz']['prefix'] = "213";   // Algeria - unconfirmed
$cf_midformat ['dz']['format'] = "0{0,1}[1-9]\d{7}";
$cf_midformat ['dz']['fixlen'] = 9;

$cf_midformat ['cy']['prefix'] = "357";   // Cyprus - unconfirmed
$cf_midformat ['cy']['format'] = "0{0,1}[1-9]\d{7}";
$cf_midformat ['cy']['fixlen'] = 9;

$cf_midformat ['it']['prefix'] = "39";    // Italy
$cf_midformat ['it']['format'] = "[3]\d{8,9}";
// Italy MSISDNs have 9 digits or 10 (old format)

$cf_midformat ['nz']['prefix'] = "64";    // New Zealand
$cf_midformat ['nz']['format'] = "0{0,1}[2][13579]\d{6,8}";
// New Zealand MSISDNs can vary from 9 to 11 digits

$cf_midformat ['th']['prefix'] = "66";    // Thailand
$cf_midformat ['th']['format'] = "0{0,1}(?:[89]\d{8}|[12345679]\d{7})";
// Thailand MSISDNs have 9 (old format) or 10 digits

$cf_midformat ['ly']['prefix'] = "218";   // Libya
$cf_midformat ['ly']['format'] = "0{0,1}[9][1-9]\d{6,7}";
// Libya MSISDNs have 9 (old format) or 10 digits

$cf_midformat ['gh']['prefix'] = "233";   // Ghana
$cf_midformat ['gh']['format'] = "0{0,1}(?:[2][046789]|[25]\d{2})\d{6}";
// Ghana MSISDNs can vary from 9 (old format) to 10 digits

$cf_midformat ['rs']['prefix'] = "381";   // Serbia (formerly Yugoslavia)
$cf_midformat ['rs']['format'] = "0{0,1}[6][1-9]\d{6,7}";
// Serbia MSISDNs can vary from 9 to 10 digits

$cf_midformat ['yu']['prefix'] = "382";   // Montenegro (formerly Yugoslavia)
// Montenegro is now an independent nation, having split from Serbia in 2006 Jun.

$cf_midformat ['sa']['prefix'] = "966";   // Saudi Arabia
$cf_midformat ['sa']['format'] = "0{0,1}[58]\d{7,8}";
// SA MSISDNs are mostly 10 digits, but can be 9

$cf_midformat ['us']['prefix'] = "1";
$cf_midformat ['us']['format'] = "[2-9]\d{2}[1-9]\d{6}";  // 234-123-4567
$cf_midformat ['us']['region'] = 'nanp';  // USA
$cf_midformat ['ca']['region'] = 'nanp';  // Canada
$cf_midformat ['jm']['region'] = 'nanp';  // Jamaica
$cf_midformat ['nanp']['prefix'] = "1";   // NANP patterns eg. 'us', 'ca', 'jm'
$cf_midformat ['nanp']['format'] = "[2-9][0-8]\d[1-9]\d{6}";   // 234-123-4567
$cf_midformat ['nanp']['fixlen'] = 10;

$cf_midformat ['ru']['prefix'] = "7";     // Russia
$cf_midformat ['ru']['format'] = "[9]\d{9}";
$cf_midformat ['ru']['fixlen'] = 10;

$cf_midformat ['za']['prefix'] = "27";    // South Africa
$cf_midformat ['za']['format'] = array (
   'zavd' => "0{0,1}[78][269]\d{7}",   // ZA-Vodacom
   'zamt' => "0{0,1}[78][38]\d{7}",    // ZA-MTN
   'zacc' => "0{0,1}[78][4]\d{7}",     // ZA-CellC
   'zaxx' => "0{0,1}[78][157]\d{7}"    // ZA-Unknown
   );
$cf_midformat ['za']['fixlen'] = 10;

$cf_midformat ['gr']['prefix'] = "30";    // Greece
$cf_midformat ['gr']['format'] = "[6][9]\d{8}";
$cf_midformat ['gr']['fixlen'] = 10;

$cf_midformat ['nl']['prefix'] = "31";    // Netherlands aka Holland
$cf_midformat ['nl']['format'] = "0{0,1}[6][25]\d{7}";
$cf_midformat ['nl']['fixlen'] = 10;

$cf_midformat ['be']['prefix'] = "32";    // Belgium
$cf_midformat ['be']['format'] = "0{0,1}[24579]\d{7,8}";
// Belgium MSISDNs are mostly 10 digits, but can be 9

$cf_midformat ['fr']['prefix'] = "33";    // France
$cf_midformat ['fr']['format'] = "0{0,1}[67]\d{8}";
$cf_midformat ['fr']['fixlen'] = 10;

$cf_midformat ['hu']['prefix'] = "36";    // Hungary
$cf_midformat ['hu']['format'] = "0{0,1}[23567][0]\d{7}";
$cf_midformat ['hu']['fixlen'] = 10;

$cf_midformat ['ro']['prefix'] = "40";    // Romania
$cf_midformat ['ro']['format'] = "0{0,1}[7]\d{8}";
$cf_midformat ['ro']['fixlen'] = 10;

$cf_midformat ['ch']['prefix'] = "41";    // Switzerland
$cf_midformat ['ch']['format'] = "0{0,1}7[1-9]\d{7}";
$cf_midformat ['ch']['fixlen'] = 10;

$cf_midformat ['se']['prefix'] = "46";    // Sweden
$cf_midformat ['se']['format'] = "0{0,1}[267]\d{8}";
$cf_midformat ['se']['fixlen'] = 10;

$cf_midformat ['pl']['prefix'] = "48";    // Poland
$cf_midformat ['pl']['format'] = "0{0,1}[56789]\d{8}";
$cf_midformat ['pl']['fixlen'] = 10;

$cf_midformat ['pe']['prefix'] = "51";    // Peru
$cf_midformat ['pe']['format'] = "0{0,1}[1-9]\d{8}";
$cf_midformat ['pe']['fixlen'] = 10;

$cf_midformat ['mx']['prefix'] = "52";    // Mexico
$cf_midformat ['mx']['format'] = "[1-9]\d{9}";
$cf_midformat ['mx']['fixlen'] = 10;

$cf_midformat ['co']['prefix'] = "57";    // Colombia
$cf_midformat ['co']['format'] = "[3][012]\d{8}";
$cf_midformat ['co']['fixlen'] = 10;

$cf_midformat ['my']['prefix'] = "60";    // Malaysia
$cf_midformat ['my']['format'] = array (
   'mydg' => "0{0,1}1(?:[6]\d{7}|4[36]\d{6}|0[1-9]\d{6})",  // Malaysia-Digi
   'mymx' => "0{0,1}1(?:[27]\d{7}|4[2]\d{6})",     // Malaysia-Maxis and Time
   'mycc' => "0{0,1}1(?:[39]\d{7}|4[8]\d{6})",     // Malaysia-Celcom and TMtouch
   'myxx' => "0{0,1}1(?:[18]\d{7}|4[14579]\d{6})",    // Malaysia-Unknown
);
$cf_midformat ['my']['fixlen'] = 10;

$cf_midformat ['au']['prefix'] = "61";    // Australia
$cf_midformat ['au']['format'] = "0{0,1}[14]\d{8}";   // Australia-Optus,Vodafone,Telstra,Virgin
$cf_midformat ['au']['fixlen'] = 10;

$cf_midformat ['vn']['prefix'] = "84";    // Vietnam
$cf_midformat ['vn']['format'] = "0{0,1}(?:[9]\d{8}|[1][26]\d{8})";
// Vietnam MSISDNs can vary from 10 to 11 digits

$cf_midformat ['id']['prefix'] = "62";    // Indonesia
$cf_midformat ['id']['format'] = "0{0,1}[8]\d{8,10}";
// Indonesia MSISDNs can vary from 10 to 12 digits

$cf_midformat ['cn']['prefix'] = "86";    // China
$cf_midformat ['cn']['format'] = array (
   'cncm' => "[0]{0,1}1[35][4-9]\d{8}",   // exclude zero-prefix
   'cncu' => "[0]{0,1}1[35][0-3]\d{8}",   // exclude zero-prefix
   'cnct' => "0{0,1}(?:25|5[12]\d{0,1})\d{8}",   // Cn-Telecom PHS - include zero-prefix
   'cntest' => "[0]{0,1}14[0-9]\d{8}",    // exclude zero-prefix
);

$cf_midformat ['in']['prefix'] = "91";    // India
$cf_midformat ['in']['format'] = "[789]\d{9}";
$cf_midformat ['in']['fixlen'] = 10;

$cf_midformat ['pk']['prefix'] = "92";    // Pakistan
$cf_midformat ['pk']['format'] = "[34]\d{9}";
$cf_midformat ['pk']['fixlen'] = 10;

$cf_midformat ['lk']['prefix'] = "94";    // Sri Lanka
$cf_midformat ['lk']['format'] = "0{0,1}[1-9]\d{8}";
$cf_midformat ['lk']['fixlen'] = 10;

$cf_midformat ['cd']['prefix'] = "243";   // Democratic Republic of Congo, formerly Zaire
$cf_midformat ['cd']['format'] = "0{0,1}[89]\d{8}";
$cf_midformat ['cd']['fixlen'] = 10;

$cf_midformat ['sd']['prefix'] = "249";   // Sudan
$cf_midformat ['sd']['format'] = "0{0,1}[9]\d{8}";
$cf_midformat ['sd']['fixlen'] = 10;

$cf_midformat ['ke']['prefix'] = "254";   // Kenya
$cf_midformat ['ke']['format'] = "0{0,1}[7]\d{8}";
$cf_midformat ['ke']['fixlen'] = 10;

$cf_midformat ['tz']['prefix'] = "255";   // Tanzania
$cf_midformat ['tz']['format'] = "0{0,1}[67][1-9]\d{7}";
$cf_midformat ['tz']['fixlen'] = 10;

$cf_midformat ['ug']['prefix'] = "256";   // Uganda
$cf_midformat ['ug']['format'] = "0{0,1}[7]\d{8}";
$cf_midformat ['ug']['fixlen'] = 10;

$cf_midformat ['zm']['prefix'] = "260";   // Zambia
$cf_midformat ['zm']['format'] = "0{0,1}[9][5-9]\d{7}";
$cf_midformat ['zm']['fixlen'] = 10;

$cf_midformat ['re']['prefix'] = "262";   // Mayotte and Reunion
$cf_midformat ['re']['format'] = "0{0,1}[6][1-9]\d{7}";
$cf_midformat ['re']['fixlen'] = 10;

$cf_midformat ['na']['prefix'] = "264";   // Namibia
$cf_midformat ['na']['format'] = "0{0,1}[8][1-9]\d{7}";
$cf_midformat ['na']['fixlen'] = 10;

$cf_midformat ['ie']['prefix'] = "353";   // Ireland
$cf_midformat ['ie']['format'] = "0{0,1}[8]\d{8}";
$cf_midformat ['ie']['fixlen'] = 10;

$cf_midformat ['py']['prefix'] = "595";   // Paraguay
$cf_midformat ['py']['format'] = "0{0,1}[9]\d{8}";
$cf_midformat ['py']['fixlen'] = 10;

$cf_midformat ['tw']['prefix'] = "886";   // Taiwan
$cf_midformat ['tw']['format'] = "0{0,1}[9]\d{8}";
$cf_midformat ['tw']['fixlen'] = 10;

$cf_midformat ['jo']['prefix'] = "962";   // Jordan
$cf_midformat ['jo']['format'] = "0{0,1}[7]\d{8}";
$cf_midformat ['jo']['fixlen'] = 10;

$cf_midformat ['sy']['prefix'] = "963";   // Syria, Arab Republic
$cf_midformat ['sy']['format'] = "0{0,1}[9][1-9]\d{7}";
$cf_midformat ['sy']['fixlen'] = 10;

$cf_midformat ['ye']['prefix'] = "967";   // Yemen
$cf_midformat ['ye']['format'] = "0{0,1}[7][1-9]\d{7}";
$cf_midformat ['ye']['fixlen'] = 10;

$cf_midformat ['ae']['prefix'] = "971";   // United Arab Emirates (UAE) including Dubai
$cf_midformat ['ae']['format'] = "0{0,1}[5][05][2-9]\d{6}";
$cf_midformat ['ae']['fixlen'] = 10;

$cf_midformat ['il']['prefix'] = "972";   // Israel
$cf_midformat ['il']['format'] = "0{0,1}[567]\d{8}";
$cf_midformat ['il']['fixlen'] = 10;

$cf_midformat ['az']['prefix'] = "994";   // Azerbaijan
$cf_midformat ['az']['format'] = "0{0,1}[4567]\d{8}";
$cf_midformat ['az']['fixlen'] = 10;

$cf_midformat ['la']['prefix'] = "856";   // LAO - unconfirmed
$cf_midformat ['la']['format'] = "0{0,1}[1-9]\d{8}";
$cf_midformat ['la']['fixlen'] = 10;

$cf_midformat ['eg']['prefix'] = "20";    // Egypt
$cf_midformat ['eg']['format'] = "0{0,1}[1][0-9]{0,1}\d{8}";
// Egypt MSISDNs have 10 (old format) or 11 digits

$cf_midformat ['ir']['prefix'] = "98";    // Iran
$cf_midformat ['ir']['format'] = "0{0,1}9[13][1-9]\d{6,7}";
// Iran MSISDNs have 10 (old format) or 11 digits

$cf_midformat ['bd']['prefix'] = "880";   // Bangladesh
$cf_midformat ['bd']['format'] = "0{0,1}[17]\d{8,9}";
// Bangladesh MSISDNs can vary from 10 to 11 digits

$cf_midformat ['hr']['prefix'] = "385";   // Croatia (Hrvatske)
$cf_midformat ['hr']['format'] = "0{0,1}[9][1-9]\d{7,9}";
// Croatia MSISDNs can vary from 10 to 12 digits

$cf_midformat ['fi']['prefix'] = "358";   // Finland
$cf_midformat ['fi']['format'] = "0{0,1}[45]\d{7,9}";
// Finland MSISDNs can range from 9 to 11 digits

$cf_midformat ['uk']['prefix'] = "44";    // United Kingdom (aka GB)
$cf_midformat ['uk']['format'] = "0{0,1}[45678]\d{9}";
$cf_midformat ['uk']['fixlen'] = 11;

$cf_midformat ['br']['prefix'] = "55";    // Brazil
$cf_midformat ['br']['format'] = "0{0,1}[1-9][\d][6789]\d{7}";
$cf_midformat ['br']['fixlen'] = 11;

$cf_midformat ['ve']['prefix'] = "58";    // Venezuela
$cf_midformat ['ve']['format'] = "0{0,1}[4][1-9]\d{8}";
$cf_midformat ['ve']['fixlen'] = 11;

$cf_midformat ['ph']['prefix'] = "63";    // Philippines
$cf_midformat ['ph']['format'] = "0{0,1}[9]\d{9}";
$cf_midformat ['ph']['fixlen'] = 11;

$cf_midformat ['jp']['prefix'] = "81";    // Japan
$cf_midformat ['jp']['format'] = "0{0,1}[89][0]\d{8}";
$cf_midformat ['jp']['fixlen'] = 11;

$cf_midformat ['kr']['prefix'] = "82";    // South Korea
$cf_midformat ['kr']['format'] = "0{0,1}[1][1-9]\d{8}";
$cf_midformat ['kr']['fixlen'] = 11;

$cf_midformat ['tr']['prefix'] = "90";    // Turkey
$cf_midformat ['tr']['format'] = "0{0,1}[59]\d{9}";
$cf_midformat ['tr']['fixlen'] = 11;

$cf_midformat ['ng']['prefix'] = "234";   // Nigeria
$cf_midformat ['ng']['format'] = "0{0,1}(?:[79][0]|[8]\d)\d{8}";
$cf_midformat ['ng']['fixlen'] = 11;

$cf_midformat ['np']['prefix'] = "977";    // Nepal
$cf_midformat ['np']['format'] = "0{0,1}98\d{8}";
$cf_midformat ['np']['fixlen'] = 11;

$cf_midformat ['ar']['prefix'] = "54";    // Argentina - unconfirmed
$cf_midformat ['ar']['format'] = "[9]\d{10}";
$cf_midformat ['ar']['fixlen'] = 11;

$cf_midformat ['de']['prefix'] = "49";    // Germany
$cf_midformat ['de']['format'] = "0{0,1}1(?:[5]\d{9}|[67]\d{8})";
// Germany MSISDNs can vary from 11 to 12 digits

$cf_midformat ['at']['prefix'] = "43";    // Austria
$cf_midformat ['at']['format'] = "0{0,1}6[4-9]\d{5,11}";
// Austria MSISDNs can range from 8 to 14 digits incl. trunk prefix

$cf_midformat ['kg']['prefix'] = "996";   // Kyrgyz Republic
$cf_midformat ['tj']['prefix'] = "992";   // Tajikistan
$cf_midformat ['pf']['prefix'] = "689";   // French Polynesia
$cf_midformat ['gf']['prefix'] = "594";   // French Guiana
$cf_midformat ['gp']['prefix'] = "590";   // Guadeloupe, French Antilles
$cf_midformat ['sv']['prefix'] = "503";   // El Salvador
$cf_midformat ['sc']['prefix'] = "248";   // Seychelles
$cf_midformat ['td']['prefix'] = "235";   // Chad
$cf_midformat ['ml']['prefix'] = "223";   // Mali
$cf_midformat ['cl']['prefix'] = "56";    // Chile

// default is defined last
//$cf_midformat ['unknown']['prefix'] = "[1-9]\d{0,2}";    // up to 3-digit international-prefix
$cf_midformat ['unknown']['prefix'] = "1|2(?:[07]|[1-9]\d)|3(?:[0-469]|[578]\d)|4(?:[013-9]|2\d)|5(?:[09]\d|[1-8])|6(?:[0-6]|[7-9]\d)|7|8(?:[057]\d|[1246]|80|81|821\d)|9(?:[0-58]|[679]\d)";
$cf_midformat ['unknown']['format'] = "0{0,1}[1-9]\d{5,11}";   // 6 to 13 digits


/**
 *  Returns a country's mobile id format
 *  - as a string (if operators are not differentiated), or
 *  - as an array, indexed by operators;
 *  NULL if not found.
 **/
function get_cntrformat ($usercntr) {
   global $cf_midformat;

   $usercntr = strtolower(trim($usercntr));

   if (isset($cf_midformat[$usercntr])) {
      // first check mid against user's country
      $formatarr = $cf_midformat[$usercntr];
      $format = $formatarr['format'];  // optional - may not be present
      return $format;
   }
   return NULL;
}


function has_cntrformat ($usercntr) {
   global $cf_midformat;

   $usercntr = strtolower(trim($usercntr));

   if (isset($cf_midformat[$usercntr])) {
      if (!empty($cf_midformat[$usercntr]['format']) || !empty($cf_midformat[$usercntr]['region']))
         return TRUE;
   }
   return FALSE;
}


function _id2cntr ($val)
{
   $base_charset = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';

   $base = 64;
   $val = intval($val);
   $str = '';
   while ($val > 0) {
      $p = $val % 64;
      $str = $base_charset{$p} . $str;
      $val = ($val - $p) >> 6;
   }
   return $str;
}


/**
 *  Find the most likely country which the specified $mid belongs to,
 *  given the user friend's cntr ($usercntr).
 *
 *  The matching is attempted against the supplied $usercntr first,
 *  before trying the other country formats in order of likelihood.
 *
 *  @return as an array the cntr, opercode(if any) and normalized mid;
 *     if mid may be valid but a match not found, cntr is returned as 'unknown';
 *     if mid is invalid, NULL is returned.
 **/
function mid_match ($mid, $usercntr='') {
   global $cf_midformat;   // my intelligent definition of country formats
   global $regionarr;

   $usercntr = strtolower(trim($usercntr));
   $mid = preg_replace("%[-, ]%", "", trim($mid));   // replace typical number separators

   if (!$mid) return NULL;
   $regionarr = array();  // initialize

   $isFound = FALSE;
   if (!preg_match('%^\+%',$mid)) {    // may not be country-prefixed

      // Check for pseudo-mid
      if (preg_match('%^0{0,1}(([\d]{4})[\d]{8}[\d]{3})$%', $mid, $matches)) {
         $mid = $matches[1];
         $cntr = _id2cntr($matches[2]);

         $cntrformat = get_cntrformat($cntr);
         if (!is_array($cntrformat))
            $format2 = $cntrformat;
         else {
            reset ($cntrformat);
            list ($opercode, $format2) = each($cntrformat);
         }
         $hasZeroPrefix = substr($format2,0,1)=='0';
         if ($hasZeroPrefix) $mid = '0'.$mid;

         $midinfo = array(
            'mid' => $mid,    // NB: use miscutil to check mid validity
            'cntr' => $cntr,
            );
         return $midinfo;
      }

      if (isset($cf_midformat[$usercntr])) {
         // first check mid against friend's country
         if ($midinfo = mid_matchcntr ($mid, $usercntr, $cf_midformat[$usercntr])) {

            if ($msisdn = mid_msisdn($midinfo['mid'], $midinfo['cntr']))
               $midinfo['msisdn'] = $msisdn;

            return $midinfo;
         }
      }
   }

   if (!$isFound) {
      foreach ($cf_midformat as $i_cntr=>$i_format) {    // iterate all country formats
         if ($midinfo = mid_matchcntr ($mid, $i_cntr, $i_format)) {
            if (empty($midinfo)) return NULL;

            if ($midinfo['cntr']=='unknown') {  // not matching known formats
               if ($usercntr && !has_cntrformat($usercntr))    // friend's country format is also unknown
                  $midinfo['cntr'] = $usercntr;     // assume friend's country
            }

            if (empty($midinfo['msisdn'])) {
               if ($msisdn = mid_msisdn($midinfo['mid'], $midinfo['cntr']))
                  $midinfo['msisdn'] = $msisdn;
            }

            return $midinfo;    // Note: can be match but cntr 'unknown'
         }
      }
   }

   return NULL;   // invalid mid
}


function mid_msisdn ($mid, $cntr) {
   global $cf_midformat;

   if (!$mid || !$cntr || $cntr=='unknown') return NULL;

   $prefix = isset($cf_midformat[$cntr]['prefix']) ? trim($cf_midformat[$cntr]['prefix']) : '';
   if ($prefix) {
      if (strcasecmp($cntr,'th')==0 && preg_match('%^[0]{0,1}([12345679]\d{7})$%', $mid, $matches))
         return '+'.$prefix.'8'.$matches[1];    // interim provision for Thailand
      if (preg_match('%^[0]{0,1}([\d]+)$%', $mid, $matches))
         return '+'.$prefix.$matches[1];
   }
   return '';
}


function mid_matchcntr ($mid, $cntr, $formatarr) {
   global $cf_debug;
   global $cf_utldir;
   global $regionarr;

   if (!isset($formatarr) || !is_array($formatarr)) return NULL;   // invalid

   $defFormat = "0{0,1}[1-9]\d{5,11}";
   $prefix = isset($formatarr['prefix']) ? $formatarr['prefix'] : '';
   $fixlen = isset($formatarr['fixlen']) ? $formatarr['fixlen'] : 0;
   $format = isset($formatarr['format']) ? $formatarr['format'] : '';    // optional - may not be present

   $midinfo = array();
   if (!isset($regionarr) || !is_array($regionarr)) $regionarr = array();  // initialize

   if ($cf_debug) echo ("<PRE>");
   if ($cf_debug) echo ("mid_matchcntr: prefix=$prefix; fixlen=$fixlen; format=$format; mid=$mid\n");
   if (!empty($format) && is_array($format)) {  // can differentiate operators
      foreach ($format as $opercode=>$format2) {
         $pattern = "(?:\+{0,1}".$prefix."){0,1}(".$format2.")";
         if ($cf_debug) echo ("$cntr> $opercode : $pattern...");
         if (preg_match("%^".$pattern."$%", $mid, $matcharr)) {
            $midinfo['mid'] = $matcharr[1];
            $midinfo['cntr'] = $cntr;
            $midinfo['opercode'] = $opercode;

            $hasZeroPrefix = substr($format2,0,1)=='0';
            if ($fixlen) {  // mid must match a fixed length
               if (strlen($midinfo['mid']) < $fixlen && $hasZeroPrefix)
                  $midinfo['mid'] = '0'.$midinfo['mid'];
               if (strlen($midinfo['mid']) != $fixlen) continue;
            }
            else {
               if ($hasZeroPrefix && substr($midinfo['mid'],0,1)!='0')
                  $midinfo['mid'] = '0'.$midinfo['mid'];
               else if (!$hasZeroPrefix && substr($midinfo['mid'],0,1)=='0')
                  $midinfo['mid'] = substr($midinfo['mid'],1);
            }
            if ($cf_debug) echo ("match".($hasZeroPrefix ? ' (hasZeroPrefix)':'')."\n");
            return $midinfo;
         }
         if ($cf_debug) echo ("\n");
      }
   }
   else {
      $isUnknownFormat = empty($format);
      $region = isset($formatarr['region']) ? strtolower($formatarr['region']) : '';
      if (strcasecmp($cntr,'unknown')==0) {
         $region = NULL;
         $pattern = "(?:\+(?:".$prefix.")){0,1}(".$format.")";
      }
      else if (!$isUnknownFormat)
         $pattern = "(?:\+{0,1}".$prefix."){0,1}(".$format.")";
      else
         $pattern = "(?:\+{0,1}".$prefix.")(".$defFormat.")";     // prefix becomes compulsory

      if ($cf_debug) echo ("$cntr> $pattern...");
      if (preg_match("%^".$pattern."$%", $mid, $matcharr)) {
         if (!is_null($region) && !$region && preg_match('%^([a-z][a-z\d]{2,})$%i', $cntr, $matches))    // may be a region eg. NANP
            $region = strtolower($matches[1]);
         if (!empty($region)) {
            if (in_array($region, $regionarr)) {   // already checked
               if ($cf_debug) echo ("skip\n");
               return NULL;
            }
            $regionarr[] = $region;
            $extfile = 'midutil_'.$region.'.php';
            if ($cf_debug) echo ("checking region $region\n");
            @include_once($cf_utldir.$extfile);
            if (function_exists("mid_match_".$region)) {
               $midinfo = call_user_func("mid_match_".$region, $mid);
               return (!empty($midinfo) && !empty($midinfo['mid'])) ? $midinfo : NULL;
            }
         }

         $midinfo['mid'] = $matcharr[1];
         $midinfo['cntr'] = $cntr;

         $hasZeroPrefix = empty($format) || (substr($format,0,1)=='0');
         if ($fixlen) {  // mid must match a fixed length
            if (strlen($midinfo['mid']) < $fixlen && $hasZeroPrefix)
               $midinfo['mid'] = '0'.$midinfo['mid'];
            if (strlen($midinfo['mid']) != $fixlen) return NULL;
         }
         else {
            if ($hasZeroPrefix && substr($midinfo['mid'],0,1)!='0')
               $midinfo['mid'] = '0'.$midinfo['mid'];
            else if (!$hasZeroPrefix && substr($midinfo['mid'],0,1)=='0')
               $midinfo['mid'] = substr($midinfo['mid'],1);
         }
         if ($cf_debug) echo ("match".($hasZeroPrefix ? ' (hasZeroPrefix)':'')."\n");
         return $midinfo;
      }
      if ($cf_debug) echo ("\n");
   }
   if ($cf_debug) echo ("</PRE>");

   return NULL;
}

?>

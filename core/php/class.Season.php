    <?php
    /*
      Initial algorithm
       Author: G. SATRE
       Date: 1999-10-26
       (c) Copyright Institut de Mécanique Céleste - Bureau des longitudes - Observatoire de Paris
      Adapted from JavaScript to PHP
       Author: Diving91
       Date: 2015-08-15

      Equinoxe & Solstice dates can be checked here
      http://www.imcce.fr/fr/grandpublic/temps/saisons.php
      http://www.faaq.org/ephemerides/saisons/
    */

    /* Usage
       Season(): Default to Actual date and year
       Season("YYYY"): Default to Actual date and Specified year
             This class invocation type is only meaningful to be used with ->getDate($phase) method
       Season("YYYY-mm-dd"): Default to Specified date and year
       ->getDate($phase) -> For a given year, return date of specified phase for desired Equinoxes & Solstices: 1= March, 2= June, 3= September, 4= December
       ->getPhase -> For a given date, return Season Phase is Equinoxes & Solstices: 1= March, 2= June, 3= September, 4= December
                This method is mainly useful is you want to translate Season name, otherwise getSeason return English season's names
       ->getSeason() -> For a given date, return Season name in nothern hemisphere
       ->getSeason($hemisphere) -> For a given date, return Season name in Specified hemisphere
       ->getNextSeason() & getNextSeason($hemisphere) -> Same as previous for coming season
       ->getNextSeasonNbDays() -> For a given date, return remaining days before next season
    */
    class Season {
       private $_year;
       private $_date;

       // Équinoxes et solstices 2024-2040 (UTC, à la minute ; Meeus ch. 27 recoupé avec les éphémérides officielles).
       // L'algorithme ci-dessous, précis à quelques minutes seulement, sert de repli hors table.
       private static $_official = array(
        2024 => array(1 => '2024-03-20 03:07', 2 => '2024-06-20 20:51', 3 => '2024-09-22 12:44', 4 => '2024-12-21 09:20'),
        2025 => array(1 => '2025-03-20 09:02', 2 => '2025-06-21 02:42', 3 => '2025-09-22 18:20', 4 => '2025-12-21 15:03'),
        2026 => array(1 => '2026-03-20 14:46', 2 => '2026-06-21 08:25', 3 => '2026-09-23 00:06', 4 => '2026-12-21 20:50'),
        2027 => array(1 => '2027-03-20 20:25', 2 => '2027-06-21 14:11', 3 => '2027-09-23 06:01', 4 => '2027-12-22 02:42'),
        2028 => array(1 => '2028-03-20 02:17', 2 => '2028-06-20 20:02', 3 => '2028-09-22 11:45', 4 => '2028-12-21 08:20'),
        2029 => array(1 => '2029-03-20 08:02', 2 => '2029-06-21 01:48', 3 => '2029-09-22 17:38', 4 => '2029-12-21 14:14'),
        2030 => array(1 => '2030-03-20 13:52', 2 => '2030-06-21 07:31', 3 => '2030-09-22 23:27', 4 => '2030-12-21 20:10'),
        2031 => array(1 => '2031-03-20 19:41', 2 => '2031-06-21 13:17', 3 => '2031-09-23 05:15', 4 => '2031-12-22 01:56'),
        2032 => array(1 => '2032-03-20 01:22', 2 => '2032-06-20 19:09', 3 => '2032-09-22 11:11', 4 => '2032-12-21 07:56'),
        2033 => array(1 => '2033-03-20 07:23', 2 => '2033-06-21 01:01', 3 => '2033-09-22 16:52', 4 => '2033-12-21 13:46'),
        2034 => array(1 => '2034-03-20 13:18', 2 => '2034-06-21 06:44', 3 => '2034-09-22 22:40', 4 => '2034-12-21 19:34'),
        2035 => array(1 => '2035-03-20 19:03', 2 => '2035-06-21 12:33', 3 => '2035-09-23 04:39', 4 => '2035-12-22 01:31'),
        2036 => array(1 => '2036-03-20 01:03', 2 => '2036-06-20 18:32', 3 => '2036-09-22 10:24', 4 => '2036-12-21 07:13'),
        2037 => array(1 => '2037-03-20 06:50', 2 => '2037-06-21 00:22', 3 => '2037-09-22 16:13', 4 => '2037-12-21 13:08'),
        2038 => array(1 => '2038-03-20 12:41', 2 => '2038-06-21 06:10', 3 => '2038-09-22 22:03', 4 => '2038-12-21 19:02'),
        2039 => array(1 => '2039-03-20 18:32', 2 => '2039-06-21 11:57', 3 => '2039-09-23 03:49', 4 => '2039-12-22 00:41'),
        2040 => array(1 => '2040-03-20 00:12', 2 => '2040-06-20 17:47', 3 => '2040-09-22 09:45', 4 => '2040-12-21 06:33'),
       );

       public function __construct($in = null) {
          if ($in == null) {
             $this->_year = date("Y");
             $this->_date = date("Y-m-d");
          }
          elseif (strlen($in) == 4) {
             $this->_year = $in;
             $this->_date = date("Y-m-d");
          }
          elseif (strlen($in) == 10) {
             $this->_year = substr($in,0,4);
             $this->_date = $in;
          }
          else { // wrong $in parameter -> Default to Actual date and year
             $this->_year = date("Y");
             $this->_date = date("Y-m-d");
          }
          //debug echo $this->_year.' - '.$this->_date."</br>";
       }

       private function trunc($x) {
          if ($x>0.0) return floor($x);
          else return ceil($x);
       }

       // (c) Copyright Institut de Mécanique Céleste - Bureau des longitudes - Observatoire de Paris
       private function jjdate($x, $accuracy=1) {
          $z1=$x+0.5;
          $z=$this->trunc($z1);
          $alpha=$this->trunc(($z-1867216.25)/36524.25);
          $a=$z+1+$alpha-$this->trunc($alpha/4);
          $b=$a+1524;
          $c=$this->trunc(($b-122.1)/365.25);
          $d=$this->trunc(365.25*$c);
          $e=$this->trunc(($b-$d)/30.6001);
          $day=$this->trunc($b-$d-$this->trunc(30.6001*$e));
          if($e<13.5)
             $month = $this->trunc($e-1);
          else
             $month = $this->trunc($e-13);
          if($month>=3)
             $year = $this->trunc($c-4716);
          else
             $year = $this->trunc($c-4715);
          $hour = floor(($x+0.5 - floor($x+0.5))*24);
          $minutes = floor($hour/24*60);
          if ($accuracy==1)
             return sprintf("%04d-",$year).sprintf("%02d-",$month).sprintf("%02d ",$day).sprintf("%02d:",$hour).sprintf("%02d",$minutes);
          else
             return sprintf("%04d-",$year).sprintf("%02d-",$month).sprintf("%02d",$day);
        }

       // (c) Copyright Institut de Mécanique Céleste - Bureau des longitudes - Observatoire de Paris
       // $phase is Equinoxes & Solstices: 1= March, 2= June, 3= September, 4= December
       public function getDate($phase) {
          if (isset(self::$_official[(int) $this->_year][$phase])) {
             $utc = date_create(self::$_official[(int) $this->_year][$phase] . ' UTC');
             return date('Y-m-d', $utc->getTimestamp()); // date locale (fuseau de la box)
          }
          $k = $this->_year - 2000 - 1;
          $dk = $k + 0.25 * (3 + $phase);
          $t = 0.21451814 + 0.99997862442 * $dk;
          $t+= 0.00642125 * sin(1.580244 + 0.0001621008 * $dk) + 0.0031065 * sin(4.143931 + 6.2829005032 * $dk);
          $t+= 0.00190024 * sin(5.604775 + 6.2829478479 * $dk) + 0.00178801 * sin(3.987335 + 6.2828291282 * $dk);
          $t+= 0.00004981 * sin(1.507976 + 6.283109952 * $dk) + 0.00006264 * sin(5.723365 + 6.283062603 * $dk);
          $t+= 0.00006262 * sin(5.702396 + 6.2827383999 * $dk) + 0.00003833 * sin(7.166906 + 6.2827857489 * $dk);
          $t+= 0.00003616 * sin(5.58175 + 6.2829912245 * $dk) + 0.00003597 * sin(5.591081 + 6.2826670315 * $dk);
          $t+= 0.00003744 * sin(4.3918 + 12.5657883 * $dk) + 0.00001827 * sin(8.3129 + 12.56582984 * $dk);
          $t+= 0.00003482 * sin(8.1219 + 12.56572963 * $dk) - 0.00001327 * sin(-2.1076 + 0.33756278 * $dk);
          $t-= 0.00000557 * sin(5.549 + 5.753262 * $dk) + 0.00000537 * sin(1.255 + 0.003393 * $dk);
          $t+= 0.00000486 * sin(19.268 + 77.7121103 * $dk) - 0.00000426 * sin(7.675 + 7.8602511 * $dk);
          $t-= 0.00000385 * sin(2.911 + 0.0005412 * $dk) - 0.00000372 * sin(2.266 + 3.9301258 * $dk);
          $t-= 0.0000021 * sin(4.785 + 11.5065238 * $dk) + 0.0000019 * sin(6.158 + 1.5774 * $dk);
          $t+= 0.00000204 * sin(0.582 + 0.5296557 * $dk) - 0.00000157 * sin(1.782 + 5.8848012 * $dk);
          $t+= 0.00000137 * sin(-4.265 + 0.3980615 * $dk) - 0.00000124 * sin(3.871 + 5.2236573 * $dk);
          $t+= 0.00000119 * sin(2.145 + 5.5075293 * $dk) + 0.00000144 * sin(0.476 + 0.0261074 * $dk);
          $t+= 0.00000038 * sin(6.45 + 18.848689 * $dk) + 0.00000078 * sin(2.8 + 0.775638 * $dk);
          $t-= 0.00000051 * sin(3.67 + 11.790375 * $dk) + 0.00000045 * sin(-5.79 + 0.796122 * $dk);
          $t+= 0.00000024 * sin(5.61 + 0.213214 * $dk) + 0.00000043 * sin(7.39 + 10.976868 * $dk);
          $t-= 0.00000038 * sin(3.1 + 5.486739 * $dk) - 0.00000033 * sin(0.64 + 2.544339 * $dk);
          $t+= 0.00000033 * sin(-4.78 + 5.573024 * $dk) - 0.00000032 * sin(5.33 + 6.069644 * $dk);
          $t-= 0.00000021 * sin(2.65 + 0.020781 * $dk) - 0.00000021 * sin(5.61 + 2.9424 * $dk);
          $t+= 0.00000019 * sin(-0.93 + 0.000799 * $dk) - 0.00000016 * sin(3.22 + 4.694014 * $dk);
          $t+= 0.00000016 * sin(-3.59 + 0.006829 * $dk) - 0.00000016 * sin(1.96 + 2.146279 * $dk);
          $t-= 0.00000016 * sin(5.92 + 15.720504 * $dk) + 0.00000115 * sin(23.671 + 83.9950108 * $dk);
          $t+= 0.00000115 * Sin(17.845 + 71.4292098 * $dk);

          $jjd = 2451545 + $t * 365.25;
          $d = $this->_year / 100;
          $tetuj = (32.23 * ($d - 18.3) * ($d - 18.3) - 15) / 86400;
          $jjd = $jjd - $tetuj;
          $jjd = $jjd + 0.0003472222;
          return $this->jjdate($jjd,0);
       }

       public function getPhase() {
          //echo $this->_date." = ";
          $p1 = $this->getDate(1);
          $p2 = $this->getDate(2);
          $p3 = $this->getDate(3);
          $p4 = $this->getDate(4);
          if (($this->_date < $p1) || ($this->_date >= $p4)) return 4;
          elseif ($this->_date < $p2) return 1;
          elseif ($this->_date < $p3) return 2;
          elseif ($this->_date < $p4) return 3;
       }

       public function getSeason($hemisphere = "nothern") {
          if ($hemisphere == "nothern")
             $season_names = array('Spring', 'Summer', 'Fall', 'Winter');
          else
             $season_names = array('Fall', 'Winter', 'Spring', 'Summer');
          return $season_names[$this->getPhase() - 1];
       }

       public function getNextSeason($hemisphere = "nothern") {
          if ($hemisphere == "nothern")
             $season_names = array('', 'Summer', 'Fall', 'Winter', 'Spring');
          else
             $season_names = array('', 'Winter', 'Spring', 'Summer', 'Fall');
          return $season_names[$this->getPhase()];
       }

        public function getSeasonNbDays() {
          $p = $this->getPhase();
          $d = $this->getDate($p); // Date of Phase start
          if ($d > $this->_date) { //we are before March's phase 1
             $x = new Season(substr($this->_date,0,4)-1);
             $d = $x->getDate(4);  // getdate for phase 4 last year
          }
          $d2 = date('d-m-Y',strtotime($d));
          $d1 = date('d-m-Y',strtotime($this->_date));
          $delta = round((strtotime($d2) - strtotime($d1))/86400);
          return -$delta;
          //echo $this->_date." to ".$d." = ".$delta;
       }

       public function getNextSeasonNbDays() {
          $p = $this->getPhase();
          $d = $this->getDate($p%4+1); // Date of next phase
          if ($d < $this->_date) { //we are in december
             $x = new Season(substr($this->_date,0,4)+1);
             $d = $x->getDate(1);  // getdate for phase 1 next year
          }
          $d2 = date('d-m-Y',strtotime($d));
          $d1 = date('d-m-Y',strtotime($this->_date));
          $delta = round((strtotime($d2) - strtotime($d1))/86400);
          return $delta;
          //echo $this->_date." to ".$d." = ".$delta;
       }

       public function getTest() {
          echo "Today ".$this->_date." we are in ".$this->getSeason().". It remains ".$this->getNextSeasonNbDays()."days before ".$this->getNextSeason()."</br>";
       }
    }

    ?>

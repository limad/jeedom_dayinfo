<?php

/* This file is part of Jeedom.
*
* Jeedom is free software: you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation, either version 3 of the License, or
* (at your option) any later version.
*
* Jeedom is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
* GNU General Public License for more details.
*
* You should have received a copy of the GNU General Public License
* along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
*/

/* * ***************************Includes********************************* */
require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
if (!class_exists('ICal')) { require_once dirname(__FILE__) . '/../../core/php/class.iCalReader.php'; }
if (!class_exists('MoonPhase')) { require_once dirname(__FILE__) . '/../../core/php/class.MoonPhase.php'; }
if (!class_exists('Season')) { require_once dirname(__FILE__) . '/../../core/php/class.Season.php'; }

class dayinfo extends eqLogic {


    public static function cronDaily() {
        self::refreshAll();
    }

    public static function start() {
        self::refreshAll();
    }

    // Les événements horaires du calendrier iCal se recalculent chaque minute (les autres types restent quotidiens).
    public static function cron() {
        self::refreshAll('calendar');
    }

    // Un équipement en erreur (ex. localisation Geotrav supprimée) ne doit pas bloquer les suivants.
    private static function refreshAll($_type = null) {
        foreach (eqLogic::byType('dayinfo', true) as $dayinfo) {
            if ($_type !== null && $dayinfo->getConfiguration('type') != $_type) {
                continue;
            }
            if ($_type == 'calendar' && $dayinfo->getConfiguration('calendar') == '') {
                continue; // pas d'avertissement chaque minute : il est émis par le calcul quotidien
            }
            try {
                $dayinfo->getInformations();
            } catch (Throwable $e) {
                log::add('dayinfo', 'error', $dayinfo->getName() . ' : ' . $e->getMessage());
            }
        }
    }

    public function getInformations() {
        //call methods to compute and update
        if ($this->getConfiguration('type') == 'bankdays') {
            $this->isNotWorkable();
            $this->getDifftoNextHoliday();
        }
        if ($this->getConfiguration('type') == 'calendar') {
            $this->calendarEvent();
        }
        if ($this->getConfiguration('type') == 'holidays') {
            $this->whatHolidays();
        }
        if ($this->getConfiguration('type') == 'moon') {
            $this->whatMoon();
        }
        if ($this->getConfiguration('type') == 'various') {
            $this->isTodayWeekend();
            $this->numberDay();
            $this->whatSeason();
            $this->dayLastOccurence();
            $this->dayOccurence();
        }
        if ($this->getConfiguration('type') == 'global') {
            $this->whatMoon();
            $this->whatHolidays();
            $this->isNotWorkable();
            $this->getDifftoNextHoliday();
            $this->isTodayWeekend();
            $this->numberDay();
            $this->whatSeason();
            $this->dayLastOccurence();
            $this->dayOccurence();
        }

    }

    public static function getTypeLabels() {
        return array(
            'bankdays' => 'Jours fériés',
            'holidays' => 'Vacances scolaires',
            'moon' => 'Lune',
            'various' => 'Divers',
            'global' => 'Global',
            'calendar' => 'Calendrier iCal',
        );
    }

    public static function getDefaultGeoloc() {
        if (class_exists('geotravCmd')) {
            foreach (eqLogic::byType('geotrav') as $geoloc) {
                if ($geoloc->getConfiguration('type') == 'location') {
                    return $geoloc->getId();
                }
            }
        }
        if (config::byKey('info::postalCode') != '' && config::byKey('info::stateCode') != '') {
            return 'jeedom';
        }
        return '';
    }

    /*
    * Crée un équipement par type d'informations ($_types, ou tous les types simples)
    * s'il n'en existe pas déjà un. Retourne la liste des noms créés.
    */
    public static function createByType($_types = array()) {
        $labels = self::getTypeLabels();
        if (!is_array($_types) || count($_types) == 0) {
            $_types = array_keys($labels);
        }
        $existing = array();
        foreach (eqLogic::byType('dayinfo') as $eqLogic) {
            $existing[$eqLogic->getConfiguration('type')] = true;
        }
        $geoloc = self::getDefaultGeoloc();
        $created = array();
        foreach ($_types as $type) {
            if (!isset($labels[$type]) || isset($existing[$type])) {
                continue;
            }
            $eqLogic = new dayinfo();
            $eqLogic->setName('Infos du jour - ' . $labels[$type]);
            $eqLogic->setEqType_name('dayinfo');
            $eqLogic->setIsEnable(1);
            $eqLogic->setIsVisible(1);
            $eqLogic->setConfiguration('type', $type);
            if ($type == 'bankdays' || $type == 'holidays' || $type == 'global') {
                $eqLogic->setConfiguration('geoloc', $geoloc);
            }
            $eqLogic->save();
            $eqLogic->loadCmdFromConf($type);
            try {
                $eqLogic->getInformations();
            } catch (Throwable $e) {
                log::add('dayinfo', 'error', 'Calcul initial "' . $labels[$type] . '" : ' . $e->getMessage());
            }
            $created[] = $eqLogic->getName();
        }
        return $created;
    }

    public function postAjax() {
        $this->loadCmdFromConf($this->getConfiguration('type'));
        $this->warnOrphanCmds();
        $this->getInformations();
    }

    // Après un changement de type, les anciennes commandes restent et ne sont plus mises à jour : on les signale (sans rien supprimer).
    public function warnOrphanCmds() {
        $file = dirname(__FILE__) . '/../config/devices/' . basename((string) $this->getConfiguration('type')) . '.json';
        $conf = is_file($file) ? json_decode(file_get_contents($file), true) : null;
        if (!is_array($conf) || !isset($conf['commands'])) {
            return;
        }
        $expected = array();
        foreach ($conf['commands'] as $command) {
            $expected[] = $command['logicalId'] ?? '';
        }
        $orphans = array();
        foreach ($this->getCmd() as $cmd) {
            if (!in_array($cmd->getLogicalId(), $expected)) {
                $orphans[] = $cmd->getName();
            }
        }
        if (count($orphans) > 0) {
            log::add('dayinfo', 'warning', $this->getName() . ' : commandes qui n\'appartiennent plus au type "' . $this->getConfiguration('type') . '" et ne sont plus mises à jour (à supprimer si inutiles) : ' . implode(', ', $orphans));
        }
    }

    public function loadCmdFromConf($type) {
        $file = dirname(__FILE__) . '/../config/devices/' . basename((string) $type) . '.json';
        if (!is_file($file)) {
            log::add('dayinfo', 'warning', $this->getName() . ' : définition des commandes introuvable pour le type "' . $type . '"');
            return true;
        }
        $content = file_get_contents($file);
        $return = json_decode($content, true);
        if (!is_array($return) || !isset($return['commands'])) {
            return true;
        }
        $this->import($return);
    }

    // Vérifie qu'une localisation exploitable est choisie (Jeedom ou Geotrav) ; avertit sinon.
    public function hasLocation() {
        $geoloc = $this->getConfiguration('geoloc');
        if ($geoloc == 'jeedom') {
            return true;
        }
        if ($geoloc == '' || !class_exists('geotravCmd')) {
            log::add('dayinfo', 'warning', $this->getName() . ' : aucune localisation sélectionnée (choisir "Configuration Jeedom" dans la fiche de l\'équipement)');
            return false;
        }
        return true;
    }

    // Département depuis le code postal : 3 chiffres pour les DOM (97x), 2A/2B pour la Corse (20xxx), sinon 2 chiffres.
    public static function getDepartment($_postal) {
        $postal = (string) $_postal;
        $prefix = substr($postal, 0, 2);
        if ($prefix == '97' || $prefix == '98') {
            return substr($postal, 0, 3);
        }
        if ($prefix == '20') {
            return ((int) substr($postal, 0, 3) <= 201) ? '2A' : '2B';
        }
        return $prefix;
    }

    public function isNotWorkable(){
        if (!$this->hasLocation()) {
            return;
        }
        if ($this->getConfiguration('geoloc') == "jeedom") {
            $postal = config::byKey('info::postalCode');
            $region = self::getDepartment($postal);
            $stateCode = strtolower(config::byKey('info::stateCode'));
            if ($stateCode == 'fr') {
                $country = "france";
            } else if ($stateCode == 'be') {
                $country = "belgique";
            } else if ($stateCode == 'ch') {
                $country = "suisse";
            } else if ($stateCode == 'ca') {
                $country = "canada";
            } else {
                $country = $stateCode;
            }
          } else {
            $region = geotravCmd::byEqLogicIdAndLogicalId($this->getConfiguration('geoloc'),'location:department')->execCmd();
            $country = strtolower(geotravCmd::byEqLogicIdAndLogicalId($this->getConfiguration('geoloc'),'location:country')->execCmd());
          }
        if ($country == '') {
            log::add('dayinfo', 'warning', 'Pays non défini (Configuration Jeedom > Informations > Pays) : jours fériés non calculés');
            return;
        }
        if (!in_array($country, array('france', 'belgique', 'suisse', 'canada'))) {
            log::add('dayinfo', 'warning', 'Aucun jour férié connu pour le pays "' . $country . '" : jour férié non calculé');
            return;
        }
        $timestamp = strtotime("today");
        $year = date("Y", $timestamp);
        $holidays = dayinfo::getHolidays($country,$region,$year);
        $return = (in_array($timestamp, $holidays)) ? 1 : 0;
        $this->checkAndUpdateCmd('bankdays:bankday', $return);
        log::add('dayinfo', 'debug', 'Redday ' . $return);
    }

    /*
    * Cette fonction retourne un tableau de timestamp correspondant
    * aux jours fériés en France pour une année donnée.
    */
    public static function getHolidays($country,$region, $year=null)		{
        if ($year === null) $year = date("Y");

        $easterDate  = easter_date($year);
        $easterDay   = date("j", $easterDate);
        $easterMonth = date("n", $easterDate);
        $easterYear   = date("Y", $easterDate);

        $holidays = array();

        if ($country == "france") {
            // Dates fixes
            $holidays[] = mktime(0, 0, 0, 1,  1,  $year);  // 1er janvier
            $holidays[] = mktime(0, 0, 0, 5,  1,  $year);  // Fête du travail
            $holidays[] = mktime(0, 0, 0, 5,  8,  $year);  // Victoire des alliés 1945
            $holidays[] = mktime(0, 0, 0, 7,  14, $year);  // Fête nationale
            $holidays[] = mktime(0, 0, 0, 8,  15, $year);  // Assomption
            $holidays[] = mktime(0, 0, 0, 11, 1,  $year);  // Toussaint
            $holidays[] = mktime(0, 0, 0, 11, 11, $year);  // Armistice 1918
            $holidays[] = mktime(0, 0, 0, 12, 25, $year);  // Noel

            // Dates variables
            $holidays[] = mktime(0, 0, 0, $easterMonth, $easterDay + 1,  $easterYear); // Lundi de Paques
            $holidays[] = mktime(0, 0, 0, $easterMonth, $easterDay + 39, $easterYear); // Ascension
            $holidays[] = mktime(0, 0, 0, $easterMonth, $easterDay + 50, $easterYear); // Lundi Pentecote

            if ($region == "57" || $region == "67" || $region == "68") {
                $holidays[] = mktime(0, 0, 0, $easterMonth, $easterDay - 2,  $easterYear); // Vendredi Saint
                $holidays[] = mktime(0, 0, 0, 12, 26, $year);  // Saint Etienne
            } else if ($region == "972") {
                $holidays[] = mktime(0, 0, 0, $easterMonth, $easterDay - 2,  $easterYear); // Vendredi Saint
                $holidays[] = mktime(0, 0, 0, 5, 22, $year);  // Abolition esclavage
            } else if ($region == "971") {
                $holidays[] = mktime(0, 0, 0, 5, 27, $year);  // Abolition esclavage
                $holidays[] = mktime(0, 0, 0, $easterMonth, $easterDay - 47,  $easterYear); // Mardi Gras
                $holidays[] = mktime(0, 0, 0, $easterMonth, $easterDay - 46,  $easterYear); // Mercredi Gras
                $holidays[] = mktime(0, 0, 0, $easterMonth, $easterDay - 2,  $easterYear); // Vendredi Saint
                $holidays[] = mktime(0, 0, 0, 11, 2, $year);  // Fete des Morts
            } else if ($region == "973") {
                $holidays[] = mktime(0, 0, 0, 6, 10, $year);  // Abolition esclavage
            } else if ($region == "974") {
                $holidays[] = mktime(0, 0, 0, 12, 20, $year);  // Abolition esclavage
            }
        }
        else if ($country == "belgique")
        {
            // Dates fixes
            $holidays[] = mktime(0, 0, 0, 1,  1,  $year);  // 1er janvier
            $holidays[] = mktime(0, 0, 0, 5,  1,  $year);  // Fête du travail
            $holidays[] = mktime(0, 0, 0, 7,  21, $year);  // Fête nationale
            $holidays[] = mktime(0, 0, 0, 8,  15, $year);  // Assomption
            $holidays[] = mktime(0, 0, 0, 11, 1,  $year);  // Toussaint
            $holidays[] = mktime(0, 0, 0, 11, 11, $year);  // Armistice 1918
            $holidays[] = mktime(0, 0, 0, 12, 25, $year);  // Noel

            // Dates variables
            $holidays[] = mktime(0, 0, 0, $easterMonth, $easterDay + 1,  $easterYear); // Lundi de Paques
            $holidays[] = mktime(0, 0, 0, $easterMonth, $easterDay + 39, $easterYear); // Ascension
            $holidays[] = mktime(0, 0, 0, $easterMonth, $easterDay + 50, $easterYear); // Lundi Pentecote
        }
        else if ($country == "suisse")
        {
            // Dates fixes
            $holidays[] = mktime(0, 0, 0, 1,  1,  $year);  // 1er janvier
            $holidays[] = mktime(0, 0, 0, 8,  1, $year);  // Fête nationale
            $holidays[] = mktime(0, 0, 0, 12, 25, $year);  // Noel

            // Dates variables
            $holidays[] = mktime(0, 0, 0, $easterMonth, $easterDay + 1,  $easterYear); // Lundi de Paques
            $holidays[] = mktime(0, 0, 0, $easterMonth, $easterDay + 39, $easterYear); // Ascension
            $holidays[] = mktime(0, 0, 0, $easterMonth, $easterDay + 50, $easterYear); // Lundi Pentecote

        }
        else if ($country == "canada")
        {
            // Dates fixes
            $holidays[] = mktime(0, 0, 0, 1,  1,  $year);  // 1er janvier
            $holidays[] = strtotime("previous monday", mktime(0, 0, 0, 5, 25,  $year)); // Lundi avant la Fete de la Reine
            $holidays[] = mktime(0, 0, 0, 7,  1,  $year);  // Fête du Canada
            $holidays[] = strtotime("first monday of september", mktime(0, 0, 0, 1,  1,  $year)); // Fete du Travail
            $holidays[] = strtotime("second monday of october", mktime(0, 0, 0, 1,  1,  $year)); // Action de Grace
            $holidays[] = mktime(0, 0, 0, 12, 25, $year);  // Noel

            // Dates variables
            $holidays[] = mktime(0, 0, 0, $easterMonth, $easterDay - 2,  $easterYear); // Vendredi Saint
            $holidays[] = $easterDate;
            $holidays[] = mktime(0, 0, 0, $easterMonth, $easterDay + 1,  $easterYear); // Lundi de Paques
        }
        sort($holidays);
        return $holidays;
    }

    public static function nextHoliday($country,$region,$year=null)	{
        $now = time();
        $next_holiday = null;
        $holidays = dayinfo::getHolidays($country,$region,$year);
        foreach($holidays as $key => $holiday_timestamp) {
            if ($holiday_timestamp >= $now) {
                $next_holiday = $holiday_timestamp;
                break;
            }
        }
        return $next_holiday;
    }

    public static function getNextHoliday($country,$region)		{
        $next_holiday = dayinfo::nextHoliday($country,$region); // get first next holiday for current year
        if ($next_holiday === null) { // no holiday for current year
            $next_year = date("Y") + 1; // next year
            $next_holiday = dayinfo::nextHoliday($country,$region,$next_year); // get first next holiday for next year
        }
        return $next_holiday;
    }

    public function getDifftoNextHoliday($format="%a")		{
        if (!$this->hasLocation()) {
            return;
        }
        if ($this->getConfiguration('geoloc') == "jeedom") {
            $postal = config::byKey('info::postalCode');
            $region = self::getDepartment($postal);
            $stateCode = strtolower(config::byKey('info::stateCode'));
            if ($stateCode == 'fr') {
                $country = "france";
            } else if ($stateCode == 'be') {
                $country = "belgique";
            } else if ($stateCode == 'ch') {
                $country = "suisse";
            } else if ($stateCode == 'ca') {
                $country = "canada";
            } else {
                $country = $stateCode;
            }
          } else {
            $region = geotravCmd::byEqLogicIdAndLogicalId($this->getConfiguration('geoloc'),'location:department')->execCmd();
            $country = strtolower(geotravCmd::byEqLogicIdAndLogicalId($this->getConfiguration('geoloc'),'location:country')->execCmd());
          }
        $next_holiday = ($country == '') ? null : dayinfo::getNextHoliday($country,$region);
        if ($next_holiday === null) {
            log::add('dayinfo', 'warning', 'Aucun jour férié connu pour le pays "' . $country . '" : prochain jour férié non calculé');
            return;
        }
        $date_next_holiday = new DateTime(date('Y-m-d', $next_holiday));
        $current_date = new DateTime('today');
        $interval = $current_date->diff($date_next_holiday);
        $this->checkAndUpdateCmd('bankdays:nextdays', $interval->format($format));
        log::add('dayinfo', 'debug', 'Nredday ' . $interval->format($format));
    }

    // For the current date
    public function isTodayWeekend() {
        $currentDate = new DateTime("now");
        $weekend = ($currentDate->format('N') >= 6) ? 1 : 0;
        $this->checkAndUpdateCmd('various:weekend', $weekend);
        log::add('dayinfo', 'debug', 'Weekend ' . $weekend);
    }

    //Day of the year
    public function numberDay() {
        $daynumber = date('z')+1;
        $this->checkAndUpdateCmd('various:daynumber', $daynumber);
        log::add('dayinfo', 'debug', 'Weekend ' . $daynumber);
    }

    // Season
    public function whatSeason() {
        $season = new Season();
        $aseason = $season->getSeason();
        $nseason = $season->getNextSeasonNbDays();
        log::add('dayinfo', 'debug', 'Season ' . $aseason);
        log::add('dayinfo', 'debug', 'Next Season ' . $nseason);
        $this->checkAndUpdateCmd('various:season', $aseason);
        $this->checkAndUpdateCmd('various:nextseason', $nseason);
    }

    //occurence of day of the week in month
    public function dayOccurence() {
        $occurence = date("j")/7;
        log::add('dayinfo', 'debug', 'Occurence ' . $occurence);
        $this->checkAndUpdateCmd('various:occurence', ceil($occurence));
    }

    //is last occurence of day of the week in month
    public function dayLastOccurence() {
        $occurence = date("j")+7;
        if ($occurence > date("t")) {
            $binary = 1;
        } else {
            $binary = 0;
        }
        log::add('dayinfo', 'debug', 'Last Occurence ' . $binary);
        $this->checkAndUpdateCmd('various:lastOccurence', $binary);
    }

    // Moon
    public function whatMoon() {
        $moon = new Solaris\MoonPhase();
        $age = round($moon->age(),1); // age de la lune en jour
        $phase = round($moon->phase(),2); //0 et 1 nouvelle lune, 0,5 pleine lune
        $illumination = round($moon->illumination(),2);
        $distance = round($moon->distance(),2);
        $name = $moon->phase_name();
        log::add('dayinfo', 'debug', 'Phase Lune ' . $phase);
        log::add('dayinfo', 'debug', 'Age Lune ' . $age);
        $this->checkAndUpdateCmd('moon:phase', $phase);
        $this->checkAndUpdateCmd('moon:age', $age);
        $this->checkAndUpdateCmd('moon:illumination', $illumination);
        $this->checkAndUpdateCmd('moon:distance', $distance);
        $this->checkAndUpdateCmd('moon:name', $name);
    }

    // Vacances scolaires
    public function whatHolidays() {
        if (!$this->hasLocation()) {
            return;
        }
        if ($this->getConfiguration('geoloc') == "jeedom") {
            $stateCode = strtolower(config::byKey('info::stateCode'));
            if ($stateCode == 'fr') {
                $country = "france";
            } else if ($stateCode == 'be') {
                $country = "belgique";
            } else if ($stateCode == 'ch') {
                $country = "suisse";
            } else if ($stateCode == 'ca') {
                $country = "canada";
            } else {
                $country = $stateCode;
            }
          } else {
            $country = strtolower(geotravCmd::byEqLogicIdAndLogicalId($this->getConfiguration('geoloc'),'location:country')->execCmd());
          }
        $holiday = '0';
        $nholiday = '-';
        $nextlabel = '-';
        //build calendar ID
        if ($country == 'france') {
            if ($this->getConfiguration('geoloc') == "jeedom") {
            $postal = config::byKey('info::postalCode');
            $departement = self::getDepartment($postal);
          } else {
            $departement = geotravCmd::byEqLogicIdAndLogicalId($this->getConfiguration('geoloc'),'location:department')->execCmd();
          }
            if (strpos((string) $departement, '97') === 0) {
                log::add('dayinfo', 'error', 'Calendrier des DOM TOM non pris en charge');
                return;
            }
            $devAddr = dirname(__FILE__) . '/../../resources/academies.csv';
            $devResult = is_file($devAddr) ? fopen($devAddr, "r") : false;
            if ($devResult === false) {
                log::add('dayinfo', 'error', 'Fichier des académies illisible : ' . $devAddr);
                return;
            }
            while ( ($data = fgetcsv($devResult,1000,",","\"","\\") ) !== FALSE ) {
                if (isset($data[3]) && $data[3] == $departement) {
                    $explode = explode(' ',(string) ($data[2] ?? ''));
                    if (isset($explode[1])) {
                        $calendarid = $country . $explode[1];
                    }
                }
            }
            fclose($devResult);
        } else {
            $calendarid = $country;
        }
        if (empty($calendarid)) {
            log::add('dayinfo', 'warning', 'Calendrier de vacances introuvable (pays "' . $country . '", département "' . ($departement ?? '') . '") : vérifier Configuration Jeedom > Informations');
            return;
        }
        $icaddr = dirname(__FILE__) . '/../../resources/' . $calendarid . '.ics';
        if (!is_file($icaddr)) {
            log::add('dayinfo', 'warning', 'Pas de calendrier de vacances pour "' . $calendarid . '"');
            return;
        }
        $ical   = new ICal($icaddr);
        log::add('dayinfo', 'debug', 'Ical ' . $icaddr);

        $events = $ical->events();
        //$timestamp = strtotime("12/30/2015");
        $timestamp = strtotime("today");
        $datetoday = date_create("today");
        $diffday = 365;
        $diffend = 365;
        $finete = date_create(date('Y') . '-01-01');
        $debutete = $finete;

        foreach ($events as $event) {
            if (isset($event['DTEND'])) {
                $datehol = date_create($event['DTSTART']);
                if ($datetoday < $datehol) {
                    //calcul du début prochaines vacances
                    $diff = date_diff($datetoday, $datehol);
                    if ($diff->format('%a') < $diffday && $diff->format('%a') > 0) {
                        $diffday = $diff->format('%a');
                        $nextlabel = $event['SUMMARY'];
                    }
                }
                $datefin = date_create($event['DTEND']);
                if ($datetoday < $datefin) {
                    //calcul de la fin des prochaines vacances
                    $diff = date_diff($datetoday, $datefin);
                    if ($diff->format('%a') < $diffend && $diff->format('%a') > 0) {
                        $diffend = $diff->format('%a');
                    }
                }
                if ($datehol <= $datetoday && $datetoday < $datefin)
                {
                    $holiday = '1';
                    $nholiday = $event['SUMMARY'];
                }
            } else {
                if (strpos($event['DESCRIPTION'] ?? '','été') !== false) {
                    //post debut vacances d'été (label vacances, date supérieure et on est bien sur l'année en cours)
                    $datehol = date_create($event['DTSTART']);
                    if (date_format($datetoday,'Y') === date_format($datehol,'Y') ) {
                        $debutete = $datehol;
                        //log::add('dayinfo', 'debug', 'Debut ' . $debutete);
                    }
                }
                if (($event['DESCRIPTION'] ?? '') == "Rentrée scolaire des élèves") {
                    //post reprise (label rentrée, date supérieure)
                    $datehol = date_create($event['DTSTART']);
                    //log::add('dayinfo', 'debug', 'Fin ' . date_format($datetoday,'Y') . ' ' . date_format($datehol,'Y'));
                    if (date_format($datetoday,'Y') === date_format($datehol,'Y') ) {
                        $finete = $datehol;
                        //log::add('dayinfo', 'debug', 'Fin ' . $finete);
                    }
                }
            }
        }

        if ($datetoday < $debutete) {
            $diff = date_diff($datetoday, $debutete);
            if ($diff->format('%a') < $diffday && $diff->format('%a') > 0) {
                $diffday = $diff->format('%a');
                $nextlabel = "Vacances d'été";
            }
        }

        if ($datetoday < $finete) {
            $diff = date_diff($datetoday, $finete);
            if ($diff->format('%a') < $diffend && $diff->format('%a') > 0) {
                $diffend = $diff->format('%a');
            }
        }

        if ($debutete <= $datetoday && $datetoday < $finete)
        {
            $holiday = '1';
            $nholiday = "Vacances d'été";
        }
        log::add('dayinfo', 'debug', 'Holiday ' . $holiday);
        log::add('dayinfo', 'debug', 'Label ' . $nholiday);
        log::add('dayinfo', 'debug', 'Next Holiday ' . $diffday);
        log::add('dayinfo', 'debug', 'Next End Holiday ' . $diffend);
        $this->checkAndUpdateCmd('holidays:day', $holiday);
        $this->checkAndUpdateCmd('holidays:daylabel', $nholiday);
        $this->checkAndUpdateCmd('holidays:nextbegin', $diffday);
        $this->checkAndUpdateCmd('holidays:nextend', $diffend);
        $this->checkAndUpdateCmd('holidays:nextlabel', $nextlabel);
    }

    // Erreur récurrente (cron chaque minute) : journalisée au plus une fois par heure et par message.
    private function logThrottled($_level, $_message) {
        $key = 'dayinfo::log::' . md5($_message);
        if (cache::byKey($key)->getValue(0) == 1) {
            return;
        }
        cache::set($key, 1, 3600);
        log::add('dayinfo', $_level, $_message);
    }

    // Lit un fichier ICS local ou une URL http(s) (timeout 10 s) ; retourne les lignes, ou null si illisible/vide.
    private static function readIcs($_source) {
        if (preg_match('#^https?://#i', $_source)) {
            // Rafraîchie chaque minute : l'URL n'est interrogée qu'une fois toutes les 10 minutes
            $cacheKey = 'dayinfo::ics::' . md5($_source);
            $cached = cache::byKey($cacheKey)->getValue('');
            if ($cached !== '') {
                return explode("\n", $cached);
            }
            $context = stream_context_create(array('http' => array('timeout' => 10)));
            $content = @file_get_contents($_source, false, $context);
            if ($content !== false && trim($content) !== '') {
                cache::set($cacheKey, $content, 600);
            }
        } else {
            $content = is_file($_source) ? @file_get_contents($_source) : false;
        }
        if ($content === false || trim($content) === '') {
            return null;
        }
        return explode("\n", $content);
    }

    public function calendarEvent() {
        $eventn = '0';
        $label = '-';
        $nextlabel = '-';

        $icaddr = $this->getConfiguration('calendar');
        if ($icaddr == '') {
            log::add('dayinfo', 'warning', $this->getName() . ' : emplacement du calendrier ICS non renseigné');
            return;
        }
        $lines = self::readIcs($icaddr);
        if ($lines === null) {
            $this->logThrottled('error', $this->getName() . ' : calendrier ICS illisible ou vide (' . $icaddr . ') : valeurs non mises à jour');
            return;
        }
        $ical   = new ICal($lines);

        $events = $ical->events();
        if (!is_array($events)) {
            $this->logThrottled('error', $this->getName() . ' : aucun événement exploitable dans le calendrier ICS (' . $icaddr . ') : valeurs non mises à jour');
            return;
        }
        $datetoday = date_create("today");
        $now = date_create('now');
        $diffday = 365;
        $diffend = 365;

        foreach ($events as $event) {
            if (isset($event['DTEND'])) {
                $datehol = date_create($event['DTSTART']);
                $datefin = date_create($event['DTEND']);
                if ($datehol === false || $datefin === false) {
                    continue;
                }
                if (strlen((string) $event['DTSTART']) > 8) {
                    // Événement horaire : en cours à l'instant ; début/fin comptés en jours calendaires (0 = aujourd'hui)
                    $datehol->setTimezone($datetoday->getTimezone());
                    $datefin->setTimezone($datetoday->getTimezone());
                    if ($now < $datehol) {
                        $days = date_diff($datetoday, date_create($datehol->format('Y-m-d')))->days;
                        if ($days < $diffday) {
                            $diffday = $days;
                            $nextlabel = $event['SUMMARY'];
                        }
                    }
                    if ($now < $datefin) {
                        $days = date_diff($datetoday, date_create($datefin->format('Y-m-d')))->days;
                        if ($days < $diffend) {
                            $diffend = $days;
                        }
                    }
                    if ($datehol <= $now && $now < $datefin) {
                        $eventn = '1';
                        $label = $event['SUMMARY'];
                    }
                    continue;
                }
                if ($datetoday < $datehol) {
                    //calcul du début prochaines vacances
                    $diff = date_diff($datetoday, $datehol);
                    if ($diff->format('%a') < $diffday && $diff->format('%a') > 0) {
                        $diffday = $diff->format('%a');
                        $nextlabel = $event['SUMMARY'];
                    }
                }
                if ($datetoday < $datefin) {
                    //calcul de la fin des prochaines vacances
                    $diff = date_diff($datetoday, $datefin);
                    if ($diff->format('%a') < $diffend && $diff->format('%a') > 0) {
                        $diffend = $diff->format('%a');
                    }
                }
                if ($datehol <= $datetoday && $datetoday < $datefin)
                {
                    $eventn = '1';
                    $label = $event['SUMMARY'];
                }
            }
        }

        $this->checkAndUpdateCmd('calendar:event', $eventn);
        $this->checkAndUpdateCmd('calendar:eventlabel', $label);
        $this->checkAndUpdateCmd('calendar:nextbegin', $diffday);
        $this->checkAndUpdateCmd('calendar:nextend', $diffend);
        $this->checkAndUpdateCmd('calendar:nextlabel', $nextlabel);
    }

}

class dayinfoCmd extends cmd {
    public function execute($_options = null) {
      if ($this->getLogicalId() == 'refresh') {
        $this->getEqLogic()->getInformations();
      }
    }

}

?>

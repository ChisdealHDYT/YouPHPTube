<?php

require_once $global['systemRootPath'] . 'plugin/Plugin.abstract.php';
require_once $global['systemRootPath'] . 'plugin/Cache/Objects/CachesInDB.php';

class Cache extends PluginAbstract {

    public function getTags() {
        return array(
            PluginTags::$RECOMMENDED,
            PluginTags::$FREE
        );
    }

    public function getDescription() {
        global $global;
        $txt = "AVideo application accelerator to cache pages.<br>Your website has 10,000 visitors who are online, and your dynamic page has to send 10,000 times the same queries to database on every page load. With this plugin, your page only sends 1 query to your DB, and uses the cache to serve the 9,999 other visitors.";
        $txt .= "<br>To auto delete the old cache files you can use this crontab command <code>0 2 * * * php {$global['systemRootPath']}plugin/Cache/crontab.php</code> this will delete cache files that are 3 days old everyday at 2 AM";
        $help = "<br><small><a href='https://github.com/WWBN/AVideo/wiki/Cache-Plugin' target='__blank'><i class='fas fa-question-circle'></i> Help</a></small>";
        return $txt . $help;
    }

    public function getName() {
        return "Cache";
    }

    public function getUUID() {
        return "10573225-3807-4167-ba81-0509dd280e06";
    }

    public function getPluginVersion() {
        return "2.0";
    }

    public function getEmptyDataObject() {
        global $global;
        $obj = new stdClass();
        $obj->enableCachePerUser = false;
        $obj->enableCacheForLoggedUsers = false;
        $obj->cacheTimeInSeconds = 600;
        $obj->cacheDir = $global['systemRootPath'] . 'videos/cache/';
        $obj->logPageLoadTime = false;
        $obj->stopBotsFromNonCachedPages = false;
        $obj->deleteStatisticsDaysOld = 180; // 6 months
        return $obj;
    }

    public function getCacheDir($ignoreFirstPage = true) {
        global $global;
        $obj = $this->getDataObject();
        if (!$ignoreFirstPage && $this->isFirstPage()) {
            $obj->cacheDir .= "firstPage" . DIRECTORY_SEPARATOR;
        }
        if (User::isLogged()) {
            if (User::isAdmin()) {
                $obj->cacheDir .= 'admin_' . md5("admin" . $global['salt']) . DIRECTORY_SEPARATOR;
            } else {
                $obj->cacheDir .= 'user_' . md5("user" . $global['salt']) . DIRECTORY_SEPARATOR;
            }
        } else {
            $obj->cacheDir .= 'notlogged_' . md5("notlogged" . $global['salt']) . DIRECTORY_SEPARATOR;
        }

        $obj->cacheDir = fixPath($obj->cacheDir, true);
        if (!file_exists($obj->cacheDir)) {
            $obj->cacheDir = $global['systemRootPath'] . 'videos' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR;
            $this->setDataObject($obj);
            if (!file_exists($obj->cacheDir)) {
                mkdir($obj->cacheDir, 0777, true);
            }
        }


        return $obj->cacheDir;
    }

    private function getFileName() {
        if (empty($_SERVER['REQUEST_URI'])) {
            $_SERVER['REQUEST_URI'] = "";
        }
        $obj = $this->getDataObject();
        $session_id = "";
        if (!empty($obj->enableCachePerUser)) {
            $session_id = session_id();
        }
        $compl = "";
        if (!empty($_SERVER['HTTP_USER_AGENT']) && get_browser_name($_SERVER['HTTP_USER_AGENT']) === 'Safari') {
            $compl .= "safari_";
        }

        $dir = "";
        $plugin = AVideoPlugin::loadPluginIfEnabled('User_Location');
        if (!empty($plugin)) {
            $location = User_Location::getThisUserLocation();
            if (!empty($location['country_code'])) {
                $dir = $location['country_code'] . "/";
            }
        }

        return $dir . User::getId() . "_{$compl}" . md5(@$_SESSION['channelName'] . $_SERVER['REQUEST_URI'] . $_SERVER['HTTP_HOST']) . "_" . $session_id . "_" . (!empty($_SERVER['HTTPS']) ? 'a' : '') . (@$_SESSION['language']) . '.cache';
    }

    private function isFirstPage() {
        return isFirstPage();
    }

    public function getStart() {
        global $global;
        // ignore cache if it is command line
        //var_dump($this->isFirstPage());exit;
        $obj = $this->getDataObject();
        if ($obj->logPageLoadTime) {
            $this->start();
        }

        if (isCommandLineInterface()) {
            return true;
        }
        $whitelistedFiles = array('user.php', 'status.php', 'canWatchVideo.json.php', '/login', '/status');
        $blacklistedFiles = array('videosAndroid.json.php');
        $baseName = basename($_SERVER["SCRIPT_FILENAME"]);
        if (getVideos_id() || isVideo() || isLive() || isLiveLink() || in_array($baseName, $whitelistedFiles) || in_array($_SERVER['REQUEST_URI'], $whitelistedFiles)) {
            return true;
        }

        $isBot = isBot();
        if ($this->isBlacklisted() || $this->isFirstPage() || !class_exists('User') || !User::isLogged() || !empty($obj->enableCacheForLoggedUsers)) {
            $cacheName = 'firstPage' . DIRECTORY_SEPARATOR . $this->getFileName();
            $lifetime = $obj->cacheTimeInSeconds;
            if ($isBot && $lifetime < 3600) {
                $lifetime = 3600;
            }
            $firstPageCache = ObjectYPT::getCache($cacheName, $lifetime, true);
            if (!empty($firstPageCache) && strtolower($firstPageCache) != 'false') {
                if ($isBot && $_SERVER['REQUEST_URI'] !== '/login') {
                    //_error_log("Bot Detected, showing the cache ({$_SERVER['REQUEST_URI']}) FROM: {$_SERVER['REMOTE_ADDR']} Browser: {$_SERVER['HTTP_USER_AGENT']}");
                }
                //$c = @local_get_contents($cachefile);
                if (preg_match("/\.json\.?/", $baseName)) {
                    header('Content-Type: application/json');
                }

                if ($isBot) {
                    $firstPageCache = strip_specific_tags($firstPageCache);
                    $firstPageCache = strip_render_blocking_resources($firstPageCache);
                } else {
                    $firstPageCache = optimizeHTMLTags($firstPageCache);
                }

                echo $firstPageCache . PHP_EOL . '<!-- Cached Page Generated in ' . getScriptRunMicrotimeInSeconds() . ' Seconds -->';
                if ($obj->logPageLoadTime) {
                    $this->end("Cache");
                }
                exit;
            }
        }

        if ($isBot && !self::isREQUEST_URIWhitelisted() && $_SERVER['REMOTE_ADDR'] != '127.0.0.1') {
            if (empty($_SERVER['HTTP_USER_AGENT'])) {
                $_SERVER['HTTP_USER_AGENT'] = "";
            }
            //_error_log("Bot Detected, NOT showing the cache ({$_SERVER['REQUEST_URI']}) FROM: {$_SERVER['REMOTE_ADDR']} Browser: {$_SERVER['HTTP_USER_AGENT']}");
            if ($obj->stopBotsFromNonCachedPages) {
                _error_log("Bot stopped  ({$_SERVER['REQUEST_URI']}) FROM: {$_SERVER['REMOTE_ADDR']} Browser: {$_SERVER['HTTP_USER_AGENT']}");
                exit;
            }
        }
        //ob_start('sanitize_output');
        ob_start();
    }

    public function getEnd() {
        global $global;
        $obj = $this->getDataObject();
        echo PHP_EOL . '<!--        Page Generated in ' . getScriptRunMicrotimeInSeconds() . ' Seconds -->';
        $c = ob_get_clean();
        $c = optimizeHTMLTags($c);
        ob_start();
        echo $c;
        if (!headers_sent()) {
            header_remove('Set-Cookie');
        }
        /*
          if (!file_exists($this->getCacheDir())) {
          mkdir($this->getCacheDir(), 0777, true);
          }
         * 
         */

        if ($this->isBlacklisted() || $this->isFirstPage() || !class_exists('User') || !User::isLogged() || !empty($obj->enableCacheForLoggedUsers)) {
            $cacheName = 'firstPage' . DIRECTORY_SEPARATOR . $this->getFileName();

            $c = preg_replace('/<script id="infoForNonCachedPages">[^<]+<\/script>/', '', $c);

            $r = ObjectYPT::setCache($cacheName, $c);
            //var_dump($r);
        }
        if ($obj->logPageLoadTime) {
            $this->end();
        }
    }

    private function isREQUEST_URIWhitelisted() {
        $cacheBotWhitelist = array(
            'aVideoEncoder',
            'plugin/Live/on_',
            'plugin/YPTStorage',
            '/login',
            'restreamer.json.php',
            'plugin/API',
            '/info?version=',
            'Meet',
            '/roku.json',
            'mrss',
            '/sitemap.xml',
            'plugin/Live/verifyToken.json.php',
            'control.json.php');
        foreach ($cacheBotWhitelist as $value) {
            if (strpos($_SERVER['REQUEST_URI'], $value) !== false) {
                _error_log("Cache::isREQUEST_URIWhitelisted: ($value) is whitelisted");
                return true;
            }
        }
        return false;
    }

    private function isBlacklisted() {
        $blacklistedFiles = array('videosAndroid.json.php');
        $baseName = basename($_SERVER["SCRIPT_FILENAME"]);
        return in_array($baseName, $blacklistedFiles);
    }

    private function start() {
        global $global;
        $time = microtime();
        $time = explode(' ', $time);
        $time = $time[1] + $time[0];
        $global['cachePluginStart'] = $time;
    }

    private function end($type = "No Cache") {
        global $global;
        if (empty($global['cachePluginStart'])) {
            return false;
        }
        $time = microtime();
        $time = explode(' ', $time);
        $time = $time[1] + $time[0];
        $finish = $time;

        if (User::isLogged()) {
            $type = "User: " . User::getUserName() . " - " . $type;
        } else {
            $type = "User: Not Logged - " . $type;
        }
        $t = (floatval($finish) - floatval($global['cachePluginStart']));
        $total_time = round($t, 4);
        _error_log("Page generated in {$total_time} seconds. {$type} ({$_SERVER['REQUEST_URI']}) FROM: {$_SERVER['REMOTE_ADDR']} Browser: {$_SERVER['HTTP_USER_AGENT']}");
    }

    public function getPluginMenu() {
        global $global;
        $fileAPIName = $global['systemRootPath'] . 'plugin/Cache/pluginMenu.html';
        $content = file_get_contents($fileAPIName);
        return $content;
    }

    public function getFooterCode() {
        global $global;
        if (preg_match('/managerPlugins.php$/', $_SERVER["SCRIPT_FILENAME"])) {
            return "<script src=\"{$global['webSiteRootURL']}plugin/Cache/pluginMenu.js\"></script>";
        }
    }

    public static function getCacheMetaData(){
        global $_getCacheMetaData;
        if(!empty($_getCacheMetaData)){
            return $_getCacheMetaData;
        }
        $domain = getDomain();
        $ishttps = isset($_SERVER["HTTPS"]) ? 1 : 0;
        $user_location = 'undefined';
        if (class_exists("User_Location")) {
            $loc = User_Location::getThisUserLocation();
            if (!empty($loc) && !empty($loc['country_code']) && $loc['country_code'] != '-') {
                $user_location = $loc['country_code'];
            }
        }
        $loggedType = CachesInDB::$loggedType_NOT_LOGGED;
        if (User::isLogged()) {
            if (User::isAdmin()) {
                $loggedType = CachesInDB::$loggedType_ADMIN;
            } else {
                $loggedType = CachesInDB::$loggedType_LOGGED;
            }
        }
        $_getCacheMetaData = array('domain'=>$domain, 'ishttps'=>$ishttps, 'user_location'=>$user_location, 'loggedType'=>$loggedType);
        return $_getCacheMetaData;
    }
    
    public static function _getCache($name){
        $metadata = self::getCacheMetaData();
        return CachesInDB::_getCache($name, $metadata['domain'], $metadata['ishttps'], $metadata['user_location'], $metadata['loggedType']);
    }
    
    public static function _setCache($name, $value) {
        $metadata = self::getCacheMetaData();
        return CachesInDB::_setCache($name, $value, $metadata['domain'], $metadata['ishttps'], $metadata['user_location'], $metadata['loggedType']);
    }

    public static function getCache($name, $lifetime = 60) {
        global $_getCacheDB, $global;
        if(!empty($global['ignoreAllCache'])){
            return null;
        }
        if(!isset($_getCacheDB)){
            $_getCacheDB = array();
        }
        $index = "{$name}_{$lifetime}";
        if(empty($_getCacheDB[$index])){
            $_getCacheDB[$index] = null;
            $metadata = self::getCacheMetaData();
            $row = CachesInDB::_getCache($name, $metadata['domain'], $metadata['ishttps'], $metadata['user_location'], $metadata['loggedType']);
            if (!empty($row)) {
                $time = getTimeInTimezone(strtotime($row['modified']), $row['timezone']);
                if (!empty($lifetime) && ($time + $lifetime) < time()) { 
                    $c = new CachesInDB($row['id']);
                    $c->delete();
                }else{
                    $_getCacheDB[$index] = _json_decode($row['content']);
                }
            }
        }
        return $_getCacheDB[$index];
    }

    public static function deleteCache($name) {
        return CachesInDB::_deleteCache($name);
    }
    
    public static function deleteAllCache() {
        return CachesInDB::_deleteAllCache();
    }

}

function sanitize_output($buffer) {

    $search = array(
        '/\>[^\S ]+/s', // strip whitespaces after tags, except space
        '/[^\S ]+\</s', // strip whitespaces before tags, except space
        '/(\s)+/s', // shorten multiple whitespace sequences
        '/<!--(.|\s)*?-->/' // Remove HTML comments
    );

    $replace = array(
        '>',
        '<',
        '\\1',
        ''
    );

    $len = strlen($buffer);
    if ($len) {
        _error_log("Before Sanitize: " . strlen($buffer));
        $buffer = preg_replace($search, $replace, $buffer);
        $lenAfter = strlen($buffer);
        _error_log("After Sanitize: {$lenAfter} = " . (($len / $lenAfter) * 100) . "%");
    }
    return $buffer;
}

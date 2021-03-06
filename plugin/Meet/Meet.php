<?php

use \Firebase\JWT\JWT;

$JIBRI_INSTANCE = 0;
require_once $global['systemRootPath'] . 'objects/php-jwt/src/JWT.php';
require_once $global['systemRootPath'] . 'plugin/Plugin.abstract.php';

require_once $global['systemRootPath'] . 'plugin/Meet/Objects/Meet_schedule.php';
require_once $global['systemRootPath'] . 'plugin/Meet/Objects/Meet_schedule_has_users_groups.php';
require_once $global['systemRootPath'] . 'plugin/Meet/Objects/Meet_join_log.php';

//require_once $global['systemRootPath'] . 'objects/php-jwt/src/JWT.php';
//use \Firebase\JWT\JWT;
class Meet extends PluginAbstract {

    public function getDescription() {
        $txt = "AVideo Meet/Conference software";
        return $txt;
    }

    public function getName() {
        return "Meet";
    }

    public function getUUID() {
        return "meet225-3807-4167-ba81-0509dd280e06";
    }

    public function getPluginVersion() {
        return "1.1";
    }

    public function getEmptyDataObject() {
        global $global;
        $obj = new stdClass();
        $obj->secret = md5($global['systemRootPath'] . $global['salt'] . "meet");
        $o = new stdClass();
        $o->type = "textarea";
        $o->value = "{UserName} is inviting you to a meeting.

Topic: {topic}

Join Meeting
{meetLink}

Passcode: {password}
";
        $obj->invitation = $o;

        $o = new stdClass();
        $o->type = array('ca1.ypt.me' => "North America 1", 'eu1.ypt.me' => "Europe 1", 'custom' => "Custom Jitsi");
        $o->value = 'ca1.ypt.me';
        $obj->server = $o;

        $obj->CUSTOM_JITSI_DOMAIN = "jitsi.eu1.ypt.me";
        $obj->JWT_APP_ID = "my_jitsi_app_id";
        $obj->JWT_APP_SECRET = "my_jitsi_app_secret";
        return $obj;
    }

    static function getTokenArray($meet_schedule_id, $users_id = 0, $expirationInMinutes = 2) {
        global $config;
        $obj = AVideoPlugin::getDataObject("Meet");
        if (empty($users_id)) {
            $users_id = User::getId();
        }
        $m = new Meet_schedule($meet_schedule_id);
        $room = $m->getName();
        if (empty($users_id)) {
            $user = [];
        } else {
            $u = new User($users_id);
            $user = [
                "avatar" => $u->getPhotoDB(),
                "name" => $u->getNameIdentificationBd(),
                "email" => $u->getEmail(),
                "id" => $users_id
            ];
        }

        $jitsiPayload = [
            "context" => [
                "user" => $user,
                "group" => $config->getWebSiteTitle()
            ],
            "aud" => ($obj->server->value=='custom')?$obj->JWT_APP_ID:"avideo",
            //"iss" => "avideo",
            "iss" => ($obj->server->value=='custom')?$obj->JWT_APP_ID:"*",
            "sub" => "meet.jitsi",
            "room" => $room,
            "exp" => strtotime("+{$expirationInMinutes} min"),
            "moderator" => self::isModerator($meet_schedule_id)
        ];
        return $jitsiPayload; // HS256
    }

    static function getToken($meet_schedule_id, $users_id = 0, $expirationInMinutes = 2) {
        $m = new Meet_schedule($meet_schedule_id);
        $jitsiPayload = self::getTokenArray($meet_schedule_id);
        $key = self::getSecret();
        //var_dump($jitsiPayload, $key);

        return JWT::encode($jitsiPayload, $key); // HS256
    }

    static function getSecret() {
        $obj = AVideoPlugin::getDataObject("Meet");
        if($obj->server->value=='custom'){
            return $obj->JWT_APP_SECRET;
        }else{
            return $obj->secret;
        }
    }

    static function getMeetServer() {
        $obj = AVideoPlugin::getDataObject("Meet");
        return "https://{$obj->server->value}/";
    }

    public function getPluginMenu() {
        global $global;
        //return '<a href="plugin/Meet/View/editor.php" class="btn btn-primary btn-sm btn-xs btn-block"><i class="fa fa-edit"></i> Edit</a>';
        return '<a href="plugin/Meet/checkServers.php" class="btn btn-primary btn-sm btn-xs btn-block"><i class="fas fa-network-wired"></i> Check Servers</a>';
    }

    static function getMeetServerStatus($cache = 30) {
        global $global;
        $secret = self::getSecret();
        $meetServer = self::getMeetServer();
        if ($meetServer == "https://custom/") {
            $obj = AVideoPlugin::getDataObject("Meet");
            $json = new stdClass();
            $json->error = false;
            $json->url = $obj->CUSTOM_JITSI_DOMAIN;
            $json->isInstalled = true;
            $json->msg = $obj->CUSTOM_JITSI_DOMAIN;
            $json->host = "custom";
            $json->jibrisInfo = new stdClass();
            $json->jibrisInfo->jibris = array();
            return $json;
        }
        $name = "getMeetServerStatus{$global['webSiteRootURL']}{$secret}{$meetServer}";
        $json = new stdClass();
        $json->content = ObjectYPT::getCache($name, $cache);
        if (!empty($json->content) && !empty($json->time)) {
            $json = json_decode($json->content);
            $json->msg = "From Cache";
        } else {
            $url = $meetServer . "api/checkMeet.json.php?webSiteRootURL=" . urlencode($global['webSiteRootURL']) . "&secret=" . $secret;
            $content = url_get_contents($url);
            $json = json_decode($content);
            if (!empty($json)) {
                $json->time = time();
                $json->url = $url;
                $json->content = $content;
                if (empty($json->error) && $json->isInstalled) {
                    ObjectYPT::setCache($name, json_encode($json));
                    if (empty($json->msg)) {
                        $json->msg = "Just create Cache";
                    }
                } else {
                    if (empty($json->msg)) {
                        $json->msg = "Error did not create Cache";
                    }
                }
            } else {
                $json = new stdClass();
                $json->time = time();
                $json->error = true;
                $json->msg = "Error we could not check your server";
            }
        }
        $json->when = humanTimingAgo($json->time);
        return $json;
    }

    static function getDomain() {
        $json = self::getMeetServerStatus();
        if (empty($json) || empty($json->host) || empty($json->isInstalled)) {
            return false;
        }
        if($json->host=='custom'){
            return "custom";
        }
        $obj = AVideoPlugin::getDataObject("Meet");
        return "{$json->host}.{$obj->server->value}";
    }
    
    static function isCustomJitsi() {
        $json = self::getMeetServerStatus();
        if (empty($json) || empty($json->host) || empty($json->isInstalled)) {
            return true;
        }
        if($json->host=='custom'){
            return true;
        }
        return false;
    }

    static function validateRoomName($room) {
        return cleanURLName(ucwords($room));
    }

    static function createRoomName($topic, $users_id = 0) {
        if (empty($users_id)) {
            if (User::isLogged()) {
                $identification = User::getNameIdentification();
            }
        } else {
            $identification = User::getNameIdentificationById($users_id);
        }
        if (empty($identification)) {
            die("User could not be identified");
        }

        $roomName = $identification . "-" . $topic;

        return self::validateRoomName($roomName);
    }

    public function getHTMLMenuRight() {
        global $global;
        if (!User::isLogged()) {
            return "";
        }
        return '<li>
        <a href="' . $global['webSiteRootURL'] . 'plugin/Meet/"  class="btn btn-default navbar-btn" data-toggle="tooltip" title="' . __('Meet') . '" data-placement="bottom" >
            <i class="fas fa-comments"></i>  <span class="hidden-md hidden-sm hidden-mdx">' . __('Meet') . '</span>
        </a>
    </li>';
    }

    public static function getMeetLink($meet_schedule_id) {
        if (empty($meet_schedule_id)) {
            return false;
        }
        $ms = new Meet_schedule($meet_schedule_id);

        return $ms->getMeetLink();
    }

    static function canManageSchedule($meet_schedule_id) {
        if (empty($meet_schedule_id)) {
            return false;
        }
        $meet = new Meet_schedule($meet_schedule_id);
        if ($meet->canManageSchedule()) {
            return true;
        }
    }

    static function canJoinMeet($meet_schedule_id) {
        $obj = self::canJoinMeetWithReason($meet_schedule_id);
        return $obj->canJoin;
    }

    static function canJoinMeetWithReason($meet_schedule_id) {
        $obj = new stdClass();
        $obj->canJoin = false;
        $obj->reason = "";


        if (User::isAdmin()) {
            $obj->canJoin = true;
            $obj->reason = "Is Admin";
            return $obj;
        }
        $meet = new Meet_schedule($meet_schedule_id);
        if (User::getId() == $meet->getUsers_id()) {
            $obj->canJoin = true;
            $obj->reason = "Is the meet owner";
            return $obj;
        }
        /**
         * Public = 2
         * Logged Users Only = 1
         * Specific User Groups = 0
         * @return type
         */
        if (empty($meet->getStarts()) || strtotime($meet->getStarts()) <= time()) {
            // means public
            if ($meet->getPublic() == "2") {
                $obj->canJoin = true;
                $obj->reason = "Is public";
                return $obj;
            } else if ($meet->getPublic() == "1") {
                $obj->canJoin = User::isLogged();
                $obj->reason = $obj->canJoin ? "Is logged" : "Must be logged to be able to join";
                return $obj;
            } else {
                $obj->canJoin = self::userGroupMatch($meet_schedule_id, User::getId());
                $obj->reason = $obj->canJoin ? "The user group match" : "Must be on the usergroup to be able to join";
                return $obj;
            }
        } else {
            $obj->reason = "The meet does no start yet";
            return $obj;
        }
    }

    static function isModerator($meet_schedule_id) {
        if (empty($meet_schedule_id)) {
            return false;
        }
        if (!User::isLogged()) {
            return false;
        }
        if (User::isAdmin()) {
            return true;
        }
        $meet = new Meet_schedule($meet_schedule_id);
        if (User::getId() == $meet->getUsers_id()) {
            return true;
        }
        return false;
    }

    static function getButtons($meet_schedule_id) {
        if (self::isModerator($meet_schedule_id)) {
            if (self::hasJibris()) {
                return [
                    'microphone', 'camera', 'closedcaptions', 'desktop', 'embedmeeting', 'fullscreen',
                    'fodeviceselection', 'hangup', 'profile', 'chat',
                    'livestreaming', 'etherpad', 'settings', 'raisehand',
                    'videoquality', 'filmstrip', 'feedback', 'stats', 'shortcuts',
                    'tileview', 'download', 'help', 'mute-everyone'
                ];
            } else {
                return [
                    'microphone', 'camera', 'closedcaptions', 'desktop', 'embedmeeting', 'fullscreen',
                    'fodeviceselection', 'hangup', 'profile', 'chat',
                    'etherpad', 'settings', 'raisehand',
                    'videoquality', 'filmstrip', 'feedback', 'stats', 'shortcuts',
                    'tileview', 'download', 'help', 'mute-everyone'
                ];
            }
        } else {
            return [
                'microphone', 'camera', 'closedcaptions', 'desktop', 'embedmeeting', 'fullscreen',
                'fodeviceselection', 'hangup', 'profile', 'chat', 'etherpad', 'settings', 'raisehand',
                'videoquality', 'filmstrip', 'feedback', 'stats', 'shortcuts',
                'tileview', 'download', 'help', 'mute-everyone'
            ];
        }
    }

    static function hasJibris() {
        $serverStatus = Meet::getMeetServerStatus();
        return count($serverStatus->jibrisInfo->jibris);
    }

    static function userGroupMatch($meet_schedule_id, $users_id) {
        global $global;

        if (User::isAdmin()) {
            return true;
        }
        if (User::isLogged()) {
            require_once $global['systemRootPath'] . 'objects/userGroups.php';
            $userGroups = UserGroups::getUserGroups(User::getId());
            $meetGroups = Meet_schedule_has_users_groups::getAllFromSchedule($meet_schedule_id);
            foreach ($userGroups as $value) {
                foreach ($meetGroups as $value2) {
                    if ($value['id'] == $value2['users_groups_id']) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    static function getServer() {
        $m = AVideoPlugin::loadPlugin("Meet");
        $pObj = AVideoPlugin::getDataObject("Meet");
        $obj = $m->getEmptyDataObject();
        $obj->server->type = object_to_array($obj->server->type);
        return array("name" => $obj->server->type[$pObj->server->value], "domain" => $pObj->server->value);
    }

}

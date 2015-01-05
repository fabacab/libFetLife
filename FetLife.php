<?php
/**
 * Class to interface with FetLife.
 *
 * PHP version 5
 *
 * LICENCE: This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @author    Meitar Moscovitz <meitar@maymay.net>
 * @copyright 2012 Meitar Moscovitz
 * @license   http://www.fsf.org/licensing/licenses/agpl-3.0.html GNU Affero General Public License version 3.0
 * @link      http://maymay.net/
 */

// Uncomment for minimal debugging.
//ini_set('log_errors', true);
//ini_set('error_log', '/tmp/php_errors.log');

if (!defined('FL_SESSIONS_DIR')) {
    define('FL_SESSIONS_DIR', dirname(__FILE__) . '/fl_sessions');
}

/**
 * Base class.
 */
class FetLife {
    const base_url = 'https://fetlife.com'; // No trailing slash!
    public $isSecure; // To indicate if the password has been encrypted
}

/**
 * Handles network connections, logins, logouts, etc.
 */
class FetLifeConnection extends FetLife {
    public $usr;        // Associated FetLifeUser object.
    public $cookiejar;  // File path to cookies for this user's connection.
    public $csrf_token; // The current CSRF authenticity token to use for doing HTTP POSTs.
    public $cur_page;   // Source code of the last page retrieved.
    public $proxy_url;  // The url of the proxy to use.
    public $proxy_type; // The type of the proxy to use.

    function __construct ($usr) {
        $this->usr = $usr;
        // Initialize cookiejar (session store), etc.
        $dir = FL_SESSIONS_DIR . '/fl_sessions';
        if (!file_exists($dir)) {
            if (!mkdir($dir, 0700)) {
                die("Failed to create FetLife Sessions store directory at $dir");
            }
        }
        $this->cookiejar = "$dir/{$this->usr->nickname}";
    }

    private function scrapeProxyURL () {
        $ch = curl_init(
            'http://www.xroxy.com/proxylist.php?port=&type=Anonymous&ssl=ssl&country=&latency=&reliability=5000'
        );
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $html = curl_exec($ch);
        curl_close($ch);

        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $rows = $dom->getElementsByTagName('tr');
        $urls = array();
        foreach ($rows as $row) {
            if (0 === strpos($row->getAttribute('class'), 'row')) {
                $str = $row->getElementsByTagName('a')->item(0)->getAttribute('href');
                parse_str($str);
                $urls[] = array('host' => $host, 'port' => $port);
            }
        }
        $n = mt_rand(0, count($urls) - 1); // choose a random proxy from the scraped list
        $p = parse_url("https://{$urls[$n]['host']}:{$urls[$n]['port']}");
        return array(
            'url'  => "{$p['host']}:{$p['port']}",
            'type' => ('socks' === $p['scheme']) ? CURLPROXY_SOCKS5 : CURLPROXY_HTTP
        );
    }

    // A flag to pass to curl_setopt()'s proxy settings.
    public function setProxy ($url, $type = CURLPROXY_HTTP) {
        if ('auto' === $url) {
            $p = $this->scrapeProxyURL();
            $url = $p['url'];
            $type = $p['type'];
        }
        $this->proxy_url = $url;
        $this->proxy_type = $type;
    }

    /**
     * Log in to FetLife.
     *
     * @param object $usr A FetLifeUser to log in as.
     * @return bool True if successful, false otherwise.
     */
    public function logIn () {
        // Grab FetLife login page HTML to get CSRF token.
        $ch = curl_init(self::base_url . '/login');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        if ($this->proxy_url) {
            curl_setopt($ch, CURLOPT_PROXY, $this->proxy_url);
            curl_setopt($ch, CURLOPT_PROXYTYPE, $this->proxy_type);
        }
        $this->setCsrfToken($this->findCsrfToken(curl_exec($ch)));
        curl_close($ch);

        // Set up login credentials.
        $post_data = http_build_query(array(
            'nickname_or_email' => $this->usr->nickname,
            'password' => $this->usr->password,
            'authenticity_token' => $this->csrf_token,
            'commit' => 'Login+to+FetLife' // Emulate pushing the "Login to FetLife" button.
        ));

        // Log in to FetLife.
        return $this->doHttpPost('/session', $post_data);
    }

    /**
     * Calls doHttpRequest with the POST option set.
     */
    public function doHttpPost ($url_path, $data = '') {
        return $this->doHttpRequest($url_path, $data, 'POST');
    }

    /**
     * Calls doHttpRequest with the GET option set.
     */
    public function doHttpGet ($url_path, $data = '') {
        return $this->doHttpRequest($url_path, $data); // 'GET' is the default.
    }

    /**
     * Generic HTTP request function.
     *
     * @param string $url_path The request URI to send to FetLife. E.g., "/users/1".
     * @param string $data Parameters to send in the HTTP request. Recommended to use http_build_query().
     * @param string $method The HTTP method to use, like GET (default), POST, etc.
     * @return array $r The result of the HTTP request.
     */
    private function doHttpRequest ($url_path, $data, $method = 'GET') {
        //var_dump($this->csrf_token);
        if (!empty($data) && 'GET' === $method) {
            $url_path += "?$data";
        }
        $ch = curl_init(self::base_url . $url_path);
        if ('POST' === $method) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }
        curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cookiejar); // use session cookies
        curl_setopt($ch, CURLOPT_COOKIEJAR, $this->cookiejar); // save session cookies
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLINFO_HEADER_OUT, true);
        if ($this->proxy_url) {
            curl_setopt($ch, CURLOPT_PROXY, $this->proxy_url);
            curl_setopt($ch, CURLOPT_PROXYTYPE, $this->proxy_type);
        }

        $r = array();
        $this->cur_page = $r['body'] = curl_exec($ch); // Grab FetLife response body.
        $this->setCsrfToken($this->findCsrfToken($r['body'])); // Update on each request.
        $r['curl_info'] = curl_getinfo($ch);
        curl_close($ch);

        $this->cur_page = htmlentities($this->cur_page, ENT_COMPAT, 'UTF-8'); // for debugging - no need to keep it encoded AFAIK
        return $r;
    }

    /**
     * Given some HTML from FetLife, this finds the current user ID.
     *
     * @param string $str Some raw HTML expected to be from FetLife.com.
     * @return mixed User ID on success. False on failure.
     */
    public function findUserId ($str) {
        $matches = array();
        preg_match('/FetLife.currentUser.id          = ([0-9]+);/', $str, $matches); //Fix regex
        return $matches[1];
    }

    /**
     * Given some HTML from FetLife, this finds a user's nickname.
     *
     * @param string $str Some raw HTML expected to be from FetLife.com.
     * @return mixed User nickname on Success. False on failure.
     */
    public function findUserNickname ($str) {
        $matches = array();
        preg_match('/<title>([-_A-Za-z0-9]+) - Kinksters - FetLife<\/title>/', $str, $matches);
        return $matches[1];
    }

    /**
     * Given some HTML from FetLife, this finds the current CSRF Token.
     *
     * @param string $str Some raw HTML expected to be form FetLife.com.
     * @return mixed CSRF Token string on success. False on failure.
     */
    private function findCsrfToken ($str) {
        $matches = array();
        preg_match('/<meta name="csrf-token" content="([+a-zA-Z0-9&#;=-]+)"\/>/', $str, $matches);
        // Decode numeric HTML entities if there are any. See also:
        //     http://www.php.net/manual/en/function.html-entity-decode.php#104617
        $r = preg_replace_callback(
            '/(&#[0-9]+;)/',
            create_function(
                '$m',
                'return mb_convert_encoding($m[1], \'UTF-8\', \'HTML-ENTITIES\');'
            ),
            $matches[1]
        );
        return $r;
    }

    private function setCsrfToken ($csrf_token) {
        $this->csrf_token = $csrf_token;
    }
}

/**
 * A FetLife User. This class mimics the logged-in user, performing actions, etc.
 */
class FetLifeUser extends FetLife {
    public $nickname;
    public $password;
    public $id;
    public $email_address;
    public $connection; // A FetLifeConnection object to handle network requests.
    public $friends;    // An array (eventually, of FetLifeProfile objects).

    function __construct ($nickname, $password) {
        $this->nickname = $nickname;
        $this->password = $password;
        $this->connection = new FetLifeConnection($this);
    }

    /**
     * Logs in to FetLife as the given user.
     *
     * @return bool True if login was successful, false otherwise.
     */
    function logIn () {
        $response = $this->connection->logIn();
        if ($this->id = $this->connection->findUserId($response['body'])) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Translates a FetLife user's nickname to their numeric ID.
     */
    function getUserIdByNickname ($nickname = NULL) {
        if (!$nickname) {
            $nickname = $this->nickname;
        }

        if ($nickname === $this->nickname && !empty($this->id)) {
            return $this->id;
        } else {
            $result    = $this->connection->doHttpGet("/$nickname");
            $url_parts = parse_url($result['curl_info']['url']);
            return current(array_reverse(explode('/', $url_parts['path'])));
        }
    }

    /**
     * Translates a FetLife user's ID to their nickname.
     */
    function getUserNicknameById ($id = NULL) {
        if (isset($this->id) && !$id) {
            $id = $this->id;
        }

        $result = $this->connection->doHttpGet("/users/$id");
        return $this->connection->findUserNickname($result['body']);
    }

    /**
     * Queries FetLife for a user's profile information.
     *
     * @param mixed $who Nickname or user ID of a FetLife profile.
     * @return mixed A FetLifeProfile object on success, FALSE on failure.
     */
    function getUserProfile ($who = NULL) {
        $id = $this->resolveWho($who);
        $profile = new FetLifeProfile(array(
            'usr' => $this,
            'id' => $id
        ));
        try {
            $profile->populate();
            return $profile;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Retrieves a user's friend list.
     *
     * @param mixed $who User whose friends list to search. If a string, treats it as a FetLife nickname and resolves to a numeric ID. If an integer, uses that ID. By default, the logged-in user.
     * @param int $pages How many pages to retrieve. By default, retrieves all (0).
     * @return array $friends Array of FetLifeProfile objects.
     */
    function getFriendsOf ($who = NULL, $pages = 0) {
        $id = $this->resolveWho($who);
        return $this->getUsersInListing("/users/$id/friends", $pages);
    }

    /**
     * Helper function to resolve "$who" we're dealing with.
     *
     * @param mixed $who The entity to resolve. If a string, assumes a nickname and resolves to an ID. If an integer, uses that.
     * @return int The FetLife user's numeric ID.
     */
    private function resolveWho ($who) {
        switch (gettype($who)) {
            case 'NULL':
                return $this->id;
            case 'integer':
                return $who;
            case 'string':
                // Double-check that an integer wasn't passed a string.
                if (ctype_digit($who)) {
                    return (int)$who; // If it was, coerce type appropriately.
                } else {
                    return $this->getUserIdByNickname($who);
                }
        }
    }

    /**
     * Helper function to determine whether we've been bounced to the "Home" page.
     * This might happen if the Profile page we're trying to load doesn't exist.
     *
     * TODO: Is there a more elegant way for handling this kind of "error"?
     */
    function isHomePage ($str) {
        return (preg_match('/<title>Home - FetLife<\/title>/', $str)) ? true: false;
    }

    /**
     * Helper function to determine whether we've gotten back an HTTP error page.
     *
     * TODO: Is there a more elegant way for handling this kind of "error"?
     */
    function isHttp500ErrorPage ($str) {
        return (preg_match('/<p class="error_code">500 Internal Server Error<\/p>/', $str)) ? true: false;
    }

    /**
     * Retrieves a user's Writings.
     *
     * @param mixed $who User whose FetLife Writings to fetch. If a string, treats it as a FetLife nickname and resolves to a numeric ID. If an integer, uses that ID. By default, the logged-in user.
     * @param int $pages How many pages to retrieve. By default, retrieves all (0).
     * @return array $writings Array of FetLifeWritings objects.
     */
    public function getWritingsOf ($who = NULL, $pages = 0) {
        $id = $this->resolveWho($who);
        $items = $this->getItemsInListing('//article', "/users/$id/posts", $pages);
        $ret = array();
        foreach ($items as $v) {
            $x = array();
            $x['title']      = $v->getElementsByTagName('h2')->item(0)->nodeValue;
            $x['category']   = trim($v->getElementsByTagName('strong')->item(0)->nodeValue);
            $author_url      = $v->getElementsByTagName('a')->item(0)->attributes->getNamedItem('href')->value;
            $author_id       = (int) current(array_reverse(explode('/', $author_url)));
            $author_avatar   = $v->getElementsByTagName('img')->item(0)->attributes->getNamedItem('src')->value;
            $x['creator']     = new FetLifeProfile(array(
                'id' => $author_id,
                'avatar_url' => $author_avatar
            ));
            $x['url']        = $v->getElementsByTagName('a')->item(1)->attributes->getNamedItem('href')->value;
            $x['id']         = (int) current(array_reverse(explode('/', $x['url'])));
            $x['dt_published'] = $v->getElementsByTagName('time')->item(0)->attributes->getNamedItem('datetime')->value;
            $x['content']      = $v->getElementsByTagName('div')->item(1); // save the DOMElement object
            $x['usr']        = $this;
            $ret[] = new FetLifeWriting($x);
        }
        return $ret;
    }

    /**
     * Retrieves a user's single Writing.
     *
     * @param mixed $id ID of a FetLife Writing.
     * @param mixed $who User whose FetLife Writings to fetch. If a string, treats it as a FetLife nickname and resolves to a numeric ID. If an integer, uses that ID. By default, the logged-in user.
     * @return mixed $writing A FetLifeWriting object, false if not found.
     */
    function getWritingOf ($id, $who = NULL) {
        $author_id = $this->resolveWho($who);

        $x            = array();
        $x['creator'] = $this->getUserProfile($author_id);
        $x['id']      = $id;
        $x['usr']     = $this;
        $ret = new FetLifeWriting($x);
        $ret->populate();

        return $ret;
    }

    /**
     * Retrieves a user's Pictures.
     */
    public function getPicturesOf ($who = NULL, $pages = 0) {
        $id = $this->resolveWho($who);
        $items = $this->getItemsInListing('//ul[contains(@class, "page")]/li', "/users/$id/pictures", $pages);
        $ret = array();
        foreach ($items as $v) {
            $x = array();
            $x['url'] = $v->getElementsByTagName('a')->item(0)->attributes->getNamedItem('href')->value;
            $x['id'] = (int) current(array_reverse(explode('/', $x['url'])));
            $x['thumb_src'] = $v->getElementsByTagName('img')->item(0)->attributes->getNamedItem('src')->value;
            $x['src'] = preg_replace('/_110\.(jpg|jpeg|gif|png)$/', '_720.$1', $x['thumb_src']); // This is a good guess.
            $x['content'] = $v->getElementsByTagName('img')->item(0)->attributes->getNamedItem('alt')->value;
            $x['creator'] = new FetLifeProfile(array('id' => $id));
            $x['usr'] = $this;
            $ret[] = new FetLifePicture($x);
        }
        return $ret;
    }

    /**
     * Retrieves list of group members.
     *
     * @param int $group_id The ID of the group.
     * @param int $pages How many pages to retrieve. By default, retrieve all (0).
     * @return array $members Array of DOMElement objects from the group's "user_in_list" elements.
     */
    public function getMembersOfGroup ($group_id, $pages = 0) {
        return $this->getUsersInListing("/groups/$group_id/group_memberships", $pages);
    }

    public function getKinkstersWithFetish ($fetish_id, $pages = 0) {
        return $this->getUsersInListing("/fetishes/$fetish_id/kinksters", $pages);
    }
    public function getKinkstersGoingToEvent ($event_id, $pages = 0) {
        return $this->getUsersInListing("/events/$event_id/rsvps", $pages);
    }
    public function getKinkstersMaybeGoingToEvent ($event_id, $pages = 0) {
        return $this->getUsersInListing("/events/$event_id/rsvps/maybe", $pages);
    }
    public function getKinkstersInLocation ($loc_str, $pages = 0) {
        return $this->getUsersInListing("/administrative_areas/$loc_str/kinksters", $pages);
    }

    public function searchKinksters ($query, $pages = 0) {
        return $this->getUsersInListing('/search/kinksters', $pages, "q=$query");
    }

    /**
     * Performs a quick search of "everything" on FetLife, but only returns the first page of results.
     * To get more information, do a specific search by object type.
     *
     * @param string $query The search query.
     * @return object $results Search results by type.
     */
    public function search ($query) {
        $results = new stdClass();
        $results->kinksters = $this->getUsersInListing('/search', $pages = 1, "q=$query");
        return $results;
    }

    /**
     * Gets a single event.
     *
     * @param int $id The event ID to fetch.
     * @param mixed $populate True to populate all data, integer to retrieve that number of RSVP pages, false (default) to do nothing.
     */
    function getEventById ($id, $populate = false) {
        $event = new FetLifeEvent(array(
            'usr' => $this,
            'id' => $id,
        ));
        $event->populate($populate);
        return $event;
    }

    /**
     * Retrieves list of events.
     *
     * TODO: Create an automated way of translating place names to place URL strings.
     * @param string $loc_str The "Place" URL part. For instance, "cities/5898" is "Baltimore, Maryland, United States".
     * @param int $pages How many pages to retrieve. By default, retrieve all (0).
     */
    function getUpcomingEventsInLocation ($loc_str, $pages = 0) {
        return $this->getEventsInListing("/$loc_str/events", $pages);
    }

    /**
     * Loads a specific page from a paginated list.
     *
     * @param string $url The URL of the paginated set.
     * @param int $page The number of the page in the set.
     * @param string $qs A query string to append to the URL.
     * @return array The result of the HTTP request.
     * @see FetLifeConnection::doHttpRequest
     */
    // TODO: This should really be a bit more sensible.
    private function loadPage ($url, $page = 1, $qs = '') {
        if ($page > 1) {
            $url .= "?page=$page&";
        } else if (!empty($qs)) {
            $url .= '?';
        }
        if (!empty($qs)) {
            $url .= $qs;
        }
        $res = $this->connection->doHttpGet($url);
        return $res;
    }

    /**
     * Counts number of pages in a paginated listing.
     *
     * @param DOMDocument $doc The page to look for paginated numbering in.
     * @return int Number of pages.
     */
    private function countPaginatedPages ($doc) {
        $result = $this->doXPathQuery('//a[@class="next_page"]/../a', $doc); // get all pagination elements
        if (0 === $result->length) {
            // This is the first (and last) page.
            $num_pages = 1;
        } else {
            $num_pages = (int) $result->item($result->length - 2)->textContent;
        }
        return $num_pages;
    }

    // Helper function to return the results of an XPath query.
    public function doXPathQuery ($x, $doc) {
        $xpath = new DOMXPath($doc);
        return $xpath->query($x);
    }

    /**
     * Iterates through a listing of users, such as a friends list or group membership list.
     *
     * @param string $url_base The base URL for the listing pages.
     * @param int $pages The number of pages to iterate through.
     * @param string $qs A query string to append to the URL.
     * @return array Array of FetLifeProfile objects from the listing's "user_in_list" elements.
     */
    private function getUsersInListing ($url_base, $pages, $qs = '') {
        $items = $this->getItemsInListing('//*[contains(@class, "user_in_list")]', $url_base, $pages, $qs);
        $ret = array();
        foreach ($items as $v) {
            $u = array();
            $u['nickname'] = $v->getElementsByTagName('img')->item(0)->attributes->getNamedItem('alt')->value;
            $u['avatar_url'] = $v->getElementsByTagName('img')->item(0)->attributes->getNamedItem('src')->value;
            $u['url'] = $v->getElementsByTagName('a')->item(0)->attributes->getNamedItem('href')->value;
            $u['id'] = current(array_reverse(explode('/', $u['url'])));
            list(, $u['age'], $u['gender'], $u['role']) = $this->parseAgeGenderRole($v->getElementsByTagName('span')->item(1)->nodeValue);
            $u['location'] = trim($v->getElementsByTagName('em')->item(0)->nodeValue);
            $pieces = array_map('trim', explode(',', $u['location']));
            $u['adr']['locality'] = $pieces[0];
            $u['adr']['region'] = $pieces[1];
            $ret[] = new FetLifeProfile($u);
        }
        return $ret;
    }

    private function parseItemsInListing ($xpath, $doc) {
        $items = array();
        $entries = $this->doXPathQuery($xpath, $doc);
        foreach ($entries as $entry) {
            $items[] = $entry;
        }
        return $items;
    }

    // TODO: Perhaps these utility functions ought go in their own parser class?
    /**
     * Helper function to parse some info from a FetLife "profile_header" block.
     *
     * @param DOMDocument $doc The DOMDocument representing the page we're parsing.
     * @return FetLifeProfile A FetLifeProfile object.
     */
    public function parseProfileHeader ($doc) {
        $hdr = $doc->getElementById('profile_header');
        $el  = $hdr->getElementsByTagName('img')->item(0);
        $author_name   = $el->attributes->getNamedItem('alt')->value;
        $author_avatar = $el->attributes->getNamedItem('src')->value;
        $author_url    = $hdr->getElementsByTagName('a')->item(0)->attributes->getNamedItem('href')->value;
        $author_id     = (int) current(array_reverse(explode('/', $author_url)));
        list(, $author_age,
            $author_gender,
            $author_role) = $this->parseAgeGenderRole($this->doXPathQuery('//*[@class="age_gender_role"]', $doc)->item(0)->nodeValue);
        // substr() is used to remove the parenthesis around the location here.
        $author_location = substr($this->doXPathQuery('//*[@class="location"]', $doc)->item(0)->nodeValue, 1, -1);
        return new FetLifeProfile(array(
            'nickname' => $author_name,
            'avatar_url' => $author_avatar,
            'id' => $author_id,
            'age' => $author_age,
            'gender' => $author_gender,
            'role' => $author_role,
            'location' => $author_location
        ));
    }
    function parseAgeGenderRole ($str) {
        $m = array();
        preg_match('/^([0-9]{2})(\S+)? (\S+)?$/', $str, $m);
        return $m;
    }
    function parseIdFromUrl ($url) {
        $m = array();
        preg_match('/(\d+)$/', $url, $m);
        return ($m[1]) ? $m[1] : false;
    }
    /**
     * Helper function to parse any comments section on the page.
     *
     * @param DOMDocument $doc The DOMDocument representing the page we're parsing.
     * @return Array An Array of FetLifeComment objects.
     */
    function parseComments ($doc) {
        $ret = array();
        $comments = $doc->getElementById('comments')->getElementsByTagName('article');
        foreach ($comments as $comment) {
            $commenter_el = $comment->getElementsByTagName('a')->item(0);
            $commenter_url = $commenter_el->attributes->getNamedItem('href')->value;
            $ret[] = new FetLifeComment(array(
                'id' => (int) current(array_reverse(explode('_', $comment->getAttribute('id')))),
                'creator' => new FetLifeProfile(array(
                    'url' => $commenter_url,
                    'id' => (int) current(array_reverse(explode('/', $commenter_url))),
                    'avatar_url' => $commenter_el->getElementsByTagName('img')->item(0)->attributes->getNamedItem('src')->value,
                    'nickname' => $commenter_el->getElementsByTagName('img')->item(0)->attributes->getNamedItem('alt')->value
                )),
                'dt_published' => $comment->getElementsByTagName('time')->item(0)->attributes->getNamedItem('datetime')->value,
                'content' => $comment->getElementsByTagName('div')->item(0)
            ));
        }
        return $ret;
    }
    /**
     * Helper function to parse out info from a profile's associated content lists.
     *
     * @param DOMDocument $doc The DOMDocument representing the page we're parsing.
     * @param string $type The specific section of associated content to parse.
     * @param bool $is_leader Add to leadership/RSVP going to info, too?
     * @return Object An object with two members, item_ids and items, each arrays.
     */
    public function parseAssociatedContentInfo ($doc, $obj_type, $is_leader = false) {
        $ret = new stdClass();
        $ret->items = array();
        $ret->item_ids = array();

        switch ($obj_type) {
            case 'event':
                $str = ($is_leader) ? 'Events going to' : 'Events maybe going to';
                break;
            case 'organizing':
                $str = 'Events organizing';
                $obj_type = 'event';
                break;
            case 'writing':
                $str = 'Writing';
                break;
            case 'group':
                $str = ($is_leader) ? 'Groups I lead' : 'Groups member of';
                break;
        }
        $obj = 'FetLife' . ucfirst($obj_type);

        $els = $this->doXPathQuery("//h4[starts-with(normalize-space(.), '$str')]/following-sibling::ul[1]", $doc);
        if ($els->length) {
            foreach ($els->item(0)->getElementsByTagName('a') as $el) {
                // explode() to extract the group number from like: href="/groups/1234"
                $id = $this->parseIdFromUrl($el->getAttribute('href'));
                if ($is_leader) {
                    $ret->item_ids[] = $id;
                }
                $ret->items[] = new $obj(array(
                    'usr' => $this,
                    'id' => $id,
                    'name' => $el->firstChild->textContent
                ));
            }
        }

        return $ret;
    }

    /**
     * Iterates through a set of events from a given multi-page listing.
     *
     * @param string $url_base The base URL for the listing pages.
     * @param int $pages The number of pages to iterate through.
     * @return array Array of FetLifeEvent objects from the listed set.
     */
    private function getEventsInListing ($url_base, $pages) {
        $items = $this->getItemsInListing('//*[contains(@class, "event_listings")]/li', $url_base, $pages);
        $ret = array();
        foreach ($items as $v) {
            $e = array();
            $e['title']      = $v->getElementsByTagName('a')->item(0)->nodeValue;
            $e['url']        = $v->getElementsByTagName('a')->item(0)->attributes->getNamedItem('href')->value;
            $e['id']         = current(array_reverse(explode('/', $e['url'])));
            // Suppress this warning because we're manually appending UTC timezone marker.
            $start_timestamp = @strtotime($v->getElementsByTagName('div')->item(1)->nodeValue . ' UTC');
            $e['dtstart']    = ($start_timestamp) ?
                gmstrftime('%Y-%m-%d %H:%MZ', $start_timestamp) : $v->getElementsByTagName('div')->item(1)->nodeValue;
            $e['venue_name'] = $v->getElementsByTagName('div')->item(2)->nodeValue;
            $e['usr']        = $this;
            $ret[] = new FetLifeEvent($e);
        }

        return $ret;
    }

    /**
     * Iterates through a multi-page listing of items that match an XPath query.
     *
     * @param string $xpath An XPath string for scraping the desired HTML elements.
     * @param string $url_base The base URL of the possibly-paginated page to scrape.
     * @param int $pages The number of pages to iterate through.
     * @param string $qs A query string to append to the base URL.
     */
    private function getItemsInListing ($xpath, $url_base, $pages, $qs = '') {
        // Retrieve the first page.
        $cur_page = 1;
        $x = $this->loadPage($url_base, $cur_page, $qs);

        $doc = new DOMDocument();
        @$doc->loadHTML($x['body']);

        $num_pages = $this->countPaginatedPages($doc);
        // If retrieving all pages, set the page retrieval limit to the last existing page.
        if (0 === $pages) {
            $pages = $num_pages;
        }

        // Find and store items on this page.
        $items = $this->parseItemsInListing($xpath, $doc);

        // Find and store items on remainder of pages.
        while ( ($cur_page < $num_pages) && ($cur_page < $pages) ) {
            $cur_page++; // increment to get to next page
            $x = $this->loadPage($url_base, $cur_page, $qs);
            @$doc->loadHTML($x['body']);
            $items = array_merge($items, $this->parseItemsInListing($xpath, $doc));
        }

        return $items;
    }
}

/**
 * Base class for various content items within FetLife.
 */
abstract class FetLifeContent extends FetLife {
    public $usr; //< Associated FetLifeUser object.
    public $id;
    public $content; //< DOMElement object. Use `getContentHtml()` to get as string.
    public $dt_published;
    public $creator; //< A FetLifeProfile who created the content

    function __construct ($arr_param) {
        // TODO: Rewrite this a bit more defensively.
        foreach ($arr_param as $k => $v) {
            $this->$k = $v;
        }
    }

    function __sleep () {
        if (isset($this->content) && !empty($this->content)) {
            $this->content = $this->getContentHtml(true);
        }
        return array_keys(get_object_vars($this));
    }

    function __wakeup () {
        $html = $this->content;
        $nodes = array();
        $doc = new DOMDocument();
        @$doc->loadHTML("<html>{$html}</html>");
        $child = $doc->documentElement->firstChild;
        while($child) {
            $nodes[] = $doc->importNode($child,true);
            $child = $child->nextSibling;
        }
        $this->content = reset($nodes);
    }

    abstract public function getUrl ();

    // Return the full URL, with fragment identifier.
    // TODO: Should this become an abstract class to enforce this contract?
    //       If so, what should be done with the class variables? They'll
    //       get changed to be class constants, which may not be acceptable.
    public function getPermalink () {
        return self::base_url . $this->getUrl();
    }

    // Fetches and fills in the remainder of the object's data.
    // For this to work, child classes must define their own parseHtml() method.
    public function populate () {
        $resp = $this->usr->connection->doHttpGet($this->getUrl());
        $data = $this->parseHtml($resp['body']);
        foreach ($data as $k => $v) {
            $this->$k = $v;
        }
    }

    public function getContentHtml ($pristine = true) {
        $html = '';
        if (!empty($this->content) && $this->content instanceof DOMElement) {
            $doc = new DOMDocument();
            foreach ($this->content->childNodes as $node) {
                $el = $doc->importNode($node, true);
                $html .= $doc->saveHTML($el);
            }
        } else {
            $html = $this->content;
        }
        $html = $pristine ? $html : htmlentities($html, ENT_COMPAT, 'UTF-8');
        return $html;
    }
}

/**
 * A FetLife Writing published by a user.
 */
class FetLifeWriting extends FetLifeContent {
    public $title;
    public $category;
    public $privacy;
    public $comments; // An array of FetLifeComment objects.
    // TODO: Implement "love" fetching?
    public $loves;

    // Returns the server-relative URL of the profile.
    public function getUrl () {
        return '/users/' . $this->creator->id . '/posts/' . $this->id;
    }

    // Given some HTML of a FetLife writing page, returns an array of its data.
    function parseHtml ($html) {
        $doc = new DOMDocument();
        @$doc->loadHTML($html);
        $ret = array();

        $ret['creator'] = $this->usr->parseProfileHeader($doc);

        $ret['title'] = $doc->getElementsByTagName('h2')->item(0)->nodeValue;
        $ret['content'] = $this->usr->doXPathQuery('//*[@id="post_content"]//div', $doc)->item(1);
        $ret['category'] = trim($this->usr->doXPathQuery('//*[@id="post_content"]//header//strong', $doc)->item(0)->nodeValue);
        $ret['dt_published'] = $this->usr->doXPathQuery('//*[@id="post_content"]//time/@datetime', $doc)->item(0)->value;
        $ret['privacy'] = $this->usr->doXPathQuery('//*[@id="privacy_section"]//*[@class="display"]', $doc)->item(0)->nodeValue;

        $ret['comments'] = $this->usr->parseComments($doc);
        return $ret;
    }

    // Override parent's implementation to strip out final paragraph from
    // contents that were scraped from a Writing listing page.
    function getContentHtml ($pristine = true) {
        $html = '';
        if (!empty($this->content) && $this->content instanceof DOMElement) {
            $doc = new DOMDocument();
            foreach ($this->content->childNodes as $node) {
                $el = $doc->importNode($node, true);
                // Strip out FetLife's own "Read NUMBER comments" paragraph
                if ($el->hasAttributes() && (false !== stripos($el->attributes->getNamedItem('class')->value, 'no_underline')) ) {
                    continue;
                }
                $html .= $doc->saveHTML($el);
            }
        } else {
            $html = $this->content;
        }

        $html = $pristine ? $html : htmlentities($html, ENT_COMPAT, 'UTF-8'); 
        return $html;
    }
}

/**
 * A FetLife Picture page. (Not the <img/> itself.)
 */
class FetLifePicture extends FetLifeContent {
    public $src;       // The fully-qualified URL of the image itself.
    public $thumb_src; // The fully-qualified URL of the thumbnail.
    public $comments;

    public function getUrl () {
        return "/users/{$this->creator->id}/pictures/{$this->id}";
    }

    // Parses a FetLife Picture page's HTML.
    function parseHtml ($html) {
        $doc = new DOMDocument();
        @$doc->loadHTML($html);
        $ret = array();

        $ret['creator'] = $this->usr->parseProfileHeader($doc);

        // TODO: I guess I could look at the actual page instea of guessing?
        //$ret['src'];
        $ret['content'] = $this->usr->doXPathQuery('//span[contains(@class, "caption")]', $doc)->item(0);
        $ret['dt_published'] = $doc->getElementById('picture')->getElementsByTagName('time')->item(0)->attributes->getNamedItem('datetime')->value;

        $ret['comments'] = $this->usr->parseComments($doc);

        return $ret;
    }

}

/**
 * Generic class for comments on FetLife contents.
 */
class FetLifeComment extends FetLifeContent {
    public $creator;

    public function getUrl () {
        return parent::getUrl() . '#' . $this->getContentType() . "_comment_{$this->id}";
    }

    // Helper function to reflect on what this comment is attached to.
    private function getContentType () {
        switch ($x = get_parent_class($this)) {
            case 'FetLifeWriting':
                return 'post';
            case 'FetLifeStatus':
                return 'status';
            default:
                return $x;
        }
    }
}

/**
 * Profile information for a FetLife User.
 */
class FetLifeProfile extends FetLifeContent {
    public $age;
    public $avatar_url;
    public $gender;
    public $location; // TODO: Split this up?
    public $adr;
    public $nickname;
    public $role;
    public $relationships; // TODO
    public $orientation;
    public $paying_account;
    public $num_friends; //< Number of friends displayed on their profile.
    public $bio;         //< Whatever's in the "About Me" section
    public $websites;    //< An array of URLs listed by the profile.
    public $fetishes;    //< An array of FetLifeFetish objects, eventually

    protected $events;              //< Array of FetLifeEvent objects
    protected $events_organised;    //< Array of FetLifeEvent objects
    protected $groups;              //< Array of FetLifeGroup objects
    protected $events_going;        //< Array of event IDs for which this user RSVP'ed "going"
    protected $groups_lead;         //< Array of group IDs for which this profile is a group leader.

    function __construct ($arr_param) {
        parent::__construct($arr_param);
        unset($this->creator); // Profile can't have a creator; it IS a creator.
    }

    // Returns the server-relative URL of the profile.
    public function getUrl () {
        return '/users/' . $this->id;
    }

    /**
     * Returns the fully-qualified URL of the profile.
     *
     * @param bool $named If true, returns the canonical URL by nickname.
     */
    public function getPermalink ($named = false) {
        if ($named) {
            return self::base_url . "/{$this->nickname}";
        } else {
            return self::base_url . $this->getUrl();
        }
    }

    public function getAvatarURL ($size = 60) {
        return $this->transformAvatarURL($this->avatar_url, $size);
    }

    public function getEvents () {
        return $this->events;
    }
    public function getEventsOrganised () {
        return $this->events_organised;
    }
    public function getEventsGoingTo () {
        $r = array();
        foreach ($this->events_going as $id) {
            foreach ($this->events as $x) {
                if ($id == $x->id) {
                    $r[] = $x;
                }
            }
        }
        return $r;
    }
    public function getGroups () {
        return $this->groups;
    }
    public function getGroupsLead () {
        $r = array();
        foreach ($this->groups_lead as $id) {
            foreach ($this->groups as $x) {
                if ($id == $x->id) {
                    $r[] = $x;
                }
            }
        }
        return $r;
    }

    // Given some HTML of a FetLife Profile page, returns an array of its data.
    function parseHtml ($html) {
        // Don't try parsing if we got bounced off the Profile for any reason.
        if ($this->usr->isHomePage($html) || $this->usr->isHttp500ErrorPage($html)) {
            throw new Exception('FetLife Profile does not exist.');
        }
        $doc = new DOMDocument();
        @$doc->loadHTML($html);
        $ret = array();

        // TODO: Defensively check for HTML elements successfully scraped, this is sloppy.
        if ($el = $doc->getElementsByTagName('h2')->item(0)) {
            list(, $ret['age'], $ret['gender'], $ret['role']) = $this->usr->parseAgeGenderRole($el->getElementsByTagName('span')->item(0)->nodeValue);
        }
        if ($el = $this->usr->doXPathQuery('//*[@class="pan"]', $doc)->item(0)) {
            $ret['avatar_url'] = $el->attributes->getNamedItem('src')->value;
        }
        $el = $doc->getElementsByTagName('em')->item(0);
        $ret['location'] = $el->nodeValue;
        $els = $el->getElementsByTagName('a');
        if (3 === $els->length) {
            $ret['adr']['locality'] = $els->item(0)->nodeValue;
            $ret['adr']['region'] = $els->item(1)->nodeValue;
            $ret['adr']['country-name'] = $els->item(2)->nodeValue;
        } else if (2 === $els->length) {
            $ret['adr']['region'] = $els->item(0)->nodeValue;
            $ret['adr']['country-name'] = $els->item(1)->nodeValue;
        } else if (1 === $els->length) {
            $ret['adr']['country-name'] = $els->item(0)->nodeValue;
        }
        if ($el = $doc->getElementsByTagName('img')->item(0)) {
            $ret['nickname'] = $el->attributes->getNamedItem('alt')->value;
        }
        if ($el = $this->usr->doXPathQuery('//*[contains(@class, "donation_badge")]', $doc)->item(0)) {
            $ret['paying_account'] = $el->nodeValue;
        }
        if ($el = $doc->getElementsByTagName('h4')->item(0)) {
            if ($el_x = $el->getElementsByTagName('span')->item(0)) {
                $ret['num_friends'] = (int) str_replace(',', '', substr($el_x->nodeValue, 1, -1)); // Strip enclosing parenthesis and commas for results like "(1,057)"
            } else {
                $ret['num_friends'] = 0;
            }
        }

        // Parse out event info
        $x = $this->usr->parseAssociatedContentInfo($doc, 'event', true);
        $ret['events_going'] = $x->item_ids;
        $ret['events'] = $x->items;
        $x = $this->usr->parseAssociatedContentInfo($doc, 'event', false);
        $ret['events'] = array_merge($ret['events'], $x->items);

        //Parse out organised event info
        $x = $this->usr->parseAssociatedContentInfo($doc, 'organizing', false);
        $ret['events_organised'] = $x->items;

        // Parse out group info
        $x = $this->usr->parseAssociatedContentInfo($doc, 'group', true);
        $ret['groups_lead'] = $x->item_ids;
        $ret['groups'] = $x->items;
        $x = $this->usr->parseAssociatedContentInfo($doc, 'group', false);
        $ret['groups'] = array_merge($ret['groups'], $x->items);

        return $ret;
    }

    /**
     * Whether or not this user profile has a paid subscription to FetLife.
     */
    function isPayingAccount () {
        return ($this->paying_account) ? true : false;
    }

    /**
     * Will use regex replace to transform the resolution of the avatar_url's
     * found. i.e. From 60px to 200px. Does not guarantee Fetlife will have the
     * requested resolution however.
     */
    function transformAvatarURL ($avatar_url, $size) {
        return preg_replace('/_[0-9]+\.jpg$/', "_$size.jpg", $avatar_url);
    }
}

/**
 * A Status object.
 */
class FetLifeStatus extends FetLifeContent {
    const MAX_STATUS_LENGTH = 200; // Character count.
    public $text;
    public $url;

    // TODO
    function __construct ($str) {
        $this->text = $str;
    }

    // TODO
    public function getUrl () {
    }
}

/**
 * An Event object.
 */
class FetLifeEvent extends FetLifeContent {
    // See event creation form at https://fetlife.com/events/new
    public $title;
    public $tagline;
    public $dtstart;
    public $dtend;
    public $venue_name;    // Text of the venue name, if provided.
    public $venue_address; // Text of the venue address, if provided.
    public $adr = array(); // Array of elements matching adr microformat.
    public $cost;
    public $dress_code;
    public $description;
    public $going;      // An array of FetLifeProfile objects who are RSVP'ed "Yes."
    public $maybegoing; // An array of FetLifeProfile objects who are RSVP'ed "Maybe."

    // Returns the server-relative URL of the event.
    public function getUrl () {
        return '/events/' . $this->id;
    }

    public function getParticipants () {
        $r = array($this->creator);
        return array_merge($r, $this->going, $this->maybegoing);
    }

    /**
     * Fetches and fills the remainder of the Event's data.
     *
     * This is public because it'll take a long time and so it is recommended to
     * do so only when you need specific data.
     *
     * @param mixed $rsvp_pages Number of RSVP pages to get, if any. Default is false, which means attendee lists won't be fetched. Passing true means "all".
     */
    public function populate ($rsvp_pages = false) {
        $resp = $this->usr->connection->doHttpGet($this->getUrl());
        $data = $this->parseEventHtml($resp['body']);
        foreach ($data as $k => $v) {
            $this->$k = $v;
        }
        if ($rsvp_pages) {
            $rsvp_pages = (true === $rsvp_pages) ? 0 : $rsvp_pages; // Privately, 0 means "all".
            $this->going   = $this->usr->getKinkstersGoingToEvent($this->id, $rsvp_pages);
            $this->maybegoing = $this->usr->getKinkstersMaybeGoingToEvent($this->id, $rsvp_pages);
        }
    }

    // Given some HTML of a FetLife event page, returns an array of its data.
    private function parseEventHtml ($html) {
        $doc = new DOMDocument();
        @$doc->loadHTML($html);
        $ret = array();
        $ret['title'] = $this->usr->doXPathQuery('//h1[@itemprop="name"]', $doc)->item(0)->textContent;
        $ret['tagline'] = $this->usr->doXPathQuery('//h1[contains(@itemprop, "name")]/following-sibling::p', $doc)->item(0)->nodeValue;
        $ret['dtstart'] = $this->usr->doXPathQuery('//*[contains(@itemprop, "startDate")]/@content', $doc)->item(0)->nodeValue;
        $ret['dtend'] = $this->usr->doXPathQuery('//*[contains(@itemprop, "endDate")]/@content', $doc)->item(0)->nodeValue;
        $ret['venue_address'] = $this->usr->doXPathQuery('//th/*[text()="Location:"]/../../td/*[contains(@class, "s")]/text()[1]', $doc)->item(0)->nodeValue;
        if ($location = $this->usr->doXPathQuery('//*[contains(@itemprop, "location")]', $doc)->item(0)) {
            $ret['adr']['country-name'] = $location->getElementsByTagName('meta')->item(0)->attributes->getNamedItem('content')->value;
            $ret['adr']['region'] = $location->getElementsByTagName('meta')->item(1)->attributes->getNamedItem('content')->value;
            if ($locality = $location->getElementsByTagName('meta')->item(2)) {
                $ret['adr']['locality'] = $locality->attributes->getNamedItem('content')->value;
            }
        }
        $ret['cost'] = $this->usr->doXPathQuery('//th[text()="Cost:"]/../td', $doc)->item(0)->nodeValue;
        $ret['dress_code'] = $this->usr->doXPathQuery('//th[text()="Dress code:"]/../td', $doc)->item(0)->textContent;
        // TODO: Save an HTML representation of the description, then make a getter that returns a text-only version.
        //       See also http://www.php.net/manual/en/class.domelement.php#101243
        $ret['description'] = $this->usr->doXPathQuery('//*[contains(@class, "description")]', $doc)->item(0)->nodeValue;
        if ($creator_link = $this->usr->doXPathQuery('//h3[text()="Created by"]/following-sibling::ul//a', $doc)->item(0)) {
            $ret['creator'] = $ret['created_by'] = new FetLifeProfile(array( // both for backwards compatibility
                'url' => $creator_link->attributes->getNamedItem('href')->value,
                'id' => current(array_reverse(explode('/', $creator_link->attributes->getNamedItem('href')->value))),
                'avatar_url' => $creator_link->getElementsByTagName('img')->item(0)->attributes->getNamedItem('src')->value,
                'nickname' => $creator_link->getElementsByTagName('img')->item(0)->attributes->getNamedItem('alt')->value
            ));
        }
        return $ret;
    }
}

/**
 * A FetLife Group.
 */
class FetLifeGroup extends FetLifeContent {
    public $name;         //< Group display name, its title, etc.
    public $members;      //< An array of FetLifeProfile objects.
    public $about;        //< The group's "About & Rules" contents.
    public $num_posts;    //< The number of discussions, as reported on the about page.
    public $num_comments; //< The number of comments, as reported on the about page.
    public $discussions;  //< An array of FetLifeGroupDiscussion objects.
    public $last_touch;   //< Timestamp of the "last comment" line item, to estimate group activity.
    public $started_on;   //< Timestamp of the "started on" date, as reported on the about page.

    public function getUrl () {
        return '/groups/' . $this->id;
    }

    private function parseGroupHtml ($html) {
        // TODO
    }
}

// TODO
class FetLifeGroupDiscussion extends FetLifeContent {
    public function getUrl () {
        return parent::getUrl() . '/group_posts/' . $this->id;
    }
}

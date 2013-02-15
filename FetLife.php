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
ini_set('log_errors', true);
ini_set('error_log', '/tmp/php_errors.log');

/**
 * Base class.
 */
class FetLife {
    static $base_url = 'https://fetlife.com'; // No trailing slash!
}

/**
 * Handles network connections, logins, logouts, etc.
 */
class FetLifeConnection extends FetLife {
    var $usr;        // Associated FetLifeUser object.
    var $cookiejar;  // File path to cookies for this user's connection.
    var $csrf_token; // The current CSRF authenticity token to use for doing HTTP POSTs.
    var $cur_page;   // Source code of the last page retrieved.

    function __construct ($usr) {
        $this->usr = $usr;
        // Initialize cookiejar (session store), etc.
        $dir = dirname(__FILE__) . '/fl_sessions';
        if (!file_exists($dir)) {
            if (!mkdir($dir, 0700)) {
                die("Failed to create FetLife Sessions store directory at $dir");
            }
        } else {
            $this->cookiejar = "$dir/{$this->usr->nickname}";
        }
    }

    /**
     * Log in to FetLife.
     *
     * @param object $usr A FetLifeUser to log in as.
     * @return bool True if successful, false otherwise.
     */
    public function logIn () {
        // Grab FetLife login page HTML to get CSRF token.
        $ch = curl_init(parent::$base_url . '/login');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
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
        $ch = curl_init(parent::$base_url . $url_path);
        if ('POST' === $method) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }
        curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cookiejar); // use session cookies
        curl_setopt($ch, CURLOPT_COOKIEJAR, $this->cookiejar); // save session cookies
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLINFO_HEADER_OUT, true);

        $r = array();
        $this->cur_page = $r['body'] = curl_exec($ch); // Grab FetLife response body.
        $this->setCsrfToken($this->findCsrfToken($r['body'])); // Update on each request.
        $r['curl_info'] = curl_getinfo($ch);
        curl_close($ch);

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
        preg_match('/var currentUserId = ([0-9]+);/', $str, $matches);
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
    var $nickname;
    var $password;
    var $id;
    var $email_address;
    var $connection; // A FetLifeConnection object to handle network requests.
    var $friends;    // An array (eventually, of FetLifeUserProfile objects).

    function __construct ($nickname, $password) {
        $this->nickname = $nickname;
        $this->password = $password;
    }

    /**
     * Logs in to FetLife as the given user.
     *
     * @return bool True if login was successful, false otherwise.
     */
    function logIn () {
        $this->connection = new FetLifeConnection($this);
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
            return end(explode('/', $url_parts['path']));
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
     * Retrieves a user's friend list.
     *
     * @param int $id User ID of the user whose friends list to search. By default, the logged-in user.
     * @param int $pages How many pages to retrieve. By default, retrieves all (0).
     * @return array $friends Array of DOMElement from FetLife's "user_in_list" elements.
     */
    function getFriendsOf ($id = NULL, $pages = 0) {
        if (isset($this->id) && !$id) {
            $id = $this->id;
        }
        return $this->getUsersInListing("/users/$id/friends", $pages);
    }

    /**
     * Retrieves list of group members.
     *
     * @param int $group_id The ID of the group.
     * @param int $pages How many pages to retrieve. By default, retrieve all (0).
     * @return array $members Array of DOMElement objects from the group's "user_in_list" elements.
     */
    function getMembersOfGroup ($group_id, $pages = 0) {
        return $this->getUsersInListing("/groups/$group_id/group_memberships", $pages);
    }

    function getKinkstersWithFetish($fetish_id, $pages = 0) {
        return $this->getUsersInListing("/fetishes/$fetish_id/kinksters", $pages);
    }
    function getKinkstersGoingToEvent($event_id, $pages = 0) {
        return $this->getUsersInListing("/events/$event_id/rsvps", $pages);
    }
    function getKinkstersMaybeGoingToEvent($event_id, $pages = 0) {
        return $this->getUsersInListing("/events/$event_id/rsvps/maybe", $pages);
    }

    /**
     * Loads a specific page from a paginated list.
     *
     * @param string $url The URL of the paginated set.
     * @param int $page The number of the page in the set.
     * @return array The result of the HTTP request.
     * @see FetLifeConnection::doHttpRequest
     */
    private function loadPage ($url, $page = 1) {
        if ($page > 1) {
            $url .= "?page=$page";
        }
        return $this->connection->doHttpGet($url);
    }

    /**
     * Counts number of pages in a paginated listing.
     *
     * @param DOMDocument $doc The page to look for paginated numbering in.
     * @return int Number of pages.
     */
    private function countPaginatedPages ($doc) {
        $xpath = new DOMXPath($doc);
        $result = $xpath->query('//a[@class="next_page"]/../a'); // get all pagination elements
        if (0 === $result->length) {
            // This is the first (and last) page.
            $num_pages = 1;
        } else {
            $num_pages = (int) $result->item($result->length - 2)->textContent;
        }
        return $num_pages;
    }

    /**
     * Iterates through a listing of users, such as a friends list or group membership list.
     *
     * @param string $url_base The base URL for the listing pages.
     * @param int $pages The number of pages to iterate through.
     * @return array Array of DOMElement objects from the listing's "user_in_list" elements.
     */
    private function getUsersInListing ($url_base, $pages) {
        // Retrieve the first page.
        $cur_page = 1;
        $x = $this->loadPage($url_base, $cur_page);

        $doc = new DOMDocument();
        @$doc->loadHTML($x['body']);

        $num_pages = $this->countPaginatedPages($doc);
        // If retrieving all pages, set the page retrieval limit to the last existing page.
        if (0 === $pages) {
            $pages = $num_pages;
        }

        // Find and store users on this page.
        $users = array();
        $xpath = new DOMXPath($doc);
        $entries = $xpath->query('//*[contains(@class, "user_in_list")]');
        foreach ($entries as $entry) {
            $users[] = $entry;
        }

        // Find and store users on remainder of pages.
        while ( ($cur_page < $num_pages) && ($cur_page < $pages) ) {
            $cur_page++; // increment to get to next page
            $x = $this->loadPage($url_base, $cur_page);
            @$doc->loadHTML($x['body']);
            // Find and store friends on this page.
            $xpath = new DOMXPath($doc);
            $entries = $xpath->query('//*[contains(@class, "user_in_list")]');
            foreach ($entries as $entry) {
                $users[] = $entry;
            }
        }

        return $users;
    }

}

/**
 * Base class for various content items within FetLife.
 */
class FetLifeContent extends FetLife {
    var $published_on;
}

/**
 * Generic class for comments on FetLife contents.
 */
class FetLifeComment extends FetLifeContent {
    var $content;
    var $id;

    // Return the full URL, with fragment identifier.
    function getPermalink () {
    }
}

/**
 * Profile information for a FetLife User.
 *
 * TODO: Figure out if this should actually extend FetLifeContent instead.
 */
class FetLifeUserProfile extends FetLifeUser {
    var $avatar_url;
    var $age;
    // etc...
}

/**
 * A Status object.
 */
class FetLifeStatus extends FetLifeContent {
    const MAX_STATUS_LENGTH = 200; // Character count.
    var $text;
    var $url;

    function __construct ($str) {
        $this->text = $str;
    }
}

/**
 * An Event object.
 */
class FetLifeEvent extends FetLifeContent {
    // See event creation form at https://fetlife.com/events/new
    var $title;
    var $tagline;
    var $dt_start;
    var $dt_end;
    var $venue_name;
    var $venue_address;
    var $cost;
    var $dress_code;
    var $description;
    var $created_by; // A FetLifeUser who created the event.
    var $rsvp_yes;   // An array of FetLifeUser objects who are RSVP'ed "Yes."
    var $rsvp_maybe; // An array of FetLifeUser objects who are RSVP'ed "Maybe."
}

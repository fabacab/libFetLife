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

class FetLife {
    var $fl_nick;     // FetLife nickname for current user.
    var $fl_pw;       // FetLife password for current user.
    const FETLIFE_MAX_STATUS_LENGTH = 200; // Character count.

    function __construct ($nick_or_email, $password) {
        $this->fl_nick = $nick_or_email;
        $this->fl_pw = $password;
    }

    public function isSignedIn () {
        if (false !== $this->obtainFetLifeSession($this->nick_fl, $this->fl_pw)) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Grab a new FetLife session cookie via FetLife.com login form,
     * and saves it in the cookie jar.
     *
     * @param string $nick_or_email The nickname or email address used for FetLife.
     * @param string $password The FetLife password.
     * @return mixed FetLife user ID and current CSRF token on success, false otherwise.
     */
    private function obtainFetLifeSession ($nick_or_email, $password) {
        // Grab FetLife login page HTML to get CSRF token.
        $ch = curl_init('https://fetlife.com/login');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $fl_csrf_token = $this->findFetLifeCSRFToken(curl_exec($ch));
        curl_close($ch);

        // Set up login credentials.
        $post_data = http_build_query(array(
            'nickname_or_email' => $nick_or_email,
            'password' => $password,
            'authenticity_token' => $fl_csrf_token,
            'commit' => 'Login+to+FetLife' // Emulate pushing the "Login to FetLife" button.
        ));

        // Login to FetLife.
        $ch = curl_init('https://fetlife.com/session');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
        //curl_setopt($ch, CURLOPT_COOKIEJAR, $this->cookiejar); // save session cookies
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $fetlife_html = curl_exec($ch); // Grab FetLife HTML page.

        curl_close($ch);

        // TODO: Flesh out some of this error handling stuff.
        if (curl_errno($ch)) {
            return false; // Some kind of error with cURL.
        } else {
            $r = array();
            $r['fl_id'] = $this->findFetLifeUserId($fetlife_html);
            $r['fl_csrf_token'] = $this->findFetLifeCSRFToken($fetlife_html);
            return $r;
        }
    }

    /**
     * Given some HTML from FetLife, this finds the current user ID.
     *
     * @param string $str Some raw HTML expected to be from FetLife.com.
     * @return mixed User ID on success. False on failure.
     */
    private function findFetLifeUserId ($str) {
        $matches = array();
        preg_match('/var currentUserId = ([0-9]+);/', $str, $matches);
        return $matches[1];
    }

    /**
     * Given some HTML from FetLife, this finds the current CSRF Token.
     *
     * @param string $str Some raw HTML expected to be form FetLife.com.
     * @return mixed CSRF Token string on success. False on failure.
     */
    private function findFetLifeCSRFToken ($str) {
        $matches = array();
        preg_match('/<meta name="csrf-token" content="([+a-zA-Z0-9&#;=-]+)"\/>/', $str, $matches);
        // Decode numeric HTML entities if there are any. See also:
        //     http://www.php.net/manual/en/function.html-entity-decode.php#104617
        $r = preg_replace_callback(
            '/(&#[0-9]+;)/',
            "myConvertHtmlEntities", // see function definition, below
            $matches[1]
        );
        return $r;
    }
}

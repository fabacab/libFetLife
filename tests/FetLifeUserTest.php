<?php
class FetLifeUserTest extends PHPUnit_Framework_TestCase {

    protected static $FL;

    // TODO: Use mock/stubs instead of sharing a static $FL and
    //       making live HTTP requests?
    public static function setUpBeforeClass () {
        global $fetlife_username, $fetlife_password, $fetlife_proxyurl;
        self::$FL = new FetLifeUser($fetlife_username, $fetlife_password);
        if ('auto' === $fetlife_proxyurl) {
            self::$FL->connection->setProxy('auto');
        } else if ($fetlife_proxyurl) {
            $p = parse_url($fetlife_proxyurl);
            self::$FL->connection->setProxy(
                "{$p['host']}:{$p['port']}",
                ('socks' === $p['scheme']) ? CURLPROXY_SOCKS5 : CURLPROXY_HTTP
            );
        }
    }

    public function testFoundUserId () {
        self::$FL->logIn();
        $this->assertNotEmpty(self::$FL->id);
    }

}

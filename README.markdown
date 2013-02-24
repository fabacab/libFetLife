# libFetLife - README

`libFetLife` is a PHP class implementing a simple API that can be used to interface with the social networking website [FetLife.com](https://fetlife.com/).

## Getting started

To use `libFetLife`, include it in your project and instantiate a new `FetLifeUser` object:

    // Load FetLife API.
    require_once('libFetLife/FetLife.php');

    // Log in as a user.
    $FL = new FetLifeUser('username', 'password');
    $FL->connection->setProxy('example.proxy.com:9050', CURLPROXY_SOCKS5); // Optional.
    $FL->logIn();

    // Print some basic information about the account you're using.
    print $FL->id; // your user's numeric ID.

    // Query FetLife for information about other users.
    print $FL->getUserIdByNickname('JohnBaku'); // prints "1"
    print $FL->getUserNicknameById('1254');     // prints "maymay"

    // Get a user's friends list as an array.
    $friends = $FL->getFriendsOf('maymay');
    // A numeric FetLife user ID also works.
    $friends = $FL->getFriendsOf(1254);
    // If there are many pages, you can set a limit.
    $friends_partial = $FL->getFriendsOf('maymay', 3); // Only first 3 pages.

    // Numerous other functions also return arrays, with optional page limit.
    $members = $FL->getMembersOfGroup('11708'); // "Kink On Tap"
    $kinksters = $FL->getKinkstersWithFetish('193'); // "Corsets"
    $attendees = $FL->getKinkstersGoingToEvent('149379');
    $maybes = $FL->getKinkstersMaybeGoingToEvent('149379', 2); // Only 2 pages.

[Patches welcome](https://github.com/meitar/libFetLife/issues/new).

## Projects that use libFetLife

* [FetLife Export](https://github.com/meitar/fetlife-export/)
* [FetLife Bridge](https://github.com/meitar/fetlife-bridge/)

Are you using `libFetLife`? [Let me know](http://maybemaimed.com/seminars/#booking-inquiry).

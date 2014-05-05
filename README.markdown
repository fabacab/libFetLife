# libFetLife - README

`libFetLife` is a PHP class implementing a simple API that can be used to interface with the social networking website [FetLife.com](https://fetlife.com/).

## System requirements

To run `libFetLife`, you need PHP version 5.3.6 or greater.

## Getting started

To use `libFetLife`, include it in your project and instantiate a new `FetLifeUser` object:

    // Load FetLife API.
    require_once('libFetLife/FetLife.php');

    // Log in as a user.
    $FL = new FetLifeUser('username', 'password');
    $FL->connection->setProxy('example.proxy.com:9050', CURLPROXY_SOCKS5); // Optional.
    $FL->connection->setProxy('auto'); // or, set a new randomized proxy automatically.
    $FL->logIn();

    // Print some basic information about the account you're using.
    print $FL->id; // your user's numeric ID.

    // Query FetLife for information about other users.
    print $FL->getUserIdByNickname('JohnBaku'); // prints "1"
    print $FL->getUserNicknameById(1254);       // prints "maymay"

    // Object-oriented access to user info is available as FetLifeProfile objects.
    $profile = $FL->getUserProfile(1);          // Profile with ID 1
    $profile->nickname;                         // "JohnBaku"
    $profile->avatar_url;
    $profile->age;
    $profile->gender;
    $profile->role;
    $profile->isPayingAccount(); // true
    // etc.

    // Get a user's friends list as an array of FetLifeProfile objects.
    $friends = $FL->getFriendsOf('maymay');
    // A numeric FetLife user ID also works.
    $friends = $FL->getFriendsOf(1254);
    // If there are many pages, you can set a limit.
    $friends_partial = $FL->getFriendsOf('maymay', 3); // Only first 3 pages.

    // You can also fetch arrays of a user's FetLife data as objects this way.
    $writings = $FL->getWritingsOf('JohnBaku'); // All of JohnBaku's Writings.
    $pictures = $FL->getPicturesOf(1);          // All of JohnBaku's Pictures.
    // If you want to fetch comments, you need to populate() the objects.
    $writings_and_pictures = array_merge($writings, $pictures);
    foreach ($writings_and_pictures as $item) {
        $item->comments;   // currently, returns an NULL
        $item->populate();
        $item->comments;   // now, returns an array of FetLifeComment objects.
    }

    // Numerous other functions also return arrays, with optional page limit.
    $members = $FL->getMembersOfGroup(11708); // "Kink On Tap"
    $kinksters = $FL->getKinkstersWithFetish(193); // "Corsets"
    $attendees = $FL->getKinkstersGoingToEvent(149379);
    $maybes = $FL->getKinkstersMaybeGoingToEvent(149379, 2); // Only 2 pages.

    // You can also fetch arrays of events.
    $events = $FL->getUpcomingEventsInLocation('cities/5898'); // Get all events in Balitmore, MD.
    // Or get just the first couple pages.
    $events_partial = $FL->getUpcomingEventsInLocation('cities/5898', 2); // Only 2 pages.

    // FetLifeEvent objects are instantiated from minimal data.
    // To fill them out, call their populate() method.
    $events[0]->populate(); // Flesh out data from first event fetched.
    // RSVP lists take a while to fetch, but you can get them, too.
    $events[1]->populate(2); // Fetch first 2 pages of RSVP responses.
    $events[2]->populate(true); // Or fetch all pages of RSVP responses.

    // Now we have access to some basic event data.
    print $events[2]->getPermalink();
    print $events[2]->venue_name;
    print $events[2]->dress_code;
    // etc...

    // Attendee lists are arrays of FetLifeProfile objects, same as friends lists.
    foreach ($events[2]->going as $profile) {
        print $profile->nickname; // FetLife names of people who RSVP'd "Going."
    }
    $i = 0;
    $y = 0;
    foreach ($events[2]->maybegoing as $profile) {
        if ('Switch' === $profile->role) { $i++; }
        if ('M' === $profile->gender) { $y++; }
    }
    print "There are $i Switches and $y male-identified people maybe going to {$events[2]->title}.";

    // If you already know the event ID, you can just fetch that event.
    $event = $FL->getEventById(151424);
    // "Populate" behavior works the same way.
    $event = $FL->getEventById(151424, true); // Get all availble event data.

[Patches welcome](https://github.com/meitar/libFetLife/issues/new).

## Projects that use libFetLife

* [FetLife WordPress eXtended RSS Generator](https://github.com/meitar/fetlife2wxr)
* [FetLife iCalendar](https://github.com/meitar/fetlife-icalendar/)
* [FetLife Export](https://github.com/meitar/fetlife-export/)
* [FetLife Bridge](https://github.com/meitar/fetlife-bridge/)

Are you using `libFetLife`? [Let me know](http://maybemaimed.com/seminars/#booking-inquiry).

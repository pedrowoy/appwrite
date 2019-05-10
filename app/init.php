<?php

// Init
require_once __DIR__ . '/../vendor/autoload.php';

use Utopia\App;
use Utopia\Request;
use Utopia\Response;
use Auth\Auth;
use Database\Database;
use Database\Document;
use Database\Validator\Authorization;
use Database\Adapter\MySQL as MySQLAdapter;
use Database\Adapter\Redis as RedisAdapter;
use Utopia\Locale\Locale;
use Utopia\Registry\Registry;

const APP_PROTOCOL                  = 'https';
const APP_NAME                      = 'Appwrite';
const APP_DOMAIN                    = 'appwrite.io';
const APP_EMAIL_TEAM                = 'team@' . APP_DOMAIN;
const APP_EMAIL_SECURITY            = 'security@' . APP_DOMAIN;
const APP_EMAIL_NEWSLETTER          = 'newsletter@' . APP_DOMAIN;
const APP_USERAGENT                 = APP_NAME . '-Server/%s Please report abuse at ' . APP_EMAIL_SECURITY;
const APP_SOCIAL_TWITTER            = 'https://twitter.com/appwrite_io';
const APP_SOCIAL_TWITTER_HANDLE     = 'appwrite_io';
const APP_SOCIAL_FACEBOOK           = 'https://www.facebook.com/appwrite.io';
const APP_SOCIAL_LINKEDIN           = 'https://www.linkedin.com/company/appwrite';
const APP_SOCIAL_INSTAGRAM          = 'https://www.instagram.com/appwrite.io';
const APP_SOCIAL_GITHUB             = 'https://github.com/appwrite';
const APP_SOCIAL_DISCORD            = 'https://discord.gg/GSeTUeA';
const APP_MODE_ADMIN                = 'admin';
const APP_LOCALES                   = ['en', 'he'];

$register   = new Registry();
$request    = new Request();
$response   = new Response();

/**
 * ENV vars
 */
$env        = $request->getServer('_APP_ENV', App::ENV_TYPE_PRODUCTION);
$domain     = $request->getServer('HTTP_HOST', '');
$version    = include __DIR__ . '/../app/config/version.php';
$redisHost  = $request->getServer('_APP_REDIS_HOST', '');
$redisPort  = $request->getServer('_APP_REDIS_PORT', '');

Resque::setBackend($redisHost . ':' . $redisPort);

define('COOKIE_DOMAIN', ($request->getServer('HTTP_HOST', null) !== 'localhost') ? '.' . $request->getServer('HTTP_HOST', false) : false);

/**
 * Error Logging
 */
/*if($request->getServer('_APP_SENTRY_DSN')) {
    $sentry = new Raven_Client($request->getServer('_APP_SENTRY_DSN'));

    if($env == App::ENV_TYPE_PRODUCTION) {
        $error_handler = new Raven_ErrorHandler($sentry);
        $error_handler->registerExceptionHandler();
        $error_handler->registerErrorHandler();
        $error_handler->registerShutdownFunction();
    }
}*/

/**
 * Registry
 */
$register->set('db', function() use ($request) { // Register DB connection
    $dbHost     = $request->getServer('_APP_DB_HOST', '');
    $dbUser     = $request->getServer('_APP_DB_USER', '');
    $dbPass     = $request->getServer('_APP_DB_PASS', '');
    $dbScheme   = $request->getServer('_APP_DB_SCHEMA', '');

    $pdo = new PDO("mysql:host={$dbHost};dbname={$dbScheme}", $dbUser, $dbPass, array(
        PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8',
        PDO::ATTR_TIMEOUT => 5 // Seconds
    ));

    // Connection settings
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);   // Return arrays
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);        // Handle all errors with exceptions

    return $pdo;
});
$register->set('influxdb', function() use ($request) { // Register DB connection
    $host = $request->getServer('_APP_INFLUXDB_HOST', 'influxdb');
    $port = $request->getServer('_APP_INFLUXDB_PORT', 8086);

    $client = new InfluxDB\Client($host, $port, '', '', false, false, 5);

    return $client;
});
$register->set('statsd', function() use ($request) { // Register DB connection
    $host = $request->getServer('_APP_STATSD_HOST', 'telegraf-statsd');
    $port = $request->getServer('_APP_STATSD_PORT', 8125);

    $connection = new \Domnikl\Statsd\Connection\UdpSocket($host, $port);
    $statsd = new \Domnikl\Statsd\Client($connection);

    return $statsd;
});
$register->set('cache', function() use ($redisHost, $redisPort) { // Register cache connection
    $redis = new Redis();

    $redis->connect($redisHost, $redisPort);
    return $redis;
});
$register->set('mailgun', function() use ($request, $domain) { // Register MailGun handler - TODO replace with SMTP connection
    $apiKey    = $request->getServer('_APP_MAILGUN_KEY', '');
    $apiDomain = $request->getServer('_APP_MAILGUN_DOMAIN', '');

    $mailgun = new \MailgunLite\MailgunLite($apiKey, $apiDomain);

    $mailgun
        ->setFrom('team@appwrite.io', APP_NAME . ' Team') // Notice: Error when using '.test' domain to send emails with mailgun
    ;

    return clone $mailgun;
});

/**
 * Localization
 */
$locale = $request->getParam('locale', $request->getHeader('X-Appwrite-Locale', null));

Locale::$exceptions = false;

Locale::setLanguage('en', include __DIR__ . '/config/locale/en.php');
Locale::setLanguage('he', include __DIR__ . '/config/locale/he.php');

if(in_array($locale, APP_LOCALES)) {
    Locale::setDefault($locale);
}

stream_context_set_default([ // Set global user agent and http settings
    'http' => [
        'method' => 'GET',
        'user_agent' => sprintf(APP_USERAGENT, $version),
        'timeout' => 2
    ]
]);

/**
 * Auth & Project Scope
 */
$consoleDB = new Database();
$consoleDB->setAdapter(new RedisAdapter(new MySQLAdapter($register), $register));
$consoleDB->setNamespace('app_console'); // Should be replaced with param if we want to have parent projects

Authorization::disable();

$project = $consoleDB->getDocument($request->getParam('project', $request->getHeader('X-Appwrite-Project', null)));

Authorization::enable();

$console = $consoleDB->getDocument('console');

if(is_null($project->getUid()) || Database::SYSTEM_COLLECTION_PROJECTS !== $project->getCollection()) {
    $project = $console;
}

$mode = $request->getParam('mode', $request->getHeader('X-Appwrite-Mode', 'default'));

Auth::setCookieName('a-session-' . $project->getUid());

if(APP_MODE_ADMIN === $mode) {
    Auth::setCookieName('a-session-' . $console->getUid());
}

$session        = Auth::decodeSession($request->getCookie(Auth::$cookieName, $request->getHeader('X-Appwrite-Key', '')));
Auth::$unique   = $session['id'];
Auth::$secret   = $session['secret'];

$projectDB = new Database();
$projectDB->setAdapter(new RedisAdapter(new MySQLAdapter($register), $register));
$projectDB->setNamespace('app_' . $project->getUid());

$user = $projectDB->getDocument(Auth::$unique);

if(APP_MODE_ADMIN === $mode) {
    $user = $consoleDB->getDocument(Auth::$unique);

    $user
        ->setAttribute('$uid', 'admin-' . $user->getAttribute('$uid'))
    ;
}

if(empty($user->getUid()) // Check a document has been found in the DB
    || Database::SYSTEM_COLLECTION_USERS !== $user->getCollection() // Validate returned document is really a user document
    || !Auth::tokenVerify($user->getAttribute('tokens', []), Auth::TOKEN_TYPE_LOGIN, Auth::$secret)) { // Validate user has valid login token
    $user = new Document(['$uid' => '', '$collection' => Database::SYSTEM_COLLECTION_USERS]);
}

if(APP_MODE_ADMIN === $mode) {
    if(!empty($user->search('teamId', $project->getAttribute('teamId'), $user->getAttribute('memberships')))) {
        Authorization::disable();
    }
    else {
        $user = new Document(['$uid' => '', '$collection' => Database::SYSTEM_COLLECTION_USERS]);
    }
}

// Set project mail
$register->get('mailgun')->setFrom(APP_EMAIL_TEAM, sprintf(Locale::getText('auth.emails.team'), $project->getAttribute('name')));
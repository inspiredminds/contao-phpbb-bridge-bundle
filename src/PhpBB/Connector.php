<?php
/*
 * This file is part of contao-phpbbBridge
 * 
 * Copyright (c) CTS GmbH
 * 
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 * 
 */

namespace Ctsmedia\Phpbb\BridgeBundle\PhpBB;

use Buzz\Browser;
use Buzz\Client\Curl;
use Buzz\Listener\CookieListener;
use Buzz\Message\RequestInterface;
use Buzz\Util\Cookie;
use Contao\Encryption;
use Contao\Environment;
use Contao\Input;
use Contao\MemberModel;
use Contao\System;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Yaml\Yaml;


/**
 *
 * @package Ctsmedia\Phpbb\BridgeBundle\PhpBB
 * @author Daniel Schwiperich <d.schwiperich@cts-media.eu>
 */
class Connector
{
    /**
     * @var Connection
     */
    protected $db;

    /**
     * @var mixed|string
     */
    protected $table_prefix = '';

    protected $config = null;


    public function __construct(Connection $db)
    {
        $this->db = $db;
        $this->table_prefix = System::getContainer()->getParameter('phpbb_bridge.db.table_prefix');
        $this->config = Yaml::parse(file_get_contents(__DIR__ . '/../Resources/phpBB/ctsmedia/contaophpbbbridge/config/contao.yml'));
    }

    /**
     * Retrieves the currently logged in user
     *
     * Usage:
     *
     *      $phpbbuser = System::getContainer()->get('phpbb_bridge.connector')->getCurrentUser();
     *      echo $phpbbuser->username
     *      echo $phpbbuser->user_email
     *      echo $phpbbuser->user_birthday
     *
     * @todo Should we check if frontend user is also logged in on contao side?
     *
     * @return object|null
     * @throws \Exception
     */
    public function getCurrentUser()
    {
        // Checks session if user data is alreay initialized or tries to check status (which then set user data to session)
        if(System::getContainer()->get('session')->get('phpbb_user') || $this->isLoggedIn()){
            return System::getContainer()->get('session')->get('phpbb_user');
        }
        return null;

    }

    /**
     * Retrieves a users data from phpbb
     *
     *      $phpbbuser = System::getContainer()->get('phpbb_bridge.connector')->getUser('name_of_user');
     *      echo $phpbbuser->username
     *      echo $phpbbuser->user_email
     *      echo $phpbbuser->user_birthday
     *
     * @param $username
     * @return object|false
     */
    public function getUser($username)
    {
        $queryBuilder = $this->db->createQueryBuilder()
            ->select('*')
            ->from($this->table_prefix . 'users', 'pu')
            ->where('username = ?')
            ->orWhere('username_clean = ?');


        $result = $this->db->fetchAssoc($queryBuilder->getSQL(), array($username, $username));

        if($result) {
            $result = (object)$result;
        } else {
            $result = null;
        }



        return $result;
    }

    /**
     * Check if the current user is logged in and append data to session
     *
     * @todo Implement caching?
     * @return bool
     * @throws \Exception
     */
    public function isLoggedIn()
    {
        $browser = $this->initForumRequest();
        $headers = $this->initForumRequestHeaders();

        // @todo load path from routing.yml
        $path = '/contao_connect/is_logged_in';
        $jsonResponse = $browser->get(Environment::get('url') . '/' . $this->getConfig('contao.forum_pageAlias') . $path, $headers);

        if($jsonResponse->getHeader('content-type') == 'application/json') {
            $result = json_decode($jsonResponse->getContent());
        } else {
            System::log("Could not communicate with forum. JSON Response expected. Got: ".$jsonResponse->getHeader('content-type'), __METHOD__, TL_ERROR);
            throw new \Exception("Could not communicate with forum. JSON Response expected. Got: ".$jsonResponse->getHeader('content-type'));
        }


        System::getContainer()->get('session')->set('phpbb_user', $result->data);


        return (boolean)$result->logged_in;
    }

    /**
     * Logout from phpbb
     */
    public function logout() {
        $cookie_prefix = $this->getDbConfig('cookie_name');
        $sid = Input::cookie($cookie_prefix.'_sid');

        System::getContainer()->get('session')->remove('phpbb_user');

        if($sid){
            $logoutUrl = Environment::get('url') . '/' . $this->getConfig('contao.forum_pageAlias') . '/ucp.php?mode=logout&sid='.$sid;
            $headers = $this->initForumRequestHeaders();
            $browser = $this->initForumRequest();
            $browser->get($logoutUrl, $headers);
        } else {
            System::log("Invalid try to logout user. No active session found.", __METHOD__, TL_ACCESS);
        }

    }

    /**
     * Tries to login the User
     *
     * !!Only returns true if the user is not alreay logged in!!
     * @todo autologin / rememberme sync
     *
     * @param $username string
     * @param $password string
     * @return bool
     */
    public function login($username, $password, $forceToSend = false)
    {

        // @todo login againt bridge controller
        $loginUrl = Environment::get('url') . '/' . $this->getConfig('contao.forum_pageAlias') . '/ucp.php?mode=login';
        $formFields = array(
            'username' => $username,
            'password' => $password,
            'autologin' => 1,
            'viewonline' => 0,
            'login' => 'Login'
        );
        $headers = $this->initForumRequestHeaders();
        $browser = $this->initForumRequest($forceToSend);

        // Try to login
        // @todo maybe better login through our connector?
        $response = $browser->submit($loginUrl, $formFields, RequestInterface::METHOD_POST, $headers);


        // Parse cookies
        $cookie_prefix = $this->getDbConfig('cookie_name');
        $loginCookies = array();
        foreach ($browser->getListener()->getCookies() as $cookie) {
            /* @var $cookie Cookie */

            // Stream cookies through to the client
            System::setCookie($cookie->getName(), $cookie->getValue(), (int)$cookie->getAttribute('expires'),
                $cookie->getAttribute('path'), $cookie->getAttribute('domain'));

            // Get phpbb cookies
            if(strpos($cookie->getName(), $cookie_prefix) !== false) {
                $loginCookies[$cookie->getName()] = $cookie->getValue();
            }
        }


        // If we find a response cookie with user id and user id higher than 1 (anonym) everything went fine
        if($loginCookies[$cookie_prefix.'_u'] > 1){
            System::log('Login to phpbb succeeded for '.$username, __METHOD__, TL_ACCESS);
            return true;
        }

        System::log('Login to phpbb failed for '.$username, __METHOD__, TL_ACCESS);
        return false;

    }

    /**
     * Imports a user from phpbb to contao
     *
     * @param $username
     * @param $password
     * @return bool
     * @throws \Exception
     */
    public function importUser($username, $password) {

        $user = $this->getUser($username);

        if($user) {

            System::log('Importing User '.$username, __METHOD__, TL_ACCESS);
            $contaoUser = new MemberModel();

            $contaoUser->username = $user['username'];
            $contaoUser->email = $user['user_email'];
            $contaoUser->firstname = 'Vorname';
            $contaoUser->lastname = 'Nachname';
            $contaoUser->password = Encryption::hash($password);
            $contaoUser->login = 1;
            $contaoUser->tstamp = $contaoUser->dateAdded = time();
            $contaoUser->save();
            System::log('User imported: '.$username, __METHOD__, TL_ACCESS);
            return true;

        } else {
            System::log($username.' could not be found in phpbb db', __METHOD__, TL_ACCESS);
            return false;
        }
    }

    /**
     * Retrieves a config value from the phpbb config table
     * For Example the cookie_name
     *
     * @param $key
     * @return mixed
     */
    public function getDbConfig($key)
    {
        $queryBuilder = $this->db->createQueryBuilder()
            ->select('config_value')
            ->from($this->table_prefix . 'config', 'co')
            ->where('config_name = ?');

        $result = $this->db->fetchAssoc($queryBuilder->getSQL(), array($key));

        return $result['config_value'];
    }

    public function updateDbConfig($key, $value) {
        $queryBuilder = $this->db->createQueryBuilder()
            ->update($this->table_prefix . 'config', 'co')
            ->set('config_value', $value)
            ->where('config_name = :key')
            ->setParameter('key', $key);
        $result = $queryBuilder->execute();

        return $result;

    }

    /**
     * Returns specific config key
     *
     * @return mixed|null
     */
    public function getConfig($key)
    {
        if (array_key_exists($key, $this->config['parameters'])) {
            return $this->config['parameters'][$key];
        }
        return null;
    }


    public function updateConfig(array $config)
    {
        $currentConfig = $this->config;
        $isChanged = false;

        foreach ($config as $key => $value) {
            if (array_key_exists($key, $currentConfig['parameters'])) {
                $currentConfig['parameters'][$key] = $value;
                $isChanged = true;
            }
        }

        if ($isChanged === true) {
            file_put_contents(__DIR__ . '/../Resources/phpBB/ctsmedia/contaophpbbbridge/config/contao.yml',
                Yaml::dump($currentConfig));
        }

    }

    /**
     * @return Browser
     */
    protected function initForumRequest($force = false)
    {
        // Init Request
        $client = new Curl();
        $client->setMaxRedirects(0);
        $browser = new Browser();
        $browser->setClient($client);
        $cookieListener = new CookieListener();
        $browser->addListener($cookieListener);

        // We need to make sure that the if the original Request is already coming from the forum, we then are not
        // allowed to send a request to the forum so we create a login loop for example.
        if($force === false && System::getContainer()->get('request')->headers->get('x-requested-with') == 'ContaoPhpbbBridge'){
            System::log('Bridge Request Recursion detected', __METHOD__, TL_ERROR);
            throw new TooManyRequestsHttpException(null, 'Internal recursion Bridge requests detected');
        }

        return $browser;
    }

    /**
     * Parse current request and build forwarding headers
     * @return array
     */
    protected function initForumRequestHeaders()
    {
        $req = System::getContainer()->get('request');
        $headers = array();
        if ($req->headers->get('user-agent')) {
            $headers[] = 'User-Agent: ' . $req->headers->get('user-agent');
        }
        if ($req->headers->get('x-forwarded-for')) {
            //split by comma+space
            $forwardIps = explode(", ", $req->headers->get('x-forwarded-for'));
            //add the server ip
            $forwardIps[] = Environment::get('server');
            //set X-Forwarded-For after imploding the array into a comma+space separated string
            $headers[] = 'X-Forwarded-For: ' . implode(", ", array_unique($forwardIps));
        } else {
            $headers[] = 'X-Forwarded-For: ' .Environment::get('ip') . ', ' .Environment::get('server');
        }
        if ($req->headers->get('cookie')) {
            $headers[] = 'Cookie: ' . $req->headers->get('cookie');
        }
        if ($req->headers->get('referer')) {
            $headers[] = 'Referer: ' . $req->headers->get('referer');
        }
        $headers[] = 'X-Requested-With: ContaoPhpbbBridge';

        return $headers;
    }


}
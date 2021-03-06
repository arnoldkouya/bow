<?php

namespace Bow\Auth;

use Bow\Security\Hash;
use Bow\Session\Session;

class Auth
{
    /**
     * @var Auth
     */
    private static $instance;

    /**
     * @var array
     */
    private static $config;

    /**
     * @var array
     */
    private $credentials = [
        'email' => 'email',
        'password' => 'password'
    ];

    /**
     * Auth constructor.
     *
     * @param array $provider
     */
    public function __construct(array $provider, $credentials = [])
    {
        $this->provider = $provider;
        $this->credentials = array_merge($credentials, $this->credentials); 
    }

    /**
     * Configure Auth system
     *
     * @param array $config
     */
    public static function configure(array $config)
    {
        static::$config = $config;
        $provider = $config['default'];

        static::$instance = new Auth($config[$provider]);
    }

    /**
     * Get Auth instance
     *
     * @return Auth
     */
    public static function getInstance()
    {
        return static::$instance;
    }

    /**
     * Check if user is authenticate
     *
     * @param string $guard
     * @return Auth|null
     */
    public function guard($guard = null)
    {
        if (is_null($guard)) {
            if (static::$instance instanceof Auth) {
                return static::$instance;
            }

            return null;
        }

        $provider = static::$config[$guard];

        return new Auth($provider);
    }

    /**
     * Check if user is authenticate
     *
     * @return bool
     */
    public function check()
    {
        return Session::has('_auth');
    }

    /**
     * Check if user is authenticate
     *
     * @return bool
     */
    public function user()
    {
        return \Session::get('_auth');
    }

    /**
     * Check if user is authenticate
     *
     * @return bool
     */
    public function attempts(array $credentials)
    {
        $model = $this->provider['model'];
        $user  = $model::where('email', $this->credentials['email'])->first();

        if (is_null($user)) {
            return false;
        }

        if (Hash::check($user->password, $this->credentials['password'])) {
            Session::add('_auth', $user);
            return true;
        }

        return false;
    }

    /**
     * 
     */
    public function login($user)
    {
        Session::add('_auth', $user);
    }

    /**
     * Get the user id
     *
     * @return bool
     */
    public function id()
    {
        return true;
    }

    /**
     * __call
     *
     * @param string $method
     * @param array $parameters
     * @return mixed
     */
    public function __call($method, array $parameters)
    {
        if (method_exists(static::class, $method)) {
            return call_user_func_array([static::class, $method], $parameters);
        }

        throw new BadMethodCallException("La methode $methode n'existe pas", 1);
    }
}

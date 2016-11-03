<?php

/**
 * Class DbHelper
 *
 * Работа с БД через общий объект.
 */
class DbHelper
{
    protected static $bdClassInstance;

    protected function __construct()
    {
    }

    protected function __clone()
    {
    }

    protected function __wakeup()
    {
    }

    public static function obj()
    {
        if (!self::$bdClassInstance)
        {
            assert(isset(ConfigComponent::getConfig()['database']['type']));

            $className = ConfigComponent::getConfig()['database']['type'] . 'DatabaseComponent';
            self::$bdClassInstance = new $className();
        }

        return self::$bdClassInstance;
    }

}

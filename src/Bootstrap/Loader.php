<?php

namespace MaxiSoft\Bootstrap;

class Loader
{
    private function __construct()
    {
    }

    public function loadClass($class)
    {
        if (mb_substr($class, 0, 9) != 'cms_model') {
            $class = mb_substr($class, 3);
            $file = PATH . '/engine/classes/' . $class . '.php';

            include $file;

            return true;
        }

        if (mb_substr($class, 0, 9) == 'cms_model') {
            $component = mb_substr($class, 10);
            $file = PATH . '/app/components/' . $component . '/frontend/model.php';

            include $file;

            return true;
        }


        if (mb_substr($class, 0, 15) == 'cms_model_admin') {
            $component = mb_substr($class, 16);
            $file = PATH . '/app/components/' . $component . '/backend/model.php';

            include $file;

            return true;
        }
    }

    public static function register($prepend = false)
    {
        spl_autoload_register(array(new static, 'loadClass'), true, $prepend);
    }

    public static function unregister()
    {
        spl_autoload_unregister(array(new static, 'loadClass'));
    }


}

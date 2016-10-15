<?php

namespace MaxiSoft\Render;

use Fenom;

class Render
{
    protected static $tpl_vars  = [];
    protected static $component = null;

    public function __construct()
    {
    }

    public static function view($tpl_folder, $tpl_file, $display = true)
    {

        if ($tpl_folder == 'admin') {
            $tpl_folder = PATH . '/app/components/' . self::$component . '/backend/views';
        } else {
            $is_exists_tpl_file = file_exists(TEMPLATE_DIR . $tpl_folder . '/' . $tpl_file);
            $tpl_folder         = $is_exists_tpl_file ? TEMPLATE_DIR . $tpl_folder : DEFAULT_TEMPLATE_DIR . $tpl_folder;
        }

        $fenom = new RenderTemplate(new Fenom\Provider($tpl_folder));

        $fenom->setCompileDir(PATH . '/cache');

        $fenom->setCompileId(TEMPLATE . '_');

        $fenom->setOptions([
            'strip'       => true,
            'auto_reload' => true,
        ]);

        $fenom->addPluginsDir(__DIR__ . '/plugins/')
            ->addPluginsDir(PATH . '/engine/render/plugins/fenom');


        self::$tpl_vars['LANG'] = \Core::$lang;

        $fenom->assignAll(
            [
                'is_ajax'           => \Core::isAjax(),
                'is_admin'          => \User::getInstance()->is_admin,
                'is_user'           => \User::getInstance()->id,
                'component'         => \Core::getInstance()->component,
                'do'                => \Core::getInstance()->do,
                'seo_link'          => \Core::request('seolink', 'str', ''),
                'site_cfg'          => \Config::getInstance()->getConfig(),
                'component_already' => \Page::getInstance()->page_body ? true : false,
                'template'          => TEMPLATE,
                'template_dir'      => trim(TEMPLATE_DIR, '/'),
            ]
        );

        if ($display) {
            $fenom->display($tpl_file, self::$tpl_vars);
        } else {
            return $fenom->fetch($tpl_file, self::$tpl_vars);
        }
    }

    public static function set(array $tpl_vars = [])
    {
        self::$tpl_vars = array_merge((array)self::$tpl_vars, (array)$tpl_vars);
    }

    public static function clear()
    {
        self::$tpl_vars = [];
    }

    public static function init($component)
    {
        self::$component = $component;
    }

}

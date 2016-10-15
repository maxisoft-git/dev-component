<?php

function fenom_function_wysiwyg($params)
{
    ob_start();
    Core::insertEditor($params['name'], $params['value'], $params['height'], $params['width'], (!empty($params['toolbar']) ? $params['toolbar'] : 'full'));
    return ob_get_clean();
}

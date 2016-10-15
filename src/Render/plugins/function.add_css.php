<?php

function fenom_function_add_css($params)
{
    cmsPage::getInstance()->addHeadCSS($params['file']);
}

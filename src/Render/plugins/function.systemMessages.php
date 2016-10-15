<?php

function fenom_function_systemMessages($params)
{
    $messages = cmsCore::getSessionMessages();
    if ($messages) {
        echo '<div class="row">
            <div class="col-md-12">
                <div class="sess_messages">';

            foreach($messages as $message) {
                echo $message;
            }

        echo '</div>
            </div>
        </div>';
    }
}

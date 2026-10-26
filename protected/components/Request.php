<?php

namespace app\components;

use yii\web\Request as BaseRequest;

/**
 * Model forms in this app always post `Model[attribute]=<scalar>`. An
 * array where a scalar belongs (`Customer[full_name][]=x`) gets assigned by
 * Model::load() and then crashes the form's own re-render - Html::active*Input()
 * htmlspecialchars()-encodes the value, a TypeError on an array - so any
 * crafted POST became an HTTP 500 on every form, the login page included.
 *
 * Blanking those values here (rather than in each model) turns the same
 * request into an ordinary validation failure ("cannot be blank", etc.)
 * everywhere at once. Checked: no view posts a legitimately array-valued
 * model attribute (no multi-selects, no `name[]` inputs). Top-level scalars
 * such as _csrf and confirmDuplicate, and uploaded files ($_FILES), are
 * untouched.
 */
class Request extends BaseRequest
{
    public function getBodyParams()
    {
        $params = parent::getBodyParams();
        if (!is_array($params)) {
            return $params;
        }

        foreach ($params as $form => $fields) {
            if (!is_array($fields)) {
                continue;
            }
            foreach ($fields as $attribute => $value) {
                if (is_array($value)) {
                    $params[$form][$attribute] = '';
                }
            }
        }

        return $params;
    }
}

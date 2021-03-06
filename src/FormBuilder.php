<?php

namespace TomJamon\Html;

use BadMethodCallException;
use DateTime;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\View\Factory;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Traits\Macroable;
use Illuminate\View\View;

/**
 * Class FormBuilder
 * @package TomJamon\Html
 */
class FormBuilder
{
    use Macroable, Componentable {
        Macroable::__call as macroCall;
        Componentable::__call as componentCall;
    }

    /**
     * The HTML builder instance.
     *
     * @var \Collective\Html\HtmlBuilder|HtmlBuilder
     */
    protected $html;

    /**
     * The URL generator instance.
     *
     * @var UrlGenerator
     */
    protected $url;

    /**
     * The View factory instance.
     *
     * @var Factory
     */
    protected $view;

    /**
     * The CSRF token used by the form builder.
     *
     * @var string
     */
    protected $csrfToken;

    /**
     * Consider Request variables while auto fill.
     * @var bool
     */
    protected $considerRequest = false;

    /**
     * The session store implementation.
     *
     * @var Session
     */
    protected $session;

    /**
     * The current model instance for the form.
     *
     * @var mixed
     */
    protected $model;

    /**
     * An array of label names we've created.
     *
     * @var array
     */
    protected $labels = [];

    /**
     * @var Request
     */
    protected $request;

    /**
     * The reserved form open attributes.
     *
     * @var array
     */
    protected $reserved = ['method', 'url', 'route', 'action', 'files'];

    /**
     * The form methods that should be spoofed, in uppercase.
     *
     * @var array
     */
    protected $spoofedMethods = ['DELETE', 'PATCH', 'PUT'];

    /**
     * The types of inputs to not fill values on by default.
     *
     * @var array
     */
    protected $skipValueTypes = ['file', 'password', 'checkbox', 'radio'];

    /**
     * Input Type.
     *
     * @var null
     */
    protected $type = null;

    /**
     * Create a new form builder instance.
     *
     * @param HtmlBuilder $html
     * @param UrlGenerator $url
     * @param Factory $view
     * @param string $csrfToken
     * @param Request $request
     */
    public function __construct(HtmlBuilder $html, UrlGenerator $url, Factory $view, $csrfToken, Request $request = null)
    {
        $this->url = $url;
        $this->html = $html;
        $this->view = $view;
        $this->csrfToken = $csrfToken;
        $this->request = $request;
    }

    /**
     * @param $component
     * @param array $data
     * @param array $mergeData
     * @return Application|Factory|View
     */
    public function viewFromTheme($component, $data = [], $mergeData = [])
    {
        $theme = config('customhtml.theme') ?? 'default';
        $view = 'customhtml::' . $theme . DIRECTORY_SEPARATOR . $component;

        return view($view, $data, $mergeData);
    }

    /**
     * ADDED FROM LARAVEL COLLECTIVE
     *
     * @param String $type
     * @param String $title
     * @param $errors
     * @param array $options
     * @param array $overrides
     * @return Factory|View
     */
    public function control(String $type, String $title, $errors = null, Array $options = [], Array $overrides = [])
    {
        $value = \Request::old($title) ?? null;
        $groupClass = '';
        $attributes = [
            'id' => $title,
            'placeholder' => $options['placeholder'] ?? null
        ];
        foreach ($overrides as $override => $overrideValue):
            $attributes[$override] = $overrideValue;
        endforeach;
        $label = null;
        if (isset($options['label'])) {
            $label = $this->label($title, $options['label']);
        }
        $message = $options['message'] ?? null;
        $selectAttributes = $attributes ?? [];
        $optionsAttributes = $attributes['optionsAttributes'] ?? [];
        $optGroupsAttributes = $attributes['optgroupsAttributes'] ?? [];
        $hasError = false;
        if (($errors != null) && ($errors->has($title))) {
            $hasError = true;
            $attributes['hasError'] = true;
            $message = $errors->first($title);
        }
        if (isset($options['sm'])) {
            $groupClass .= ' sm:w-'.$options['sm'];
        }
        if (isset($options['md'])) {
            $groupClass .= ' md:w-'.$options['md'];
        }
        switch ($type) :
            case "select":
                $input = $this->select($title, $options['data'], $value, $selectAttributes, $optionsAttributes, $optGroupsAttributes);
                break;
            case "submit":
            case "file":
            case "password":
            case "button":
            case "link":
                $input = call_user_func_array(['Form', $type], [$title, $attributes]);
                break;
            case "checkbox":
                $input = $this->checkbox($title, $value, null, $attributes);
                break;
            default:
                $input = call_user_func_array(['Form', $type], [$title, $value, $attributes]);
        endswitch;

        return $this->viewFromTheme('control', compact( 'label', 'groupClass','title', 'message', 'input','hasError'));
    }

    /**
     * Open up a new HTML form.
     *
     * @param  array $options
     *
     * @return Application|Factory|View
     */
    public function open(array $options = [])
    {
        $method = Arr::get($options, 'method', 'post');

        // We need to extract the proper method from the attributes. If the method is
        // something other than GET or POST we'll use POST since we will spoof the
        // actual method since forms don't support the reserved methods in HTML.
        $attributes['method'] = $this->getMethod($method);

        $attributes['action'] = $this->getAction($options);

        $attributes['accept-charset'] = 'UTF-8';

        // If the method is PUT, PATCH or DELETE we will need to add a spoofer hidden
        // field that will instruct the Symfony request to pretend the method is a
        // different method than it actually is, for convenience from the forms.
        $append = $this->getAppendage($method);

        if (isset($options['files']) && $options['files']) {
            $options['enctype'] = 'multipart/form-data';
        }

        // Finally we're ready to create the final form HTML field. We will attribute
        // format the array of attributes. We will also add on the appendage which
        // is used to spoof requests for this PUT, PATCH, etc. methods on forms.
        $attributes = array_merge(
            $attributes, Arr::except($options, $this->reserved)
        );

        // Finally, we will concatenate all of the attributes into a single string so
        // we can build out the final form open statement. We'll also append on an
        // extra value for the hidden _method field if it's needed for the form.
        $attributes = $this->html->attributes($attributes);

        return $this->viewFromTheme('form', compact('attributes', 'append'));
    }

    /**
     * Create a new model based form builder.
     *
     * @param  mixed $model
     * @param  array $options
     *
     * @return Application|Factory|View
     */
    public function model($model, array $options = [])
    {
        $this->model = $model;

        return $this->open($options);
    }

    /**
     * Set the model instance on the form builder.
     *
     * @param  mixed $model
     *
     * @return void
     */
    public function setModel($model)
    {
        $this->model = $model;
    }

    /**
     * Get the current model instance on the form builder.
     *
     * @return mixed $model
     */
    public function getModel()
    {
        return $this->model;
    }

    /**
     * Close the current form.
     *
     * @return string
     */
    public function close()
    {
        $this->labels = [];

        $this->model = null;

        return $this->viewFromTheme('close');
    }

    /**
     * Generate a hidden field with the current CSRF token.
     *
     * @return string
     */
    public function token()
    {
        $token = ! empty($this->csrfToken) ? $this->csrfToken : $this->session->token();

        return $this->hidden('_token', $token);
    }

    /**
     * Create a form label element.
     *
     * @param  string $name
     * @param  string $value
     * @param  array  $options
     * @param  bool   $escape_html
     *
     * @return Application|Factory|View
     */
    public function label($name, $value = null, $options = [], $escape_html = true)
    {
        $this->labels[] = $name;

        $options = $this->html->attributes($options);

        $value = $this->formatLabel($name, $value);

        if ($escape_html) {
            $value = $this->html->entities($value);
        }

        return $this->viewFromTheme('label', compact('name', 'options', 'value'));
    }

    /**
     * Format the label value.
     *
     * @param  string      $name
     * @param  string|null $value
     *
     * @return string
     */
    protected function formatLabel($name, $value)
    {
        return $value ?: ucwords(str_replace('_', ' ', $name));
    }

    /**
     * OVERRIDE FROM LARAVEL COLLECTIVE
     * Create a form input field.
     *
     * @param  string $type
     * @param  string $name
     * @param  string $value
     * @param  array  $options
     *
     * @return Application|Factory|View
     */
    public function input($type, $name, $value = null, $options = [])
    {
        $this->type = $type;
        if (! isset($options['name'])) {
            $options['name'] = $name;
        }
        $id = $this->getIdAttribute($name, $options);
        if (! in_array($type, $this->skipValueTypes)) {
            $value = $this->getValueAttribute($name, $value);
        }
        $hasError = false;
        if (isset($options['hasError']) && $options['hasError']) {
            $hasError = true;
            unset($options['hasError']);
        }
        $merge = compact('type', 'value', 'id');
        $options = array_merge($options, $merge);
        switch ($type) {
            case 'checkbox':
                $input = $this->viewFromTheme('checkbox', compact('options', 'hasError'));
                break;
            default:
                $input = $this->viewFromTheme('input', compact('options', 'hasError'));
        }

        return $input;
    }

    /**
     * Create a text input field.
     *
     * @param  string $name
     * @param  string $value
     * @param  array  $options
     *
     * @return Application|Factory|View
     */
    public function text($name, $value = null, $options = [])
    {
        return $this->input('text', $name, $value, $options);
    }

    /**
     * Create a password input field.
     *
     * @param  string $name
     * @param  array  $options
     *
     * @return Application|Factory|View
     */
    public function password($name, $options = [])
    {
        return $this->input('password', $name, '', $options);
    }

    /**
     * Create a range input field.
     *
     * @param  string $name
     * @param  string $value
     * @param  array  $options
     *
     * @return Application|Factory|View
     */
    public function range($name, $value = null, $options = [])
    {
        return $this->input('range', $name, $value, $options);
    }

    /**
     * Create a hidden input field.
     *
     * @param  string $name
     * @param  string $value
     * @param  array  $options
     *
     * @return Application|Factory|View
     */
    public function hidden($name, $value = null, $options = [])
    {
        return $this->input('hidden', $name, $value, $options);
    }

    /**
     * Create a search input field.
     *
     * @param  string $name
     * @param  string $value
     * @param  array  $options
     *
     * @return Application|Factory|View
     */
    public function search($name, $value = null, $options = [])
    {
        return $this->input('search', $name, $value, $options);
    }

    /**
     * Create an e-mail input field.
     *
     * @param  string $name
     * @param  string $value
     * @param  array  $options
     *
     * @return Application|Factory|View
     */
    public function email($name, $value = null, $options = [])
    {
        return $this->input('email', $name, $value, $options);
    }

    /**
     * Create a tel input field.
     *
     * @param  string $name
     * @param  string $value
     * @param  array  $options
     *
     * @return Application|Factory|View
     */
    public function tel($name, $value = null, $options = [])
    {
        return $this->input('tel', $name, $value, $options);
    }

    /**
     * Create a number input field.
     *
     * @param  string $name
     * @param  string $value
     * @param  array  $options
     *
     * @return Application|Factory|View
     */
    public function number($name, $value = null, $options = [])
    {
        return $this->input('number', $name, $value, $options);
    }

    /**
     * Create a date input field.
     *
     * @param  string $name
     * @param  string $value
     * @param  array  $options
     *
     * @return Application|Factory|View
     */
    public function date($name, $value = null, $options = [])
    {
        if ($value instanceof DateTime) {
            $value = $value->format('Y-m-d');
        }

        return $this->input('date', $name, $value, $options);
    }

    /**
     * Create a datetime input field.
     *
     * @param  string $name
     * @param  string $value
     * @param  array  $options
     *
     * @return Application|Factory|View
     */
    public function datetime($name, $value = null, $options = [])
    {
        if ($value instanceof DateTime) {
            $value = $value->format(DateTime::RFC3339);
        }

        return $this->input('datetime', $name, $value, $options);
    }

    /**
     * Create a datetime-local input field.
     *
     * @param  string $name
     * @param  string $value
     * @param  array  $options
     *
     * @return Application|Factory|View
     */
    public function datetimeLocal($name, $value = null, $options = [])
    {
        if ($value instanceof DateTime) {
            $value = $value->format('Y-m-d\TH:i');
        }

        return $this->input('datetime-local', $name, $value, $options);
    }

    /**
     * Create a time input field.
     *
     * @param  string $name
     * @param  string $value
     * @param  array  $options
     *
     * @return Application|Factory|View
     */
    public function time($name, $value = null, $options = [])
    {
        if ($value instanceof DateTime) {
            $value = $value->format('H:i');
        }

        return $this->input('time', $name, $value, $options);
    }

    /**
     * Create a url input field.
     *
     * @param  string $name
     * @param  string $value
     * @param  array  $options
     *
     * @return Application|Factory|View
     */
    public function url($name, $value = null, $options = [])
    {
        return $this->input('url', $name, $value, $options);
    }

    /**
     * Create a week input field.
     *
     * @param  string $name
     * @param  string $value
     * @param  array  $options
     *
     * @return Application|Factory|View
     */
    public function week($name, $value = null, $options = [])
    {
        if ($value instanceof DateTime) {
            $value = $value->format('Y-\WW');
        }

        return $this->input('week', $name, $value, $options);
    }

    /**
     * Create a file input field.
     *
     * @param  string $name
     * @param  array  $options
     *
     * @return Application|Factory|View
     */
    public function file($name, $options = [])
    {
        return $this->input('file', $name, null, $options);
    }

    /**
     * OVERRIDE FROM LARAVEL COLLECTIVE
     * Create a textarea input field.
     *
     * @param  string $name
     * @param  string $value
     * @param  array  $options
     *
     * @return Application|Factory|View
     */
    public function textarea($name, $value = null, $options = [])
    {
        $this->type = 'textarea';
        if (! isset($options['name'])) {
            $options['name'] = $name;
        }
        $options = $this->setTextAreaSize($options);
        $options['id'] = $this->getIdAttribute($name, $options);
        $value = (string) $this->getValueAttribute($name, $value);
        unset($options['size']);
        $hasError = false;
        if (isset($options['hasError']) && $options['hasError']) {
            $hasError = true;
            unset($options['hasError']);
        }

        return $this->viewFromTheme('textarea', compact('options', 'hasError', 'value'));
    }

    /**
     * Set the text area size on the attributes.
     *
     * @param  array $options
     *
     * @return array
     */
    protected function setTextAreaSize($options)
    {
        if (isset($options['size'])) {
            return $this->setQuickTextAreaSize($options);
        }

        // If the "size" attribute was not specified, we will just look for the regular
        // columns and rows attributes, using sane defaults if these do not exist on
        // the attributes array. We'll then return this entire options array back.
        $cols = Arr::get($options, 'cols', 50);

        $rows = Arr::get($options, 'rows', 10);

        return array_merge($options, compact('cols', 'rows'));
    }

    /**
     * Set the text area size using the quick "size" attribute.
     *
     * @param  array $options
     *
     * @return array
     */
    protected function setQuickTextAreaSize($options)
    {
        $segments = explode('x', $options['size']);

        return array_merge($options, ['cols' => $segments[0], 'rows' => $segments[1]]);
    }

    /**
     * OVERRIDE FROM LARAVEL COLLECTIVE
     * Create a select box field.
     *
     * @param  string $name
     * @param  array  $list
     * @param  string|bool $selected
     * @param  array  $selectAttributes
     * @param  array  $optionsAttributes
     * @param  array  $optgroupsAttributes
     *
     * @return Application|Factory|View
     */
    public function select($name, $list = [], $selected = null, array $selectAttributes = [], array $optionsAttributes = [], array $optgroupsAttributes = [])
    {
        $this->type = 'select';
        $selected = $this->getValueAttribute($name, $selected);
        $selectAttributes['id'] = $this->getIdAttribute($name, $selectAttributes);
        if (! isset($selectAttributes['name'])) {
            $selectAttributes['name'] = $name;
        }
        $html = [];
        if (isset($selectAttributes['placeholder'])) {
            $html[] = $this->placeholderOption($selectAttributes['placeholder'], $selected);
            unset($selectAttributes['placeholder']);
        }
        foreach ($list as $value => $display) {
            $optionAttributes = $optionsAttributes[$value] ?? [];
            $optgroupAttributes = $optgroupsAttributes[$value] ?? [];
            $html[] = $this->getSelectOption($display, $value, $selected, $optionAttributes, $optgroupAttributes);
        }
        $list = implode('', $html);

        return $this->viewFromTheme('select', compact('selectAttributes', 'list'));
    }

    /**
     * Create a select range field.
     *
     * @param  string $name
     * @param  string $begin
     * @param  string $end
     * @param  string $selected
     * @param  array  $options
     *
     * @return Application|Factory|View
     */
    public function selectRange($name, $begin, $end, $selected = null, $options = [])
    {
        $range = array_combine($range = range($begin, $end), $range);

        return $this->select($name, $range, $selected, $options);
    }

    /**
     * Create a select year field.
     *
     * @return mixed
     */
    public function selectYear()
    {
        return call_user_func_array([$this, 'selectRange'], func_get_args());
    }

    /**
     * Create a select month field.
     *
     * @param string $name
     * @param string $selected
     * @param array $options
     * @param string $format
     *
     * @return Application|Factory|View
     */
    public function selectMonth($name, $selected = null, $options = [], $format = '%B')
    {
        $months = [];

        foreach (range(1, 12) as $month) {
            $months[$month] = strftime($format, mktime(0, 0, 0, $month, 1));
        }

        return $this->select($name, $months, $selected, $options);
    }

    /**
     * Get the select option for the given value.
     *
     * @param  string $display
     * @param  string $value
     * @param  string $selected
     * @param  array  $attributes
     * @param  array  $optgroupAttributes
     *
     * @return Application|Factory|View|HtmlString
     */
    public function getSelectOption($display, $value, $selected, array $attributes = [], array $optgroupAttributes = [])
    {
        if (is_iterable($display)) {
            return $this->optionGroup($display, $value, $selected, $optgroupAttributes, $attributes);
        }

        return $this->option($display, $value, $selected, $attributes);
    }

    /**
     * Create an option group form element.
     *
     * @param  array  $list
     * @param  string $label
     * @param  string $selected
     * @param  array  $attributes
     * @param  array  $optionsAttributes
     * @param  integer  $level
     *
     * @return Application|Factory|View
     */
    protected function optionGroup($list, $label, $selected, array $attributes = [], array $optionsAttributes = [], $level = 0)
    {
        $html = [];
        $space = str_repeat("&nbsp;", $level);
        foreach ($list as $value => $display) {
            $optionAttributes = $optionsAttributes[$value] ?? [];
            if (is_iterable($display)) {
                $html[] = $this->optionGroup($display, $value, $selected, $attributes, $optionAttributes, $level+5);
            } else {
                $html[] = $this->option($space.$display, $value, $selected, $optionAttributes);
            }
        }
        $optLabel = e($space.$label, false);
        $optAttributes = $this->html->attributes($attributes);
        $optValue = implode('', $html);

        return $this->viewFromTheme('optgroup', compact('optLabel', 'optAttributes', 'optValue'));
    }

    /**
     * Create a select element option.
     *
     * @param  string $display
     * @param  string $value
     * @param  string $selected
     * @param  array  $attributes
     *
     * @return HtmlString
     */
    protected function option($display, $value, $selected, array $attributes = [])
    {
        $selected = $this->getSelectedValue($value, $selected);

        $options = array_merge(['value' => $value, 'selected' => $selected], $attributes);

        $string = '<option' . $this->html->attributes($options) . '>';
        if ($display !== null) {
            $string .= e($display, false) . '</option>';
        }

        return $this->toHtmlString($string);
    }

    /**
     * Create a placeholder select element option.
     *
     * @param $display
     * @param $selected
     *
     * @return Application|Factory|View
     */
    protected function placeholderOption($display, $selected)
    {
        $selected = $this->getSelectedValue(null, $selected);

        $options = $this->html->attributes([
            'selected' => $selected,
            'value' => '',
        ]);

        $display = e($display, false);

        return $this->viewFromTheme('option', compact('options', 'display'));
    }

    /**
     * Determine if the value is selected.
     *
     * @param  string $value
     * @param  string $selected
     *
     * @return null|string
     */
    protected function getSelectedValue($value, $selected)
    {
        if (is_array($selected)) {
            return in_array($value, $selected, true) || in_array((string) $value, $selected, true) ? 'selected' : null;
        } elseif ($selected instanceof Collection) {
            return $selected->contains($value) ? 'selected' : null;
        }
        if (is_int($value) && is_bool($selected)) {
            return (bool)$value === $selected;
        }
        return ((string) $value === (string) $selected) ? 'selected' : null;
    }

    /**
     * Create a checkbox input field.
     *
     * @param  string $name
     * @param  mixed  $value
     * @param  bool   $checked
     * @param  array  $options
     *
     * @return Application|Factory|View
     */
    public function checkbox($name, $value = 1, $checked = null, $options = [])
    {
        return $this->checkable('checkbox', $name, $value, $checked, $options);
    }

    /**
     * Create a radio button input field.
     *
     * @param  string $name
     * @param  mixed  $value
     * @param  bool   $checked
     * @param  array  $options
     *
     * @return Application|Factory|View
     */
    public function radio($name, $value = null, $checked = null, $options = [])
    {
        if (is_null($value)) {
            $value = $name;
        }

        return $this->checkable('radio', $name, $value, $checked, $options);
    }

    /**
     * Create a checkable input field.
     *
     * @param  string $type
     * @param  string $name
     * @param  mixed  $value
     * @param  bool   $checked
     * @param  array  $options
     *
     * @return Application|Factory|View
     */
    protected function checkable($type, $name, $value, $checked, $options)
    {
        $this->type = $type;

        $checked = $this->getCheckedState($type, $name, $value, $checked);

        if ($checked) {
            $options['checked'] = 'checked';
        }

        return $this->input($type, $name, $value, $options);
    }

    /**
     * Get the check state for a checkable input.
     *
     * @param  string $type
     * @param  string $name
     * @param  mixed  $value
     * @param  bool   $checked
     *
     * @return bool
     */
    protected function getCheckedState($type, $name, $value, $checked)
    {
        switch ($type) {
            case 'checkbox':
                return $this->getCheckboxCheckedState($name, $value, $checked);

            case 'radio':
                return $this->getRadioCheckedState($name, $value, $checked);

            default:
                return $this->compareValues($name, $value);
        }
    }

    /**
     * Get the check state for a checkbox input.
     *
     * @param  string $name
     * @param  mixed  $value
     * @param  bool   $checked
     *
     * @return bool
     */
    protected function getCheckboxCheckedState($name, $value, $checked)
    {
        $request = $this->request($name);

        if (isset($this->session) && ! $this->oldInputIsEmpty() && is_null($this->old($name)) && !$request) {
            return false;
        }

        if ($this->missingOldAndModel($name) && is_null($request)) {
            return $checked;
        }

        $posted = $this->getValueAttribute($name, $checked);

        if (is_array($posted)) {
            return in_array($value, $posted);
        } elseif ($posted instanceof Collection) {
            return $posted->contains('id', $value);
        } else {
            return (bool) $posted;
        }
    }

    /**
     * Get the check state for a radio input.
     *
     * @param  string $name
     * @param  mixed  $value
     * @param  bool   $checked
     *
     * @return bool
     */
    protected function getRadioCheckedState($name, $value, $checked)
    {
        $request = $this->request($name);

        if ($this->missingOldAndModel($name) && !$request) {
            return $checked;
        }

        return $this->compareValues($name, $value);
    }

    /**
     * Determine if the provide value loosely compares to the value assigned to the field.
     * Use loose comparison because Laravel model casting may be in affect and therefore
     * 1 == true and 0 == false.
     *
     * @param  string $name
     * @param  string $value
     * @return bool
     */
    protected function compareValues($name, $value)
    {
        return $this->getValueAttribute($name) == $value;
    }

    /**
     * Determine if old input or model input exists for a key.
     *
     * @param  string $name
     *
     * @return bool
     */
    protected function missingOldAndModel($name)
    {
        return (is_null($this->old($name)) && is_null($this->getModelValueAttribute($name)));
    }

    /**
     * Create a HTML reset input element.
     *
     * @param  string $value
     * @param  array  $attributes
     *
     * @return Application|Factory|View
     */
    public function reset($value, $attributes = [])
    {
        return $this->input('reset', null, $value, $attributes);
    }

    /**
     * Create a HTML image input element.
     *
     * @param  string $url
     * @param  string $name
     * @param  array  $attributes
     *
     * @return Application|Factory|View
     */
    public function image($url, $name = null, $attributes = [])
    {
        $attributes['src'] = $this->url->asset($url);

        return $this->input('image', $name, null, $attributes);
    }

    /**
     * Create a month input field.
     *
     * @param  string $name
     * @param  string $value
     * @param  array  $options
     *
     * @return Application|Factory|View
     */
    public function month($name, $value = null, $options = [])
    {
        if ($value instanceof DateTime) {
            $value = $value->format('Y-m');
        }

        return $this->input('month', $name, $value, $options);
    }

    /**
     * Create a color input field.
     *
     * @param  string $name
     * @param  string $value
     * @param  array  $options
     *
     * @return Application|Factory|View
     */
    public function color($name, $value = null, $options = [])
    {
        return $this->input('color', $name, $value, $options);
    }

    /**
     * Create a submit button element.
     *
     * @param  string $value
     * @param  array  $options
     *
     * @return Application|Factory|View
     */
    public function submit($value = null, $options = [])
    {

        $name = null;

        $this->type = 'submit';

        if (! isset($options['name'])) {
            $options['name'] = $name;
        }

        $id = $this->getIdAttribute($name, $options);

        if (! in_array( 'submit', $this->skipValueTypes)) {
            $value = $this->getValueAttribute($name, $value);
        }

        $hasError = false;
        if (isset($options['hasError']) && $options['hasError']) {
            $hasError = true;
            unset($options['hasError']);
        }

        if(isset($value)) {
            $merge = compact( 'value', 'id');
        } else {
            $merge = compact( 'id');
        }

        $options = array_merge($options, $merge);

        return $this->viewFromTheme('submit', compact('options', 'hasError'));
    }

    /**
     * Create a button element.
     *
     * @param  string $value
     * @param  array  $options
     *
     * @return Application|Factory|View
     */
    public function button($value = null, $options = [])
    {
        if (! array_key_exists('type', $options)) {
            $options['type'] = 'button';
        }

        return $this->viewFromTheme('button', compact('value', 'options'));
    }

    /**
     * ADDED FROM LARAVEL COLLECTIVE
     *
     * @param null $value
     * @param array $options
     * @return Factory|View
     */
    public function link($value = null, $options = [])
    {
        return $this->viewFromTheme('link', compact('value', 'options'));
    }

    /**
     * Create a datalist box field.
     *
     * @param  string $id
     * @param  array  $list
     *
     * @return Application|Factory|View
     */
    public function datalist($id, $list = [])
    {
        $this->type = 'datalist';

        $attributes['id'] = $id;

        $html = [];

        if ($this->isAssociativeArray($list)) {
            foreach ($list as $value => $display) {
                $html[] = $this->option($display, $value, null, []);
            }
        } else {
            foreach ($list as $value) {
                $html[] = $this->option($value, $value, null, []);
            }
        }

        $attributes = $this->html->attributes($attributes);

        $list = implode('', $html);

        return $this->viewFromTheme('datalist', compact('attributes', 'list'));
    }

    /**
     * Determine if an array is associative.
     *
     * @param  array $array
     * @return bool
     */
    protected function isAssociativeArray($array)
    {
        return (array_values($array) !== $array);
    }

    /**
     * Parse the form action method.
     *
     * @param  string $method
     *
     * @return string
     */
    protected function getMethod($method)
    {
        $method = strtoupper($method);

        return $method !== 'GET' ? 'POST' : $method;
    }

    /**
     * Get the form action from the options.
     *
     * @param  array $options
     *
     * @return string
     */
    protected function getAction(array $options)
    {
        // We will also check for a "route" or "action" parameter on the array so that
        // developers can easily specify a route or controller action when creating
        // a form providing a convenient interface for creating the form actions.
        if (isset($options['url'])) {
            return $this->getUrlAction($options['url']);
        }

        if (isset($options['route'])) {
            return $this->getRouteAction($options['route']);
        }

        // If an action is available, we are attempting to open a form to a controller
        // action route. So, we will use the URL generator to get the path to these
        // actions and return them from the method. Otherwise, we'll use current.
        elseif (isset($options['action'])) {
            return $this->getControllerAction($options['action']);
        }

        return $this->url->current();
    }

    /**
     * Get the action for a "url" option.
     *
     * @param  array|string $options
     *
     * @return string
     */
    protected function getUrlAction($options)
    {
        if (is_array($options)) {
            return $this->url->to($options[0], array_slice($options, 1));
        }

        return $this->url->to($options);
    }

    /**
     * Get the action for a "route" option.
     *
     * @param  array|string $options
     *
     * @return string
     */
    protected function getRouteAction($options)
    {
        if (is_array($options)) {
            $parameters = array_slice($options, 1);

            if (array_keys($options) === [0, 1]) {
                $parameters = head($parameters);
            }

            return $this->url->route($options[0], $parameters);
        }

        return $this->url->route($options);
    }

    /**
     * Get the action for an "action" option.
     *
     * @param  array|string $options
     *
     * @return string
     */
    protected function getControllerAction($options)
    {
        if (is_array($options)) {
            return $this->url->action($options[0], array_slice($options, 1));
        }

        return $this->url->action($options);
    }

    /**
     * Get the form appendage for the given method.
     *
     * @param  string $method
     *
     * @return string
     */
    protected function getAppendage($method)
    {
        list($method, $appendage) = [strtoupper($method), ''];

        // If the HTTP method is in this list of spoofed methods, we will attach the
        // method spoofer hidden input to the form. This allows us to use regular
        // form to initiate PUT and DELETE requests in addition to the typical.
        if (in_array($method, $this->spoofedMethods)) {
            $appendage .= $this->hidden('_method', $method);
        }

        // If the method is something other than GET we will go ahead and attach the
        // CSRF token to the form, as this can't hurt and is convenient to simply
        // always have available on every form the developers creates for them.
        if ($method !== 'GET') {
            $appendage .= $this->token();
        }

        return $appendage;
    }

    /**
     * Get the ID attribute for a field name.
     *
     * @param  string $name
     * @param  array  $attributes
     *
     * @return string
     */
    public function getIdAttribute($name, $attributes)
    {
        if (array_key_exists('id', $attributes)) {
            return $attributes['id'];
        }

        if (in_array($name, $this->labels)) {
            return $name;
        }
    }

    /**
     * Get the value that should be assigned to the field.
     *
     * @param  string $name
     * @param  string $value
     *
     * @return mixed
     */
    public function getValueAttribute($name, $value = null)
    {
        if (is_null($name)) {
            return $value;
        }

        $old = $this->old($name);

        if (! is_null($old) && $name !== '_method') {
            return $old;
        }

        if (function_exists('app')) {
            $hasNullMiddleware = app("Illuminate\Contracts\Http\Kernel")
                ->hasMiddleware(ConvertEmptyStringsToNull::class);

            if ($hasNullMiddleware
                && is_null($old)
                && is_null($value)
                && !is_null($this->view->shared('errors'))
                && count(is_countable($this->view->shared('errors')) ? $this->view->shared('errors') : []) > 0
            ) {
                return null;
            }
        }

        $request = $this->request($name);
        if (! is_null($request) && $name != '_method') {
            return $request;
        }

        if (! is_null($value)) {
            return $value;
        }

        if (isset($this->model)) {
            return $this->getModelValueAttribute($name);
        }
    }

    /**
     * Take Request in fill process
     * @param bool $consider
     */
    public function considerRequest($consider = true)
    {
        $this->considerRequest = $consider;
    }

    /**
     * Get value from current Request
     * @param $name
     * @return array|null|string
     */
    protected function request($name)
    {
        if (!$this->considerRequest) {
            return null;
        }

        if (!isset($this->request)) {
            return null;
        }

        return $this->request->input($this->transformKey($name));
    }

    /**
     * Get the model value that should be assigned to the field.
     *
     * @param  string $name
     *
     * @return mixed
     */
    protected function getModelValueAttribute($name)
    {
        $key = $this->transformKey($name);

        if (method_exists($this->model, 'getFormValue')) {
            return $this->model->getFormValue($key);
        }

        return data_get($this->model, $key);
    }

    /**
     * Get a value from the session's old input.
     *
     * @param  string $name
     *
     * @return mixed
     */
    public function old($name)
    {
        if (isset($this->session)) {
            $key = $this->transformKey($name);
            $payload = $this->session->getOldInput($key);

            if (!is_array($payload)) {
                return $payload;
            }

            if (!in_array($this->type, ['select', 'checkbox'])) {
                if (!isset($this->payload[$key])) {
                    $this->payload[$key] = collect($payload);
                }

                if (!empty($this->payload[$key])) {
                    $value = $this->payload[$key]->shift();
                    return $value;
                }
            }

            return $payload;
        }
    }

    /**
     * Determine if the old input is empty.
     *
     * @return bool
     */
    public function oldInputIsEmpty()
    {
        return (isset($this->session) && count((array) $this->session->getOldInput()) === 0);
    }

    /**
     * Transform key from array to dot syntax.
     *
     * @param  string $key
     *
     * @return mixed
     */
    protected function transformKey($key)
    {
        return str_replace(['.', '[]', '[', ']'], ['_', '', '.', ''], $key);
    }

    /**
     * Transform the string to an Html serializable object
     *
     * @param $html
     *
     * @return HtmlString
     */
    protected function toHtmlString($html)
    {
        return new HtmlString($html);
    }

    /**
     * Get the session store implementation.
     *
     * @return  Session  $session
     */
    public function getSessionStore()
    {
        return $this->session;
    }

    /**
     * Set the session store implementation.
     *
     * @param Session $session
     *
     * @return $this
     */
    public function setSessionStore(Session $session)
    {
        $this->session = $session;

        return $this;
    }

    /**
     * Dynamically handle calls to the class.
     *
     * @param  string $method
     * @param  array  $parameters
     *
     * @return \Illuminate\Contracts\View\View|mixed
     *
     * @throws BadMethodCallException
     */
    public function __call($method, $parameters)
    {
        if (static::hasComponent($method)) {
            return $this->componentCall($method, $parameters);
        }

        if (static::hasMacro($method)) {
            return $this->macroCall($method, $parameters);
        }

        throw new BadMethodCallException("Method {$method} does not exist.");
    }
}

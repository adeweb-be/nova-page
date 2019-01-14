<?php

namespace Whitecube\NovaPage\Pages;

use App;
use Closure;
use ArrayAccess;
use Carbon\Carbon;
use BadMethodCallException;
use Whitecube\NovaPage\Sources\SourceInterface;
use Whitecube\NovaPage\Exceptions\TemplateContentNotFoundException;
use Illuminate\Http\Request;

abstract class Template implements ArrayAccess
{

    /**
     * The page name (usually the route's name)
     *
     * @var string
     */
    protected $name;

    /**
     * The page type
     *
     * @var string
     */
    protected $type;

    /**
     * The page's current locale code
     *
     * @var string
     */
    protected $locale;

    /**
     * The page's title for the currently loaded locales
     *
     * @var array
     */
    protected $localizedTitle = [];

    /**
     * The page's attributes for the currently loaded locales
     *
     * @var array
     */
    protected $localizedAttributes = [];

    /**
     * The page's timestamps
     *
     * @var array
     */
    protected $dates = [];

    /**
     * The page's source
     *
     * @var mixed
     */
    protected $source;

    /**
     * Create A Template Instance.
     *
     * @param string $name
     * @param string $type
     * @param string $locale
     * @param bool $throwOnMissing
     */
    public function __construct($name = null, $type = null, $locale = null, $throwOnMissing = true)
    {
        $this->name = $name;
        $this->type = $type;
        $this->setLocale($locale);
        $this->load($throwOnMissing);
    }

    /**
     * Get the template's source class name
     *
     * @return string
     */
    public function getSource() : SourceInterface
    {
        if(is_string($this->source) || is_null($this->source)) {
            $source = $this->source ?? config('novapage.default_source');
            $this->source = new $source;
            $this->source->setConfig(config('novapage.sources.' . $this->source->getName()) ?? []);
        }

        return $this->source;
    }

    /**
     * Load the page's static content for the current locale if needed
     *
     * @param bool $throwOnMissing
     * @return $this
     */
    public function load($throwOnMissing = true)
    {
        if(!$this->name || isset($this->localizedAttributes[$this->locale])) {
            return $this;
        }

        if($data = $this->getSource()->fetch($this->type, $this->name, $this->locale)) {
            $this->fill($this->locale, $data);
            return $this;
        }

        if($throwOnMissing) {
            throw new TemplateContentNotFoundException($this->getSource()->getName(), $this->type, $this->name);
        }

        return $this;
    }

    /**
     * Set all the template's attributes for given locale
     *
     * @param string $locale
     * @param array $data
     * @return void
     */
    public function fill($locale, array $data = [])
    {
        $this->localizedTitle[$locale] = $data['title'] ?? null;
        $this->localizedAttributes[$locale] = $data['attributes'] ?? [];

        $this->setDateIf('created_at', $data['created_at'] ?? null,
            function(Carbon $new, Carbon $current = null) {
                return (!$current || $new->isBefore($current));
            });

        $this->setDateIf('updated_at', $data['updated_at'] ?? null,
            function(Carbon $new, Carbon $current = null) {
                return (!$current || $new->isAfter($current));
            });
    }

    /**
     * Create a new loaded template instance
     *
     * @param string $type
     * @param string $key
     * @param string $locale
     * @param bool $throwOnMissing
     * @return \Whitecube\NovaPage\Pages\Template
     */
    public function getNewTemplate($type, $key, $locale, $throwOnMissing = true)
    {
        return new static($key, $type, $locale, $throwOnMissing);
    }

    /**
     * Wrap calls to getter methods without the "get" prefix
     *
     * @param string $method
     * @param array $arguments
     * @return mixed
     */
    public function __call($method, $arguments)
    {
        $getter = 'get' . ucfirst($method);
        if(method_exists($this, $getter)) {
            return call_user_func_array([$this, $getter], $arguments);
        }

        throw new BadMethodCallException(sprintf(
            'Method %s::%s does not exist.', static::class, $method
        ));
    }

    /**
     * Retrieve the page name
     *
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Retrieve the page type
     *
     * @return string
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * Retrieve the compound page type.name
     *
     * @return string
     */
    public function getKey()
    {
        return $this->type . '.' . $this->name;
    }

    /**
     * Mimic Eloquent's getKeyName method, returning null
     *
     * @return null
     */
    public function getKeyName()
    {
        return null;
    }

    /**
     * Retrieve the page's localized title
     *
     * @param string $default
     * @param string $prepend
     * @param string $append
     * @return string
     */
    public function getTitle($default = null, $prepend = '', $append = '')
    {
        $title = $this->localizedTitle[$this->locale] ?? $default ?? '';
        $title = trim($prepend . $title . $append);
        return strlen($title) ? $title : null;
    }

    /**
     * Retrieve the page's current locale
     *
     * @return string
     */
    public function getLocale()
    {
        return $this->locale;
    }

    /**
     * Set the page's current locale
     *
     * @param string $locale
     * @return $this
     */
    public function setLocale($locale = null)
    {
        $this->locale = $locale ?? App::getLocale();
        return $this;
    }

    /**
     * Retrieve a page's attribute
     *
     * @param string $attribute
     * @param Closure $closure
     * @return mixed
     */
    public function get($attribute, Closure $closure = null)
    {
        if($closure) {
            return $closure($this->__get($attribute));
        }

        return $this->__get($attribute);
    }

    /**
     * Retrieve all the page's attributes for given local
     *
     * @param string $locale
     * @return array
     */
    public function getLocalized($locale)
    {
        return $this->localizedAttributes[$locale];
    }

    /**
     * Magically retrieve a page's attribute
     *
     * @param string $attribute
     * @return mixed
     */
    public function __get($attribute)
    {
        if($attribute === 'nova_page_title') {
            return $this->getTitle();
        }

        if($attribute === 'nova_page_created_at') {
            return $this->getDate('created_at');
        }

        return $this->localizedAttributes[$this->locale][$attribute] ?? null;
    }

    /**
     * Retrieve a timestamp linked to this page resource
     *
     * @param string $timestamp
     * @return Carbon\Carbon
     */
    public function getDate($timestamp = 'created_at')
    {
        return $this->dates[$timestamp] ?? null;
    }

    /**
     * Define a timestamp
     *
     * @param string $moment
     * @param mixed $date
     * @return Carbon\Carbon
     */
    public function setDate($moment, $date = null)
    {
        if(!$date) return;

        if($date instanceof Carbon) {
            return $this->dates[$moment] = $date;
        }

        return $this->dates[$moment] = new Carbon($date);
    }

    /**
     * Define a timestamp if closure condition is met
     *
     * @param string $moment
     * @param mixed $date
     * @param Closure $closure
     * @return mixed
     */
    public function setDateIf($moment, $date = null, Closure $closure)
    {
        if(!($date instanceof Carbon)) {
            $date = new Carbon($date);
        }

        if($closure($date, $this->getDate($moment))) {
            return $this->setDate($moment, $date);
        }
    }

    /**
     * Magically set a page's attribute
     *
     * @param string $attribute
     * @param mixed $attribute
     */
    public function __set($attribute, $value)
    {
        switch ($attribute) {
            case 'nova_page_title':
                $this->localizedTitle[$this->locale] = $value;
                break;
            case 'nova_page_created_at':
                $this->setDate('created_at', $value);
                break;
            
            default:
                $this->localizedAttributes[$this->locale][$attribute] = $value;
                break;
        }
    }

    /**
     * Determine if the given attribute exists.
     *
     * @param  mixed  $offset
     * @return bool
     */
    public function offsetExists($offset)
    {
        return ! is_null($this->__get($offset));
    }

    /**
     * Get the value for a given offset.
     *
     * @param  mixed  $offset
     * @return mixed
     */
    public function offsetGet($offset)
    {
        return $this->__get($offset);
    }

    /**
     * Set the value for a given offset.
     *
     * @param  mixed  $offset
     * @param  mixed  $value
     * @return void
     */
    public function offsetSet($offset, $value)
    {
        $this->__set($offset, $value);
    }

    /**
     * Unset the value for a given offset.
     *
     * @param  mixed  $offset
     * @return void
     */
    public function offsetUnset($offset)
    {
        unset($this->localizedAttributes[$this->locale][$offset]);
    }

    /**
     * Get the fields displayed by the template.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    abstract public function fields(Request $request);

    /**
     * Get the cards available for the request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    abstract public function cards(Request $request);

    /**
     * Mimic eloquent model method and return a fake Query builder
     *
     * @return Whitecube\NovaPage\Pages\Query
     */
    public function newQueryWithoutScopes()
    {
        return resolve(Manager::class)->newQueryWithoutScopes();
    }

    /**
     * Store template attributes in Source
     *
     * @return bool
     */
    public function save()
    {
        $this->setDateIf('created_at', Carbon::now(),
            function(Carbon $new, Carbon $current = null) {
                return !$current;
            }
        );
        return $this->getSource()->store($this, $this->locale);
    }

}
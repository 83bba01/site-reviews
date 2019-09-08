<?php

namespace GeminiLabs\SiteReviews;

use GeminiLabs\SiteReviews\Database\Cache;
use GeminiLabs\SiteReviews\HelperTraits\Arr;
use GeminiLabs\SiteReviews\HelperTraits\Str;
use GeminiLabs\Vectorface\Whip\Whip;

class Helper
{
    use Arr;
    use Str;

    /**
     * @param string $name
     * @param string $path
     * @return string
     */
    public function buildClassName($name, $path = '')
    {
        $className = $this->camelCase($name);
        $path = ltrim(str_replace(__NAMESPACE__, '', $path), '\\');
        return !empty($path)
            ? __NAMESPACE__.'\\'.$path.'\\'.$className
            : $className;
    }

    /**
     * @param string $name
     * @param string $prefix
     * @return string
     */
    public function buildMethodName($name, $prefix = '')
    {
        return lcfirst($prefix.$this->buildClassName($name));
    }

    /**
     * @param string $name
     * @return string
     */
    public function buildPropertyName($name)
    {
        return lcfirst($this->buildClassName($name));
    }

    /**
     * @param string $cast
     * @param mixed $value
     * @return mixed
     */
    public function castTo($cast = '', $value)
    {
        switch ($cast) {
            case 'array':
                return (array) $value;
            case 'boolean':
                return filter_var($value, FILTER_VALIDATE_BOOLEAN);
            case 'float':
                return (float) filter_var($value, FILTER_VALIDATE_FLOAT, FILTER_FLAG_ALLOW_THOUSAND);
            case 'integer':
                return (int) filter_var($value, FILTER_VALIDATE_INT);
            case 'object':
                return (object) (array) $value;
            case 'string':
                if (is_object($value) && in_array('__toString', get_class_methods($value))) {
                    return (string) $value->__toString();
                }
                if (is_array($value) || is_object($value)) {
                    return serialize($value);
                }
                return (string) $value;
            default:
                return $value;
        }
    }

    /**
     * @param string $key
     * @return mixed
     */
    public function filterInput($key, array $request = [])
    {
        if (isset($request[$key])) {
            return $request[$key];
        }
        $variable = filter_input(INPUT_POST, $key);
        if (is_null($variable) && isset($_POST[$key])) {
            $variable = $_POST[$key];
        }
        return $variable;
    }

    /**
     * @param string $key
     * @return array
     */
    public function filterInputArray($key)
    {
        $variable = filter_input(INPUT_POST, $key, FILTER_DEFAULT, FILTER_REQUIRE_ARRAY);
        if (empty($variable) && !empty($_POST[$key]) && is_array($_POST[$key])) {
            $variable = $_POST[$key];
        }
        return (array) $variable;
    }

    /**
     * @return string
     */
    public function getIpAddress()
    {
        $cloudflareIps = glsr(Cache::class)->getCloudflareIps();
        $ipv6 = defined('AF_INET6')
            ? $cloudflareIps['v6']
            : [];
        $whitelist = apply_filters('site-reviews/whip/whitelist', [
            Whip::CLOUDFLARE_HEADERS => [
                Whip::IPV4 => $cloudflareIps['v4'],
                Whip::IPV6 => $ipv6,
            ],
            Whip::CUSTOM_HEADERS => [
                Whip::IPV4 => ['127.0.0.1'],
                Whip::IPV6 => ['::1'],
            ],
        ]);
        $methods = Whip::CUSTOM_HEADERS | Whip::CLOUDFLARE_HEADERS | Whip::REMOTE_ADDR;
        $methods = apply_filters('site-reviews/whip/methods', $methods);
        $whip = new Whip($methods, $whitelist);
        do_action_ref_array('site-reviews/whip', [$whip]);
        return (string) $whip->getValidIpAddress();
    }
}

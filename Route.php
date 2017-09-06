<?php

namespace ys\route;

use Illuminate\Http\Request;

/**
 *
 * @method static Tenden any(string $route, Callable $callback)
 * @method static Tenden get(string $route, Callable $callback)
 * @method static Tenden post(string $route, Callable $callback)
 * @method static Tenden put(string $route, Callable $callback)
 * @method static Tenden delete(string $route, Callable $callback)
 * @method static Tenden options(string $route, Callable $callback)
 * @method static Tenden head(string $route, Callable $callback)
 */
class Route
{
    public static $halts = false;
    public static $routes = [];
    public static $methods = [];
    public static $callbacks = [];
    public static $names = [];
    const VARIABLE_REGEX = <<<REGEX
    \{
        \s* ([a-zA-Z_][a-zA-Z0-9_-]*) \s*
        (?:
            : \s* ([^{}]*(?:\{(?-1)\}[^{}]*)*)
        )?
    \}
REGEX;
    const DEFAULT_DISPATCH_REGEX = '[^/]+';
    public static $error_callback;

  /**
   * Defines a route w/ callback and method
   */
    public static function __callstatic($method, $params)
    {
        if (php_sapi_name()==='cli-server') {
            $uri = str_replace($_SERVER['SCRIPT_NAME'], '', $params[0]);
        } else {
            $uri = dirname($_SERVER['PHP_SELF']).'/'.$params[0];
        }
        $callback = $params[1];
        array_push(self::$routes, $uri);
        array_push(self::$methods, strtoupper($method));
        array_push(self::$callbacks, $callback);
        if (isset($params[2])) {
            $name = $params[2];
        } else {
            $name= md5($method.$uri);
        }
        array_push(self::$names, $name);
    }

    /**
     * Defines callback if route is not found
     */
    public static function error($callback)
    {
        self::$error_callback = $callback;
    }

    public static function haltOnMatch($flag = true)
    {
        self::$halts = $flag;
    }

    private static function parsePlaceholders($route)
    {
        //https://github.com/nikic/FastRoute
        if (!preg_match_all(
            '~' . self::VARIABLE_REGEX . '~x', $route, $matches,
            PREG_OFFSET_CAPTURE | PREG_SET_ORDER
        )) {
            return [$route];
        }

        $offset = 0;
        $routeData = [];
        foreach ($matches as $set) {
            if ($set[0][1] > $offset) {
                $routeData[] = substr($route, $offset, $set[0][1] - $offset);
            }
            $routeData[] = [
                $set[1][0],
                isset($set[2]) ? trim($set[2][0]) : self::DEFAULT_DISPATCH_REGEX
            ];
            $offset = $set[0][1] + strlen($set[0][0]);
        }

        if ($offset != strlen($route)) {
            $routeData[] = substr($route, $offset);
        }

        return $routeData;
    }

    public static function path(string $name, array $parameters = [])
    {
        if (in_array($name, self::$names)) {
            $name_pos = array_keys(self::$names, $name)[0];
            $uri = self::$routes[$name_pos]; //
            $info = self::parsePlaceholders($uri);
            $url = '';
            foreach ($info as $k => $v) {
                if (is_array($v)) {
                    $url .= ($v[0].'/'.isset($parameters[$v[0]])?$parameters[$v[0]]:null);
                    unset($parameters[$v[0]]);
                } else {
                    $url .= $v;
                }
            }
            if (count($parameters)) {
                $url .= '?'.http_build_query($parameters);
            }
            return $url;
        }
        throw new \Exception("name: {$name} Not Found");
    }

  
    /**
     * Runs the callback for the given request
     */
    public static function dispatch()
    {
        $request = Request::createFromGlobals();
        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $method = $_SERVER['REQUEST_METHOD'];

        $found_route = false;

        self::$routes = preg_replace('/\/+/', '/', self::$routes);
      
        // Check if route is defined without regex
        if (in_array($uri, self::$routes)) {
            $route_pos = array_keys(self::$routes, $uri);
            foreach ($route_pos as $route) {
                // Using an ANY option to match both GET and POST requests
                if (self::$methods[$route] == $method || self::$methods[$route] == 'ANY') {
                    $found_route = true;
                    // If route is not an object
                    if (!is_object(self::$callbacks[$route])) {
                        // Grab all parts based on a / separator
                        $parts = explode('/', self::$callbacks[$route]);

                        // Collect the last index of the array
                        $last = end($parts);

                        // Grab the controller name and method call
                        $segments = explode('@', $last);

                        // Instanitate controller
                        $controller = new $segments[0]();

                        // Call method
                        $controller->{$segments[1]}($request);

                        if (self::$halts) {
                            return;
                        }
                    } else {
                        // Call closure
                        call_user_func(self::$callbacks[$route], [$request]);

                        if (self::$halts) {
                            return;
                        }
                    }
                }
            }
        } else {
            $pos = 0;
            foreach (self::$routes as $route) {
                $info = self::parsePlaceholders($route);
                // if(count($info)===1){
                //     continue;
                // }
                $newRoute = $info[0];
                for ($i=1; $i<count($info); $i++) {
                    if (is_array($info[$i])) {
                        $newRoute .= '('.$info[$i][1].')';
                    } else {
                        $newRoute .= $info[$i];
                    }
                }
                if (preg_match('#^' . $newRoute . '$#', $uri, $matched)) {
                    if (self::$methods[$pos] == $method || self::$methods[$pos] == 'ANY') {
                        $found_route = true;
                        // Remove $matched[0] as [1] is the first parameter.
                        array_shift($matched);
                        array_unshift($matched, $request);
                        if (!is_object(self::$callbacks[$pos])) {
                            // Grab all parts based on a / separator
                            $parts = explode('/', self::$callbacks[$pos]);
                            // Collect the last index of the array
                            $last = end($parts);
                            // Grab the controller name and method call
                            $segments = explode('@', $last);
                            // Instanitate controller
                            $controller = new $segments[0]();
                            // Fix multi parameters
                            if (!method_exists($controller, $segments[1])) {
                                echo "controller and action not found";
                            } else {
                                call_user_func_array(array($controller, $segments[1]), $matched);
                            }
                            if (self::$halts) {
                                return;
                            }
                        } else {
                            array_unshift($matched, $request);
                            call_user_func_array(self::$callbacks[$pos], $matched);
                            if (self::$halts) {
                                return;
                            }
                        }
                    }
                }
                $pos++;
            }
        }
        // Run the error callback if the route was not found
        if ($found_route == false) {
            if (!self::$error_callback) {
                self::$error_callback = function () {
                    header($_SERVER['SERVER_PROTOCOL']." 404 Not Found");
                    echo '404';
                };
            } else {
                if (is_string(self::$error_callback)) {
                    self::get($_SERVER['REQUEST_URI'], self::$error_callback);
                    self::$error_callback = null;
                    self::dispatch();
                    return ;
                }
            }
            call_user_func(self::$error_callback);
        }
    }
}

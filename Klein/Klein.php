<?php
/**
 * Klein (klein.php) - A lightning fast router for PHP
 *
 * @author      Chris O'Hara <cohara87@gmail.com>
 * @author      Trevor Suarez (Rican7) (contributor and v2 refactorer)
 * @copyright   (c) Chris O'Hara
 * @link        https://github.com/chriso/klein.php
 * @license     MIT
 */

namespace Klein;

use \Exception;

/**
 * Klein
 *
 * Main Klein router class
 * 
 * @package     Klein
 */
class Klein
{

    /**
     * Class properties
     */

    /**
     * Array of the routes to match on dispatch
     *
     * @var array
     * @access protected
     */
    protected $routes;

    /**
     * The namespace of which to collect the routes in
     * when matching, so you can define routes under a
     * common endpoint
     *
     * @var string
     * @access protected
     */
    protected $namespace;


    /**
     * Route objects
     */

    /**
     * The Request object passed to each matched route
     *
     * @var Request
     * @access protected
     */
    protected $request;

    /**
     * The Response object passed to each matched route
     *
     * @var Response
     * @access protected
     */
    protected $response;

    /**
     * A generic variable passed to each matched route
     *
     * @var mixed
     * @access protected
     */
    protected $app;


    /**
     * Methods
     */

    /**
     * Constructor
     *
     * Create a new Klein instance with optionally injected dependencies
     * This DI allows for easy testing, object mocking, or class extension
     *
     * @param Headers $headers      A Headers object to be cloned to both the Request and Response objects
     * @param Request $request      To contain all incoming request properties and related functions
     * @param Response $response    To contain all outgoing response properties and related functions
     * @param mixed $app            An object that will be passed to each route callback, defaults to a new App instance
     * @access public
     */
    public function __construct(
        Headers $headers = null,
        Request $request = null,
        Response $response = null,
        $app = null
    ) {
        // Create our base Headers object to be cloned
        $headers        = $headers  ?: new Headers();

        // Instanciate our routing objects
        // $this->request  = $request  ?: new Request(clone $headers);
        $this->response = $response ?: new Response(clone $headers);
        $this->app      = $app      ?: new App();
    }

    /**
     * Add a new route to be matched on dispatch
     *
     * This method takes its arguments in a very loose format
     * The only "required" parameter is the callback (which is very strange considering the argument definition order)
     *
     * <code>
     * $router = new Klein();
     *
     * $router->respond( function() {
     *     echo 'this works';
     * });
     * $router->respond( '/endpoint', function() {
     *     echo 'this also works';
     * });
     * $router->respond( 'POST', '/endpoint', function() {
     *     echo 'this also works!!!!';
     * });
     * </code>
     *
     * @param string | array $method    HTTP Method to match
     * @param string $route             Route URI to match
     * @param callable $callback        Callable callback method to execute on route match
     * @access public
     * @return callable $callback
     */
    public function respond($method, $route = '*', $callback = null)
    {
        $args = func_get_args();
        $callback = array_pop($args);
        $route = array_pop($args);
        $method = array_pop($args);

        if (null === $route) {
            $route = '*';
        }

        // only consider a request to be matched when not using matchall
        $count_match = ($route !== '*');

        if ($this->namespace && $route[0] === '@' || ($route[0] === '!' && $route[1] === '@')) {
            if ($route[0] === '!') {
                $negate = true;
                $route = substr($route, 2);
            } else {
                $negate = false;
                $route = substr($route, 1);
            }

            // regex anchored to front of string
            if ($route[0] === '^') {
                $route = substr($route, 1);
            } else {
                $route = '.*' . $route;
            }

            if ($negate) {
                $route = '@^' . $this->namespace . '(?!' . $route . ')';
            } else {
                $route = '@^' . $this->namespace . $route;
            }

        } elseif ($this->namespace && ('*' === $route)) {
            // empty route with namespace is a match-all
            $route = '@^' . $this->namespace . '(/|$)';
        } else {
            $route = $this->namespace . $route;
        }

        $this->routes[] = array($method, $route, $callback, $count_match);

        return $callback;
    }

    /**
     * Collect a set of routes under a common namespace
     *
     * The routes may be passed in as either a callable (which holds the route definitions),
     * or as a string of a filename, of which to "include" under the Klein router scope
     *
     * <code>
     * $router = new Klein();
     *
     * $router->with('/users', function() use ( $router) {
     *     $router->respond( '/', function() {
     *         // do something interesting
     *     });
     *     $router->respond( '/[i:id]', function() {
     *         // do something different
     *     });
     * });
     *
     * $router->with('/cars', __DIR__ . '/routes/cars.php');
     * </code>
     *
     * @param string $namespace                     The namespace under which to collect the routes
     * @param callable | string[filename] $routes   The defined routes to collect under the namespace
     * @access public
     * @return void
     */
    public function with($namespace, $routes)
    {
        $previous = $this->namespace;
        $this->namespace .= $namespace;

        if (is_callable($routes)) {
            $routes();
        } else {
            require $routes;
        }

        $this->namespace = $previous;
    }

    /**
     * Start a PHP session
     *
     * @access public
     * @return void
     */
    public function startSession()
    {
        if (session_id() === '') {
            session_start();
        }
    }

    /**
     * Dispatch the request to the approriate route(s)
     *
     * @param Request $request      The request object to inject into the router
     * @access public
     * @return void
     */
    public function dispatch(Request $request = null)
    {
        if (is_null($request)) {
            $request = Request::createFromGlobals();
        }

        // Grab some data from the request
        $uri = $request->uri(true); // Strip the query string
        $req_method = $request->method();

        // Set up some variables for matching
        $matched = 0;
        $methods_matched = array();
        $params = array();
        $apc = function_exists('apc_fetch');

        ob_start();

        foreach ($this->routes as $handler) {
            list($method, $_route, $callback, $count_match) = $handler;

            // Keep track of whether this specific request method was matched
            $method_match = null;

            // Was a method specified? If so, check it against the current request method
            if (is_array($method)) {
                foreach ($method as $test) {
                    if (strcasecmp($req_method, $test) === 0) {
                        $method_match = true;
                    } elseif (strcasecmp($req_method, 'HEAD') === 0
                          && (strcasecmp($test, 'HEAD') === 0 || strcasecmp($test, 'GET') === 0)) {

                        // Test for HEAD request (like GET)
                        $method_match = true;
                    }
                }

                if (null === $method_match) {
                    $method_match = false;
                }
            } elseif (null !== $method && strcasecmp($req_method, $method) !== 0) {
                $method_match = false;

                // Test for HEAD request (like GET)
                if (strcasecmp($req_method, 'HEAD') === 0
                    && (strcasecmp($method, 'HEAD') === 0 || strcasecmp($method, 'GET') === 0 )) {

                    $method_match = true;
                }
            } elseif (null !== $method && strcasecmp($req_method, $method) === 0) {
                $method_match = true;
            }

            // If the method was matched or if it wasn't even passed (in the route callback)
            $possible_match = is_null($method_match) || $method_match;

            // ! is used to negate a match
            if (isset($_route[0]) && $_route[0] === '!') {
                $negate = true;
                $i = 1;
            } else {
                $negate = false;
                $i = 0;
            }

            // Check for a wildcard (match all)
            if ($_route === '*') {
                $match = true;

            } elseif ($_route === '404' && !$matched && count($methods_matched) <= 0) {
                // Easily handle 404's

                try {
                    call_user_func($callback, $request, $this->response, $this->app, $matched, $methods_matched);
                } catch (Exception $e) {
                    $this->response->error($e);
                }

                ++$matched;
                continue;

            } elseif ($_route === '405' && !$matched && count($methods_matched) > 0) {
                // Easily handle 405's

                try {
                    call_user_func($callback, $request, $this->response, $this->app, $matched, $methods_matched);
                } catch (Exception $e) {
                    $this->response->error($e);
                }

                ++$matched;
                continue;

            } elseif (isset($_route[$i]) && $_route[$i] === '@') {
                // @ is used to specify custom regex

                $match = preg_match('`' . substr($_route, $i + 1) . '`', $uri, $params);

            } else {
                // Compiling and matching regular expressions is relatively
                // expensive, so try and match by a substring first

                $route = null;
                $regex = false;
                $j = 0;
                $n = isset($_route[$i]) ? $_route[$i] : null;

                // Find the longest non-regex substring and match it against the URI
                while (true) {
                    if (!isset($_route[$i])) {
                        break;
                    } elseif (false === $regex) {
                        $c = $n;
                        $regex = $c === '[' || $c === '(' || $c === '.';
                        if (false === $regex && false !== isset($_route[$i+1])) {
                            $n = $_route[$i + 1];
                            $regex = $n === '?' || $n === '+' || $n === '*' || $n === '{';
                        }
                        if (false === $regex && $c !== '/' && (!isset($uri[$j]) || $c !== $uri[$j])) {
                            continue 2;
                        }
                        $j++;
                    }
                    $route .= $_route[$i++];
                }

                // Check if there's a cached regex string
                if (false !== $apc) {
                    $regex = apc_fetch("route:$route");
                    if (false === $regex) {
                        $regex = $this->compileRoute($route);
                        apc_store("route:$route", $regex);
                    }
                } else {
                    $regex = $this->compileRoute($route);
                }

                $match = preg_match($regex, $uri, $params);
            }

            if (isset($match) && $match ^ $negate) {
                // Keep track of possibly matched methods
                $methods_matched = array_merge($methods_matched, (array) $method);
                $methods_matched = array_filter($methods_matched);
                $methods_matched = array_unique($methods_matched);

                if ($possible_match) {
                    if (!empty($params)) {
                        $request->paramsNamed()->merge($params);
                    }

                    // Try and call our route's callback
                    try {
                        call_user_func(
                            $callback,
                            $request,
                            $this->response,
                            $this->app,
                            $matched,
                            $methods_matched
                        );
                    } catch (Exception $e) {
                        $this->response->error($e);
                    }

                    if ($_route !== '*') {
                        $count_match && ++$matched;
                    }
                }
            }
        }

        if (!$matched && count($methods_matched) > 0) {
            if (strcasecmp($req_method, 'OPTIONS') !== 0) {
                $this->response->code(405);
            }

            $this->response->header('Allow', implode(', ', $methods_matched));
        } elseif (!$matched) {
            $this->response->code(404);
        }

        // Test for HEAD request (like GET)
        if (strcasecmp($req_method, 'HEAD') === 0) {
            // HEAD requests shouldn't return a body
            return ob_get_clean();

        // TODO
        // } elseif ($capture) {
        //     return ob_get_clean();

        } elseif ($this->response->chunked) {
            $this->response->chunk();

        } else {
            ob_end_flush();
        }
    }

    /**
     * Compiles a route string to a regular expression
     *
     * @param string $route     The route string to compile
     * @access public
     * @return void
     */
    public function compileRoute($route)
    {
        if (preg_match_all('`(/|\.|)\[([^:\]]*+)(?::([^:\]]*+))?\](\?|)`', $route, $matches, PREG_SET_ORDER)) {
            $match_types = array(
                'i'  => '[0-9]++',
                'a'  => '[0-9A-Za-z]++',
                'h'  => '[0-9A-Fa-f]++',
                '*'  => '.+?',
                '**' => '.++',
                ''   => '[^/]+?'
            );
            foreach ($matches as $match) {
                list($block, $pre, $type, $param, $optional) = $match;

                if (isset($match_types[$type])) {
                    $type = $match_types[$type];
                }
                if ($pre === '.') {
                    $pre = '\.';
                }
                // Older versions of PCRE require the 'P' in (?P<named>)
                $pattern = '(?:'
                         . ($pre !== '' ? $pre : null)
                         . '('
                         . ($param !== '' ? "?P<$param>" : null)
                         . $type
                         . '))'
                         . ($optional !== '' ? '?' : null);

                $route = str_replace($block, $pattern, $route);
            }
        }
        return "`^$route$`";
    }

    /**
     * Add a custom validator to validate request parameters against
     *
     * @param string $method        The name of the validator method
     * @param callable $callback    The callback to perform on validation
     * @access public
     * @return void
     */
    public function addValidator($method, $callback)
    {
        Validator::$methods[strtolower($method)] = $callback;
    }
}

<?php

declare(strict_types = 1);

namespace Httpful;

use Closure;
use Exception;
use Httpful\Exception\ConnectionErrorException;

use function curl_version;
use function implode;
use function parse_url;
use function preg_replace;

/**
 * Clean, simple class for sending HTTP requests
 * in PHP.
 *
 * There is an emphasis of readability without loosing concise
 * syntax. As such, you will notice that the library lends
 * itself very nicely to "chaining". You will see several "alias"
 * methods: more readable method definitions that wrap
 * their more concise counterparts. You will also notice
 * no public constructor. This two adds to the readability
 * and "chainabilty" of the library.
 *
 * @author Nate Good <me@nategood.com>
 * @method self sendsJson()
 * @method self sendsXml()
 * @method self sendsForm()
 * @method self sendsPlain()
 * @method self sendsText()
 * @method self sendsUpload()
 * @method self sendsHtml()
 * @method self sendsXhtml()
 * @method self sendsJs()
 * @method self sendsJavascript()
 * @method self sendsYaml()
 * @method self sendsCsv()
 * @method self expectsJson()
 * @method self expectsXml()
 * @method self expectsForm()
 * @method self expectsPlain()
 * @method self expectsText()
 * @method self expectsUpload()
 * @method self expectsHtml()
 * @method self expectsXhtml()
 * @method self expectsJs()
 * @method self expectsJavascript()
 * @method self expectsYaml()
 * @method self expectsCsv()
 */
class Request
{
    // Option constants
    const SERIALIZE_PAYLOAD_NEVER = 0;
    const SERIALIZE_PAYLOAD_ALWAYS = 1;
    const SERIALIZE_PAYLOAD_SMART = 2;

    const MAX_REDIRECTS_DEFAULT = 25;

    public $uri;
    public $method = Http::GET;
    public $headers = array();
    public $raw_headers = '';
    public $strict_ssl = false;
    public $content_type;
    public $expected_type;
    public $additional_curl_opts = array();
    public $auto_parse = true;
    public $serialize_payload_method = self::SERIALIZE_PAYLOAD_SMART;
    public $username;
    public $password;
    public $serialized_payload;
    public $payload;
    public $parse_callback;
    public $error_callback;
    public $send_callback;
    public $follow_redirects = false;
    public $max_redirects = self::MAX_REDIRECTS_DEFAULT;
    public $payload_serializers = array();

    // Options
    // private $_options = array(
    //     'serialize_payload_method' => self::SERIALIZE_PAYLOAD_SMART
    //     'auto_parse' => true
    // );

    // Curl Handle
    public $_ch;
    public $_debug;

    // Template Request object
    private static $_template;

    /**
     * We made the constructor protected to force the factory style. This was
     * done to keep the syntax cleaner and better the support the idea of
     * "default templates". Very basic and flexible as it is only intended
     * for internal use.
     *
     * @param array $attrs hash of initial attribute values
     */
    protected function __construct(?array $attrs = null)
    {
        if (!is_array($attrs)) {
            return;
        }

        foreach ($attrs as $attr => $value) {
            $this->$attr = $value;
        }
    }

    // Defaults Management

    /**
     * Factory style constructor works nicer for chaining. This
     * should also really only be used internally. The Request::get,
     * Request::post syntax is preferred as it is more readable.
     *
     * @param string $method Http Method
     * @param string $mime Mime Type to Use
     * @return \Httpful\Request
     */
    public static function init(?string $method = null, ?string $mime = null): self
    {
        // Setup our handlers, can call it here as it's idempotent
        Bootstrap::init();

        // Setup the default template if need be
        if (!isset(self::$_template)) {
            self::_initializeDefaults();
        }

        $request = new self();

        return $request
               ->_setDefaults()
               ->method($method)
               ->sendsType($mime)
               ->expectsType($mime);
    }

    /**
     * HTTP Method Get
     *
     * @param string $uri optional uri to use
     * @param string $mime expected
     * @return \Httpful\Request
     */
    public static function get(string $uri, ?string $mime = null): self
    {
        return self::init(Http::GET)->uri($uri)->mime($mime);
    }

    /**
     * HTTP Method Post
     *
     * @param string $uri optional uri to use
     * @param string $payload data to send in body of request
     * @param string $mime MIME to use for Content-Type
     * @return \Httpful\Request
     */
    public static function post(string $uri, ?string $payload = null, ?string $mime = null): self
    {
        return self::init(Http::POST)->uri($uri)->body($payload, $mime);
    }

    /**
     * HTTP Method Put
     *
     * @param string $uri optional uri to use
     * @param string $payload data to send in body of request
     * @param string $mime MIME to use for Content-Type
     * @return \Httpful\Request
     */
    public static function put(string $uri, ?string $payload = null, ?string $mime = null): self
    {
        return self::init(Http::PUT)->uri($uri)->body($payload, $mime);
    }

    /**
     * HTTP Method Patch
     *
     * @param string $uri optional uri to use
     * @param string $payload data to send in body of request
     * @param string $mime MIME to use for Content-Type
     * @return \Httpful\Request
     */
    public static function patch(string $uri, ?string $payload = null, ?string $mime = null): self
    {
        return self::init(Http::PATCH)->uri($uri)->body($payload, $mime);
    }

    /**
     * HTTP Method Delete
     *
     * @param string $uri optional uri to use
     * @return \Httpful\Request
     */
    public static function delete(string $uri, $mime = null): self
    {
        return self::init(Http::DELETE)->uri($uri)->mime($mime);
    }

    /**
     * HTTP Method Head
     *
     * @param string $uri optional uri to use
     * @return \Httpful\Request
     */
    public static function head(string $uri): self
    {
        return self::init(Http::HEAD)->uri($uri);
    }

    /**
     * HTTP Method Options
     *
     * @param string $uri optional uri to use
     * @return \Httpful\Request
     */
    public static function options(string $uri): self
    {
        return self::init(Http::OPTIONS)->uri($uri);
    }

    /** @return bool does the request have a timeout? */
    public function hasTimeout(): bool
    {
        return isset($this->timeout);
    }

    /** @return bool has the internal curl request been initialized? */
    public function hasBeenInitialized(): bool
    {
        return isset($this->_ch);
    }

    /** @return bool Is this request setup for basic auth? */
    public function hasBasicAuth(): bool
    {
        return isset($this->password) && isset($this->username);
    }

    /** @return bool Is this request setup for digest auth? */
    public function hasDigestAuth(): bool
    {
        return isset($this->password) && isset($this->username) && $this->additional_curl_opts[CURLOPT_HTTPAUTH] === CURLAUTH_DIGEST;
    }

    /**
     * Specify a HTTP timeout
     *
     * @param float|int $timeout seconds to timeout the HTTP call
     * @return \Httpful\Request
     */
    public function timeout(float|int $timeout): self
    {
        $this->timeout = $timeout;

        return $this;
    }

    // alias timeout
    public function timeoutIn($seconds)
    {
        return $this->timeout($seconds);
    }

    /**
     * If the response is a 301 or 302 redirect, automatically
     * send off another request to that location
     *
     * @param bool|int $follow follow or not to follow or maximal number of redirects
     * @return \Httpful\Request
     */
    public function followRedirects(bool|int $follow = true): self
    {
        $this->max_redirects = $follow === true
            ? self::MAX_REDIRECTS_DEFAULT
            : max(0, $follow);
        $this->follow_redirects = (bool) $follow;

        return $this;
    }

    /**
     * @see Request::followRedirects()
     * @return \Httpful\Request
     */
    public function doNotFollowRedirects(): self
    {
        return $this->followRedirects(false);
    }

    /**
     * Actually send off the request, and parse the response
     *
     * @return \Httpful\Response with parsed results
     * @throws \Httpful\Exception\ConnectionErrorException when unable to parse or communicate w server
     */
    public function send(): Response
    {
        if (!$this->hasBeenInitialized()) {
            $this->_curlPrep();
        }

        $result = curl_exec($this->_ch);

        $response = $this->buildResponse($result);

        curl_close($this->_ch);
        unset($this->_ch);

        return $response;
    }

    public function sendIt()
    {
        return $this->send();
    }

    // Setters

    /** @return \Httpful\Request */
    public function uri(string $uri): self
    {
        $this->uri = $uri;

        return $this;
    }

    /**
     * User Basic Auth.
     * Only use when over SSL/TSL/HTTPS.
     *
     * @return \Httpful\Request
     */
    public function basicAuth(string $username, string $password): self
    {
        $this->username = $username;
        $this->password = $password;

        return $this;
    }
    // @alias of basicAuth
    public function authenticateWith($username, $password)
    {
        return $this->basicAuth($username, $password);
    }
    // @alias of basicAuth
    public function authenticateWithBasic($username, $password)
    {
        return $this->basicAuth($username, $password);
    }

    // @alias of ntlmAuth
    public function authenticateWithNTLM($username, $password)
    {
        return $this->ntlmAuth($username, $password);
    }

    public function ntlmAuth($username, $password)
    {
        $this->addOnCurlOption(CURLOPT_HTTPAUTH, CURLAUTH_NTLM);

        return $this->basicAuth($username, $password);
    }

    /**
     * User Digest Auth.
     *
     * @return \Httpful\Request
     */
    public function digestAuth(string $username, string $password): self
    {
        $this->addOnCurlOption(CURLOPT_HTTPAUTH, CURLAUTH_DIGEST);

        return $this->basicAuth($username, $password);
    }

    // @alias of digestAuth
    public function authenticateWithDigest($username, $password)
    {
        return $this->digestAuth($username, $password);
    }

    /** @return bool is this request setup for client side cert? */
    public function hasClientSideCert(): bool
    {
        return isset($this->client_cert) && isset($this->client_key);
    }

    /**
     * Use Client Side Cert Authentication
     *
     * @param string $key file path to client key
     * @param string $cert file path to client cert
     * @param string $passphrase for client key
     * @param string $encoding default PEM
     * @return \Httpful\Request
     */
    public function clientSideCert(
        string $cert,
        string $key,
        ?string $passphrase = null,
        string $encoding = 'PEM'
    ): self {
        $this->client_cert = $cert;
        $this->client_key = $key;
        $this->client_passphrase = $passphrase;
        $this->client_encoding = $encoding;

        return $this;
    }
    // @alias of basicAuth
    public function authenticateWithCert($cert, $key, $passphrase = null, $encoding = 'PEM')
    {
        return $this->clientSideCert($cert, $key, $passphrase, $encoding);
    }

    /**
     * Set the body of the request
     *
     * @param string $mimeType currently, sets the sends AND expects mime type although this
     *    behavior may change in the next minor release (as it is a potential breaking change).
     * @return \Httpful\Request
     */
    public function body(mixed $payload, ?string $mimeType = null): self
    {
        $this->mime($mimeType);
        $this->payload = $payload;

        // Iserntentially don't call _serializePayload yet.  Wait until
        // we actually send off the request to convert payload to string.
        // At that time, the `serialized_payload` is set accordingly.
        return $this;
    }

    /**
     * Helper function to set the Content type and Expected as same in
     * one swoop
     *
     * @param string $mime mime type to use for content type and expected return type
     * @return \Httpful\Request
     */
    public function mime(string $mime): self
    {
        if (empty($mime)) {
            return $this;
        }

        $this->content_type = $this->expected_type = Mime::getFullMime($mime);

        if ($this->isUpload()) {
            $this->neverSerializePayload();
        }

        return $this;
    }
    // @alias of mime
    public function sendsAndExpectsType($mime)
    {
        return $this->mime($mime);
    }
    // @alias of mime
    public function sendsAndExpects($mime)
    {
        return $this->mime($mime);
    }

    /**
     * Set the method. Shouldn't be called often as the preferred syntax
     * for instantiation is the method specific factory methods.
     *
     * @return \Httpful\Request
     */
    public function method(string $method): self
    {
        if (empty($method)) {
            return $this;
        }

        $this->method = $method;

        return $this;
    }

    /** @return \Httpful\Request */
    public function expects(string $mime): self
    {
        if (empty($mime)) {
            return $this;
        }

        $this->expected_type = Mime::getFullMime($mime);

        return $this;
    }
    // @alias of expects
    public function expectsType($mime)
    {
        return $this->expects($mime);
    }

    public function attach($files)
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        foreach ($files as $key => $file) {
            $mimeType = finfo_file($finfo, $file);

            if (function_exists('curl_file_create')) {
                $this->payload[$key] = curl_file_create($file, $mimeType);
            } else {
                $this->payload[$key] = '@' . $file;

                if ($mimeType) {
                    $this->payload[$key] .= ';type=' . $mimeType;
                }
            }
        }

        $this->sendsType(Mime::UPLOAD);

        return $this;
    }

    /** @return \Httpful\Request */
    public function contentType(string $mime): self
    {
        if (empty($mime)) {
            return $this;
        }

        $this->content_type = Mime::getFullMime($mime);

        if ($this->isUpload()) {
            $this->neverSerializePayload();
        }

        return $this;
    }
    // @alias of contentType
    public function sends($mime)
    {
        return $this->contentType($mime);
    }
    // @alias of contentType
    public function sendsType($mime)
    {
        return $this->contentType($mime);
    }

    /**
     * Do we strictly enforce SSL verification?
     *
     * @return \Httpful\Request
     */
    public function strictSSL(bool $strict): self
    {
        $this->strict_ssl = $strict;

        return $this;
    }

    public function withoutStrictSSL()
    {
        return $this->strictSSL(false);
    }

    public function withStrictSSL()
    {
        return $this->strictSSL(true);
    }

    /**
     * Use proxy configuration
     *
     * @param string $proxy_host Hostname or address of the proxy
     * @param int $proxy_port Port of the proxy. Default 80
     * @param string $auth_type Authentication type or null. Accepted values are CURLAUTH_BASIC, CURLAUTH_NTLM. Default null, no authentication
     * @param string $auth_username Authentication username. Default null
     * @param string $auth_password Authentication password. Default null
     * @return \Httpful\Request
     */
    public function useProxy(
        string $proxy_host,
        int $proxy_port = 80,
        ?string $auth_type = null,
        ?string $auth_username = null,
        ?string $auth_password = null,
        $proxy_type = Proxy::HTTP
    ): self {
        $this->addOnCurlOption(CURLOPT_PROXY, "{$proxy_host}:{$proxy_port}");
        $this->addOnCurlOption(CURLOPT_PROXYTYPE, $proxy_type);

        if (in_array($auth_type, array(CURLAUTH_BASIC, CURLAUTH_NTLM))) {
            $this->addOnCurlOption(CURLOPT_PROXYAUTH, $auth_type)
                ->addOnCurlOption(CURLOPT_PROXYUSERPWD, "{$auth_username}:{$auth_password}");
        }

        return $this;
    }

    /**
     * Shortcut for useProxy to configure SOCKS 4 proxy
     *
     * @see Request::useProxy
     * @return \Httpful\Request
     */
    public function useSocks4Proxy(
        $proxy_host,
        $proxy_port = 80,
        $auth_type = null,
        $auth_username = null,
        $auth_password = null
    ): self {
        return $this->useProxy($proxy_host, $proxy_port, $auth_type, $auth_username, $auth_password, Proxy::SOCKS4);
    }

    /**
     * Shortcut for useProxy to configure SOCKS 5 proxy
     *
     * @see Request::useProxy
     * @return \Httpful\Request
     */
    public function useSocks5Proxy(
        $proxy_host,
        $proxy_port = 80,
        $auth_type = null,
        $auth_username = null,
        $auth_password = null
    ): self {
        return $this->useProxy($proxy_host, $proxy_port, $auth_type, $auth_username, $auth_password, Proxy::SOCKS5);
    }

    /** @return bool is this request setup for using proxy? */
    public function hasProxy(): bool
    {
        /* We must be aware that proxy variables could come from environment also.
           In curl extension, http proxy can be specified not only via CURLOPT_PROXY option,
           but also by environment variable called http_proxy.
        */
        return isset($this->additional_curl_opts[CURLOPT_PROXY]) && is_string(
            $this->additional_curl_opts[CURLOPT_PROXY]
        ) ||
            getenv("http_proxy");
    }

    /**
     * Determine how/if we use the built in serialization by
     * setting the serialize_payload_method
     * The default (SERIALIZE_PAYLOAD_SMART) is...
     *  - if payload is not a scalar (object/array)
     *    use the appropriate serialize method according to
     *    the Content-Type of this request.
     *  - if the payload IS a scalar (int, float, string, bool)
     *    than just return it as is.
     * When this option is set SERIALIZE_PAYLOAD_ALWAYS,
     * it will always use the appropriate
     * serialize option regardless of whether payload is scalar or not
     * When this option is set SERIALIZE_PAYLOAD_NEVER,
     * it will never use any of the serialization methods.
     * Really the only use for this is if you want the serialize methods
     * to handle strings or not (e.g. Blah is not valid JSON, but "Blah"
     * is). Forcing the serialization helps prevent that kind of error from
     * happening.
     *
     * @return \Httpful\Request
     */
    public function serializePayload(int $mode): self
    {
        $this->serialize_payload_method = $mode;

        return $this;
    }

    /**
     * @see Request::serializePayload()
     * @return \Httpful\Request
     */
    public function neverSerializePayload(): self
    {
        return $this->serializePayload(self::SERIALIZE_PAYLOAD_NEVER);
    }

    /**
     * This method is the default behavior
     *
     * @see Request::serializePayload()
     * @return \Httpful\Request
     */
    public function smartSerializePayload(): self
    {
        return $this->serializePayload(self::SERIALIZE_PAYLOAD_SMART);
    }

    /**
     * @see Request::serializePayload()
     * @return \Httpful\Request
     */
    public function alwaysSerializePayload(): self
    {
        return $this->serializePayload(self::SERIALIZE_PAYLOAD_ALWAYS);
    }

    /**
     * Add an additional header to the request
     * Can also use the cleaner syntax of
     * $Request->withMyHeaderName($my_value);
     *
     * @see Request::__call()
     * @return \Httpful\Request
     */
    public function addHeader(string $header_name, string $value): self
    {
        $this->headers[$header_name] = $value;

        return $this;
    }

    /**
     * Add group of headers all at once. Note: This is
     * here just as a convenience in very specific cases.
     * The preferred "readable" way would be to leverage
     * the support for custom header methods.
     *
     * @param array $headers
     * @return \Httpful\Request
     */
    public function addHeaders(array $headers): self
    {
        foreach ($headers as $header => $value) {
            $this->addHeader($header, $value);
        }

        return $this;
    }

    /**
     * @param bool $auto_parse perform automatic "smart"
     *    parsing based on Content-Type or "expectedType"
     *    If not auto parsing, Response->body returns the body
     *    as a string.
     * @return \Httpful\Request
     */
    public function autoParse(bool $auto_parse = true): self
    {
        $this->auto_parse = $auto_parse;

        return $this;
    }

    /**
     * @see Request::autoParse()
     * @return \Httpful\Request
     */
    public function withoutAutoParsing(): self
    {
        return $this->autoParse(false);
    }

    /**
     * @see Request::autoParse()
     * @return \Httpful\Request
     */
    public function withAutoParsing(): self
    {
        return $this->autoParse(true);
    }

    /**
     * Use a custom function to parse the response.
     *
     * @param \Closure $callback Takes the raw body of
     *    the http response and returns a mixed
     * @return \Httpful\Request
     */
    public function parseWith(Closure $callback): self
    {
        $this->parse_callback = $callback;

        return $this;
    }

    /**
     * @see Request::parseResponsesWith()
     * @return \Httpful\Request
     */
    public function parseResponsesWith(Closure $callback): self
    {
        return $this->parseWith($callback);
    }

    /**
     * Callback called to handle HTTP errors. When nothing is set, defaults
     * to logging via `error_log`
     *
     * @param \Closure $callback (string $error)
     * @return \Httpful\Request
     */
    public function whenError(Closure $callback): self
    {
        $this->error_callback = $callback;

        return $this;
    }

    /**
     * Callback invoked after payload has been serialized but before
     * the request has been built.
     *
     * @param \Closure $callback (Request $request)
     * @return \Httpful\Request
     */
    public function beforeSend(Closure $callback): self
    {
        $this->send_callback = $callback;

        return $this;
    }

    /**
     * Register a callback that will be used to serialize the payload
     * for a particular mime type. When using "*" for the mime
     * type, it will use that parser for all responses regardless of the mime
     * type. If a custom '*' and 'application/json' exist, the custom
     * 'application/json' would take precedence over the '*' callback.
     *
     * @param string $mime mime type we're registering
     * @param \Closure $callback takes one argument, $payload,
     *    which is the payload that we'll be
     * @return \Httpful\Request
     */
    public function registerPayloadSerializer(string $mime, Closure $callback): self
    {
        $this->payload_serializers[Mime::getFullMime($mime)] = $callback;

        return $this;
    }

    /**
     * @see Request::registerPayloadSerializer()
     * @return \Httpful\Request
     */
    public function serializePayloadWith(Closure $callback): self
    {
        return $this->registerPayloadSerializer('*', $callback);
    }

    /**
     * Does the heavy lifting. Uses de facto HTTP
     * library cURL to set up the HTTP request.
     * Note: It does NOT actually send the request
     *
     * @return \Httpful\Request
     * @throws \Exception
     */
    public function _curlPrep(): self
    {
        // Check for required stuff
        if (!isset($this->uri)) {
            throw new Exception('Attempting to send a request before defining a URI endpoint.');
        }

        if (isset($this->payload)) {
            $this->serialized_payload = $this->_serializePayload($this->payload);
        }

        if (isset($this->send_callback)) {
            call_user_func($this->send_callback, $this);
        }

        $ch = curl_init($this->uri);

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $this->method);

        if ($this->method === Http::HEAD) {
            curl_setopt($ch, CURLOPT_NOBODY, true);
        }

        if ($this->hasBasicAuth()) {
            curl_setopt($ch, CURLOPT_USERPWD, $this->username . ':' . $this->password);
        }

        if ($this->hasClientSideCert()) {

            if (!file_exists($this->client_key)) {
                throw new Exception('Could not read Client Key');
            }

            if (!file_exists($this->client_cert)) {
                throw new Exception('Could not read Client Certificate');
            }

            curl_setopt($ch, CURLOPT_SSLCERTTYPE, $this->client_encoding);
            curl_setopt($ch, CURLOPT_SSLKEYTYPE, $this->client_encoding);
            curl_setopt($ch, CURLOPT_SSLCERT, $this->client_cert);
            curl_setopt($ch, CURLOPT_SSLKEY, $this->client_key);
            curl_setopt($ch, CURLOPT_SSLKEYPASSWD, $this->client_passphrase);
            // curl_setopt($ch, CURLOPT_SSLCERTPASSWD,  $this->client_cert_passphrase);
        }

        if ($this->hasTimeout()) {
            if (defined('CURLOPT_TIMEOUT_MS')) {
                curl_setopt($ch, CURLOPT_TIMEOUT_MS, $this->timeout * 1000);
            } else {
                curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
            }
        }

        if ($this->follow_redirects) {
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS, $this->max_redirects);
        }

        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $this->strict_ssl);
        // zero is safe for all curl versions
        $verifyValue = $this->strict_ssl + 0;

        //Support for value 1 removed in cURL 7.28.1 value 2 valid in all versions
        if ($verifyValue > 0) {
            $verifyValue++;
        }

        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $verifyValue);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        // https://github.com/nategood/httpful/issues/84
        // set Content-Length to the size of the payload if present
        if (isset($this->payload)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $this->serialized_payload);

            if (!$this->isUpload()) {
                $this->headers['Content-Length'] =
                    $this->_determineLength($this->serialized_payload);
            }
        }

        $headers = array();
        // https://github.com/nategood/httpful/issues/37
        // Except header removes any HTTP 1.1 Continue from response headers
        $headers[] = 'Expect:';

        if (!isset($this->headers['User-Agent'])) {
            $headers[] = $this->buildUserAgent();
        }

        $headers[] = "Content-Type: {$this->content_type}";

        // allow custom Accept header if set
        if (!isset($this->headers['Accept'])) {
            // http://pretty-rfc.herokuapp.com/RFC2616#header.accept
            $accept = 'Accept: */*; q=0.5, text/plain; q=0.8, text/html;level=3;';

            if (!empty($this->expected_type)) {
                $accept .= "q=0.9, {$this->expected_type}";
            }

            $headers[] = $accept;
        }

        // Solve a bug on squid proxy, NONE/411 when miss content length
        if (!isset($this->headers['Content-Length']) && !$this->isUpload()) {
            $this->headers['Content-Length'] = 0;
        }

        foreach ($this->headers as $header => $value) {
            $headers[] = "$header: $value";
        }

        $url = parse_url($this->uri);
        $path = ($url['path'] ?? '/') . (isset($url['query']) ? '?' . $url['query'] : '');
        $this->raw_headers = "{$this->method} $path HTTP/1.1\r\n";
        $host = ($url['host'] ?? 'localhost') . (isset($url['port']) ? ':' . $url['port'] : '');
        $this->raw_headers .= "Host: $host\r\n";
        $this->raw_headers .= implode("\r\n", $headers);
        $this->raw_headers .= "\r\n";

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($this->_debug) {
            curl_setopt($ch, CURLOPT_VERBOSE, true);
        }

        curl_setopt($ch, CURLOPT_HEADER, 1);

        // If there are some additional curl opts that the user wants
        // to set, we can tack them in here
        foreach ($this->additional_curl_opts as $curlopt => $curlval) {
            curl_setopt($ch, $curlopt, $curlval);
        }

        $this->_ch = $ch;

        return $this;
    }

    /**
     * @param string $str payload
     * @return int length of payload in bytes
     */
    public function _determineLength(string $str): int
    {
        return function_exists('mb_strlen')
            ? mb_strlen($str, '8bit')
            : strlen($str);
    }

    public function isUpload(): bool
    {
        return $this->content_type === Mime::UPLOAD;
    }

    public function buildUserAgent(): string
    {
        $user_agent = 'User-Agent: Httpful/' . Httpful::VERSION . ' (cURL/';
        $curl = curl_version();

        if (isset($curl['version'])) {
            $user_agent .= $curl['version'];
        } else {
            $user_agent .= '?.?.?';
        }

        $user_agent .= ' PHP/' . PHP_VERSION . ' (' . PHP_OS . ')';

        if (isset($_SERVER['SERVER_SOFTWARE'])) {
            $user_agent .= ' ' . preg_replace(
                '~PHP/[\d\.]+~U',
                '',
                $_SERVER['SERVER_SOFTWARE']
            );
        } else {
            if (isset($_SERVER['TERM_PROGRAM'])) {
                $user_agent .= " {$_SERVER['TERM_PROGRAM']}";
            }

            if (isset($_SERVER['TERM_PROGRAM_VERSION'])) {
                $user_agent .= "/{$_SERVER['TERM_PROGRAM_VERSION']}";
            }
        }

        if (isset($_SERVER['HTTP_USER_AGENT'])) {
            $user_agent .= " {$_SERVER['HTTP_USER_AGENT']}";
        }

        $user_agent .= ')';

        return $user_agent;
    }

    /**
     * Takes a curl result and generates a Response from it
     */
    public function buildResponse($result): Response
    {
        if ($result === false) {
            if ($curlErrorNumber = curl_errno($this->_ch)) {
                $curlErrorString = curl_error($this->_ch);
                $this->_error($curlErrorString);

                $exception = new ConnectionErrorException('Unable to connect to "' . $this->uri . '": '
                        . $curlErrorNumber . ' ' . $curlErrorString);

                $exception->setCurlErrorNumber($curlErrorNumber)
                    ->setCurlErrorString($curlErrorString);

                throw $exception;
            }

            $this->_error('Unable to connect to "' . $this->uri . '".');

            throw new ConnectionErrorException('Unable to connect to "' . $this->uri . '".');
        }

        $info = curl_getinfo($this->_ch);

        // Remove the "HTTP/1.x 200 Connection established" string and any other headers added by proxy
        $proxy_regex = "/HTTP\/1\.[01] 200 Connection established.*?\r\n\r\n/si";

        if ($this->hasProxy() && preg_match($proxy_regex, $result)) {
            $result = preg_replace($proxy_regex, '', $result);
        }

        $response = explode("\r\n\r\n", $result, 2 + $info['redirect_count']);

        $body = array_pop($response);
        $headers = array_pop($response);

        return new Response($body, $headers, $this, $info);
    }

    /**
     * Semi-reluctantly added this as a way to add in curl opts
     * that are not otherwise accessible from the rest of the API.
     *
     * @return \Httpful\Request
     */
    public function addOnCurlOption(string $curlopt, mixed $curloptval): self
    {
        $this->additional_curl_opts[$curlopt] = $curloptval;

        return $this;
    }

    /**
     * Let's you configure default settings for this
     * class from a template Request object. Simply construct a
     * Request object as much as you want to and then pass it to
     * this method. It will then lock in those settings from
     * that template object.
     * The most common of which may be default mime
     * settings or strict ssl settings.
     * Again some slight memory overhead incurred here but in the grand
     * scheme of things as it typically only occurs once
     *
     * @param \Httpful\Request $template
     */
    public static function ini(self $template): void
    {
        self::$_template = clone $template;
    }

    /**
     * Reset the default template back to the
     * library defaults.
     */
    public static function resetIni(): void
    {
        self::_initializeDefaults();
    }

    /**
     * Get default for a value based on the template object
     *
     * @param string|null $attr Name of attribute (e.g. mime, headers)
     *    if null just return the whole template object;
     * @return mixed default value
     */
    public static function d(?string $attr): mixed
    {
        return isset($attr)
            ? self::$_template->$attr
            : self::$_template;
    }// Accessors


    /**
     * Like Request:::get, except that it sends off the request as well
     * returning a response
     *
     * @param string $uri optional uri to use
     * @param string $mime expected
     */
    public static function getQuick(string $uri, ?string $mime = null): Response
    {
        return self::get($uri, $mime)->send();
    }

    /**
     * Set the defaults on a newly instantiated object
     * Doesn't copy variables prefixed with _
     *
     * @return \Httpful\Request
     */
    private function _setDefaults(): self
    {
        if (!isset(self::$_template)) {
            self::_initializeDefaults();
        }

        foreach (self::$_template as $k => $v) {
            if ($k[0] !== '_') {
                $this->$k = $v;
            }
        }

        return $this;
    }

    private function _error($error): void
    {
        // TODO add in support for various Loggers that follow
        // PSR 3 https://github.com/php-fig/fig-standards/blob/master/accepted/PSR-3-logger-interface.md
        if (isset($this->error_callback)) {
            $this->error_callback($error);
        } else {
            error_log($error);
        }
    }

    /**
     * Turn payload from structured data into
     * a string based on the current Mime type.
     * This uses the auto_serialize option to determine
     * it's course of action. See serialize method for more.
     * Renamed from _detectPayload to _serializePayload as of
     * 2012-02-15.
     *
     * Added in support for custom payload serializers.
     * The serialize_payload_method stuff still holds true though.
     *
     * @see Request::registerPayloadSerializer()
     */
    private function _serializePayload(mixed $payload): string
    {
        if (empty($payload) || $this->serialize_payload_method === self::SERIALIZE_PAYLOAD_NEVER) {
            return $payload;
        }

        // When we are in "smart" mode, don't serialize strings/scalars, assume they are already serialized
        if ($this->serialize_payload_method === self::SERIALIZE_PAYLOAD_SMART && is_scalar($payload)) {
            return $payload;
        }

        // Use a custom serializer if one is registered for this mime type
        if (isset($this->payload_serializers['*']) || isset($this->payload_serializers[$this->content_type])) {
            $key = isset($this->payload_serializers[$this->content_type])
                ? $this->content_type
                : '*';

            return call_user_func($this->payload_serializers[$key], $payload);
        }

        return Httpful::get($this->content_type)->serialize($payload);
    }

    /**
     * This is the default template to use if no
     * template has been provided. The template
     * tells the class which default values to use.
     * While there is a slight overhead for object
     * creation once per execution (not once per
     * Request instantiation), it promotes readability
     * and flexibility within the class.
     */
    private static function _initializeDefaults(): void
    {
        // This is the only place you will
        // see this constructor syntax.  It
        // is only done here to prevent infinite
        // recusion.  Do not use this syntax elsewhere.
        // It goes against the whole readability
        // and transparency idea.
        self::$_template = new self(array('method' => Http::GET));

        // This is more like it...
        self::$_template
            ->withoutStrictSSL();
    }

    /**
     * Magic method allows for neatly setting other headers in a
     * similar syntax as the other setters. This method also allows
     * for the sends* syntax.
     *
     * @param string $method "missing" method name called
     *    the method name called should be the name of the header that you
     *    are trying to set in camel case without dashes e.g. to set a
     *    header for Content-Type you would use contentType() or more commonly
     *    to add a custom header like X-My-Header, you would use xMyHeader().
     *    To promote readability, you can optionally prefix these methods with
     *    "with" (e.g. withXMyHeader("blah") instead of xMyHeader("blah")).
     * @param array $args in this case, there should only ever be 1 argument provided
     *    and that argument should be a string value of the header we're setting
     * @return \Httpful\Request
     */
    public function __call(string $method, array $args): self
    {
        // This method supports the sends* methods
        // like sendsJSON, sendsForm
        //!method_exists($this, $method) &&
        if (substr($method, 0, 5) === 'sends') {
            $mime = strtolower(substr($method, 5));

            if (Mime::supportsMimeType($mime)) {
                $this->sends(Mime::getFullMime($mime));

                return $this;
            }

            // else {
            //     throw new \Exception("Unsupported Content-Type $mime");
            // }
        }

        if (substr($method, 0, 7) === 'expects') {
            $mime = strtolower(substr($method, 7));

            if (Mime::supportsMimeType($mime)) {
                $this->expects(Mime::getFullMime($mime));

                return $this;
            }

            // else {
            //     throw new \Exception("Unsupported Content-Type $mime");
            // }
        }

        // This method also adds the custom header support as described in the
        // method comments
        if (count($args) === 0) {
            return $this;
        }

        // Strip the sugar.  If it leads with "with", strip.
        // This is okay because: No defined HTTP headers begin with with,
        // and if you are defining a custom header, the standard is to prefix it
        // with an "X-", so that should take care of any collisions.
        if (substr($method, 0, 4) === 'with') {
            $method = substr($method, 4);
        }

        // Precede upper case letters with dashes, uppercase the first letter of method
        $header = ucwords(
            implode('-', preg_split('/([A-Z][^A-Z]*)/', $method, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY))
        );
        $this->addHeader($header, $args[0]);

        return $this;
    }// Internal Functions
}

<?php

namespace Tier;

use Room11\HTTP\Request;
use Room11\HTTP\Request\Request as RequestImpl;
use Room11\HTTP\Response;

use Jig\Jig;
use Jig\JigBase;
use Room11\HTTP\Body\HtmlBody;


function createRequestFromGlobals()
{
    try {
        $_input = empty($_SERVER['CONTENT-LENGTH']) ? null : fopen('php://input', 'r');
        $request = new RequestImpl($_SERVER, $_GET, $_POST, $_FILES, $_COOKIE, $_input);
    }
    catch (\Exception $e) {
        //TODO - exit quickly.
        header("We totally failed", true, 501);
        echo "we ded ".$e->getMessage();
        exit(0);
    }

    return $request;
}


function setupErrorHandlers(){
    register_shutdown_function('Tier\tierShutdownFunction');
    set_exception_handler('Tier\tierExceptionHandler');
    set_error_handler('Tier\tierErrorHandler');
}

/**
 *
 */
function tierErrorHandler($errno, $errstr, $errfile, $errline)
{
    if (error_reporting() == 0) {
        return true;
    }
    if ($errno == E_DEPRECATED) {
        return true; //Don't care - deprecated warnings are generally not useful
    }
    
    if ($errno == E_CORE_ERROR || $errno == E_ERROR) {
        //$message = "Fatal error: [$errno] $errstr on line $errline in file $errfile <br />\n";
        return false;
    }

    $message = "Error: [$errno] $errstr in file $errfile on line $errline<br />\n";
    throw new \Exception($message);
}


/**
 * Parse errors cannot be handled inside the same file where they originate.
 * For this reason we have to include the application file externally here
 * so that our shutdown function can handle E_PARSE.
 */
function tierShutdownFunction()
{
    $fatals = [
        E_ERROR,
        E_PARSE,
        E_USER_ERROR,
        E_CORE_ERROR,
        E_CORE_WARNING,
        E_COMPILE_ERROR,
        E_COMPILE_WARNING
    ];

    $lastError = error_get_last();

    if ($lastError && in_array($lastError['type'], $fatals)) {
        if (headers_sent()) {
            return;
        }

        header_remove();
        header("HTTP/1.0 500 Internal Server Error");

        //if (DEBUG) {
        extract($lastError);
        $msg = sprintf("Fatal error: %s in %s on line %d", $message, $file, $line);
//        } else {
//            $msg = "Oops! Something went terribly wrong :(";
//        }

        $msg = "<pre style=\"color:red;\">{$msg}</pre>";

        echo "<html><body><h1>500 Internal Server Error</h1><hr/>{$msg}</body></html>";
    }
}


/**
 * @param Request $request
 * @param $body
 * @param $errorCode
 */
function sendErrorResponse(Request $request, $body, $errorCode)
{
    $response = new \Room11\HTTP\Response\Response();
    $response->setBody($body);
    $response->setStatus($errorCode);
    sendResponse($request, $response);
}

/**
 * @param Request $request
 * @param Response $response
 * @param bool $autoAddReason
 */
function sendResponse(Request $request, Response $response, $autoAddReason = true)
{
    $statusCode = $response->getStatus();
    $reason = $response->getReasonPhrase();
    if ($autoAddReason && empty($reason)) {
        $reasonConstant = "Arya\\Reason::HTTP_{$statusCode}";
        $reason = defined($reasonConstant) ? constant($reasonConstant) : '';
        $response->setReasonPhrase($reason);
    }
    
    if ($response->hasHeader('Date') == false) {
         $response->addHeader("Date", gmdate("D, d M Y H:i:s", time())." UTC");
    }

    $statusLine = sprintf("HTTP/%s %s", $request['SERVER_PROTOCOL'], $statusCode);
    if (isset($reason[0])) {
        $statusLine .= " {$reason}";
    }

    header($statusLine);

    $headers = $response->getAllHeaderLines();

    foreach ($headers as $headerLine) {
        header($headerLine, $replace = false);
    }

    flush(); // Force header output

    $body = $response->getBody();

    if (method_exists($body, '__toString')) {
        echo $body->__toString();
    }
    else if (is_string($body)) {
        echo $body;
    }
    elseif (is_callable($body)) {
        $body();
    }
    else {
        //this is bad.
    }
}

/**
 * @param $result
 * @throws TierException
 */
function throwWrongTypeException($result)
{
    if ($result === null) {
        throw new TierException('Return value of tier must be either a Room11\HTTP\Body or a tier, null given.');
    }

    if (is_object($result)) {
        $detail = "object of type '".get_class($result)."' returned.";
    }
    else {
        $detail = "variable of type '".gettype($result)."' returned.";
    }

    $message = sprintf(
        'Return value of tier must be either a Room11\HTTP\Body or a tier, instead %s returned.',
        $detail
    );

    throw new TierException($message);
}


/**
 * Helper function to allow template rendering to be easier.
 * @param $templateName
 * @param array $sharedObjects
 * @return Tier
 */
function getRenderTemplateTier($templateName, array $sharedObjects = [])
{
    $fn = function (Jig $jigRender) use ($templateName, $sharedObjects) {
        $className = $jigRender->getTemplateCompiledClassname($templateName);
        $jigRender->checkTemplateCompiled($templateName);

        $alias = [];
        $alias['Jig\JigBase'] = $className;
        $injectionParams = new InjectionParams($sharedObjects, $alias, [], []);

        return new Tier('Tier\createHtmlBody', $injectionParams);
    };

    return new Tier($fn);
}


/**
 * @param JigBase $template
 * @return HtmlBody
 * @throws \Exception
 * @throws \Jig\JigException
 */
function createHtmlBody(JigBase $template)
{
    $text = $template->render();

    return new HtmlBody($text);
}
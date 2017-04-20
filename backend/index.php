<?php
require_once 'vendor/autoload.php';

ini_set('max_execution_time', 0);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

define('BASE_URL', '/api');
define('APP_PATH', __DIR__);

$klein = new \Klein\Klein();

$klein->respond(function ($request, $response, $service, $app) use ($klein) {
    $app->register('config', function() use ($service) {
        return Config::getInstance();
    });

    $app->register('redis', function() use ($service) {
        return PredisWrapper::wrap();
    });

    $service->addValidator('in', function ($element, $array) {
        return in_array($element, $array);
    });

    $service->addValidator('fasta', function ($element) {
        return true;
    });

    $service->addValidator('eachIn', function ($elements, $array) {
        if ($elements == null)
            return false;

        foreach ($elements as $element) {
            if (!in_array($element, $array)) {
                return false;
            }
        }
        return true;
    });

    $service->addValidator('positive', function ($element) {
        return ((float) $element) >= 0;
    });

    $service->addValidator('uuid', function ($uuid) {
        $UUIDv4 = '/^[0-9A-F]{8}-[0-9A-F]{4}-4[0-9A-F]{3}-[89AB][0-9A-F]{3}-[0-9A-F]{12}$/i';
        return preg_match($UUIDv4, $uuid) === 1;
    });

    $klein->onHttpError(function ($code) use ($klein) {
        $klein->response()->body($code);

    });

    $klein->onError(function ($klein, $msg, $type, $err) {
        $klein->response()->json([ "status" => "ERROR", "code" => $type, "message" => $msg ]);
    });

    $response->header('Access-Control-Allow-Origin', '*');
});

$klein->respond('GET', '/', function($request, $response, $service, $app) {
    $response->body("Hello World!");
});

$klein->respond('GET', '/databases', function($request, $response, $service, $app) {
    $response->json(AvailableDatabases::getInstance());
});


$klein->respond('POST', '/ticket', function ($request, $response, $service, $app) {
    $service->validateParam('q')->fasta();
    $databases = array_column(AvailableDatabases::getInstance(), 'db');
    $service->validateParam('database')->eachIn($databases);
    $service->validateParam('mode')->in(['accept', 'summary']);

    $params = [
        "database" => $request->database,
        "mode" => $request->mode
    ];

    if (isset($request->email)) {
        $service->validateParam('email')->email();
        $params["email"] = $request->email;
    }

    $uuid = Uuid::generate();
    $result = [ "status" => "PENDING" ];
    if ((file_put_contents($app->config["workbase"] . "/" . $uuid . ".json", json_encode($params)) === FALSE)
        || (file_put_contents($app->config["workbase"] . "/" . $uuid . ".fasta", $request->q) === FALSE)) {
        $result["status"] = "ERROR";
    } else {
        $app->redis->transaction()
            ->zadd('mmseqs:pending', Priority::rank($params), $uuid)
            ->set('mmseqs:status:' . $uuid, '{ "status": "PENDING" }')
            ->execute();
        $result["ticket"] = $uuid;
    }

    $response->json($result);
});

$klein->respond('POST', '/tickets', function ($request, $response, $service, $app) {
    $tickets = [];
    foreach ($request->tickets as $ticket) {
        $service->validate($ticket)->uuid();
        $tickets[] = 'mmseqs:status:' . $ticket;
    }

    $response->json($app->redis->mget($tickets));
});

$klein->respond('GET', '/ticket/[:ticket]', function ($request, $response, $service, $app) {
    $service->validateParam('ticket')->uuid();
    $json = $app->redis->get('mmseqs:status:' . $request->ticket);
    $response->body($json);
});

$klein->respond('GET', '/result/queries/[:ticket]/[i:limit]/[i:page]', function ($request, $response, $service, $app) {
    $service->validateParam('ticket')->uuid();
    $service->validateParam('limit')->int()->positive();
    $service->validateParam('page')->int()->positive();

    $json = $app->redis->get('mmseqs:status:' . $request->ticket);

    $result = json_decode($json, true);
    if ($result['status'] == 'COMPLETED') {
        $base = $app->config["workbase"] . "/" . $request->ticket;
        $result['data'] = MMseqs\Lookup::read($base . '/input.lookup', $request->limit, $request->page);
    }
    $response->json($result);
});

$klein->respond('GET', '/result/[:ticket]/[:entry]', function ($request, $response, $service, $app) {
    $service->validateParam('ticket')->uuid();
    $service->validateParam('entry')->int()->positive();
    $json = $app->redis->get('mmseqs:status:' . $request->ticket);

    $result = json_decode($json, true);
    if ($result['status'] == 'COMPLETED') {
        $base = $app->config["workbase"] . "/" . $request->ticket;
        $alipath = $base . '/alis';
        $result['items'] = MMseqs\AlignmentResult::parseEntry($alipath, $request->entry);
        $msapath = $base . '/msa';
        $reader = new \IntDBReader($msapath, $msapath . ".index", 1);
        $result['msa'] = $reader->getData($request->entry);
        unset($reader);
    }
    $response->json($result);
});


$request = \Klein\Request::createFromGlobals();
$uri = $request->server()->get('REQUEST_URI');
$request->server()->set('REQUEST_URI', substr($uri, strlen(BASE_URL)));
$klein->dispatch($request);

<?php

require('../vendor/autoload.php');

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;

function postAsJSONToSlack($responseUrl, $data) {
	$streamOpts = [
		'http' => [
			'method'  => 'POST',
			'timeout' => '30',
			'header'  => join(PHP_EOL, [
				"Content-type: application/json",
			]) . PHP_EOL,
			'content' => json_encode($data)
		]
	];
	$jsonPosterStreamContext = stream_context_create($streamOpts);
	file_get_contents($responseUrl, null, $jsonPosterStreamContext);
}

function getAppBaseUrl($request)
{
    $url = $request->getSchemeAndHttpHost();
    $port80 = strpos($url, ':80');
    if ($port80 !== false) {
        $url = substr($url, 0, $port80);
    }
    return $url;
}

$app = new Silex\Application();
$app['debug'] = true;
$app['base_dir'] = __DIR__.'/..';

/** $app['conn'] \PDO */
$app['conn'] = require $app['base_dir'].'/db/connection.php';
$app['birkman_repository'] = new \BirkmanRepository($app['conn']);

// Register the monolog logging service
$app->register(new Silex\Provider\MonologServiceProvider(), array(
    'monolog.logfile' => 'php://stderr',
));

// Register view rendering
$app->register(new Silex\Provider\TwigServiceProvider(), array(
    'twig.path' => __DIR__.'/views',
));

// Our web handlers
$app->get('/', function() use($app) {
    return $app['twig']->render('index.twig');
});

$app->get('/grid', function(Request $request) use($app) {
    $birkmanId = $request->query->get('birkman_id');
    $birkmanData = $app['birkman_repository']->fetchByBirkmanId($birkmanId);
    $grid = new \BirkmanGrid($birkmanData['birkman_data']);
    ob_start();
    $grid->asPNG();
    $imageData = ob_get_contents();
    ob_end_clean();

    // respond to slack
    return new Response(
        $imageData,
        200,
        ['Content-Type' => 'image/png']
    );
});

$app->get('/alastairs-comparative-graph', function(Request $request) use($app) {
    $userABirkmanId = $request->query->get('birkman_id_a');
    $userBBirkmanId = $request->query->get('birkman_id_b');

    $birkman = new \BirkmanAPI(getenv('BIRKMAN_API_KEY'));
    $userABirkman = $app['birkman_repository']->fetchByBirkmanId($userABirkmanId);
    $userBBirkman = $app['birkman_repository']->fetchByBirkmanId($userBBirkmanId);
    $alastairsReportData = @$birkman->getAlastairsComparativeReport($userABirkman['birkman_data'], $userBBirkman['birkman_data']);

    // respond to slack
    return new Response(
        $alastairsReportData['graphImg'],
        200,
        ['Content-Type' => 'image/png']
    );
});

$app->get('/slack-slash-command/', function(Request $request) use($app) {
    $app['monolog']->addDebug('Requested Birkman GRID');
    $birkman = new \BirkmanAPI(getenv('BIRKMAN_API_KEY'));
    $componentLabels = $birkman->getComponentPrettyLabels();

    $slackToken = $request->query->get('token');

    if ($slackToken !== getenv('SLACK_TOKEN')) {
        $app->abort(403, "token does not match app's configured SLACK_TOKEN");
    }

    // grab url to post responses to
    $slack_response_url = $request->query->get('response_url');

    // Parse out the birkman slash command
    // /birkman GTW013 sjhdf skdfjh
    // apperas in API as
    // text=GTW013 sjhdf skdfjh
    $commandFromSlack = $request->query->get('text');
    // Clean up commandFromSlack
    $commandFromSlack = preg_replace('/\s+/', ' ', $commandFromSlack);
    list($birkmanCommand, $birkmanCommandArgsString) = explode(' ', $commandFromSlack, 2);
    $birkmanCommandArgs = array_filter(explode(' ', $birkmanCommandArgsString));

    try {
        switch ($birkmanCommand) {
        // show birkman grid
        case 'grid':
            // `grid [@slackusername]`
            // if slackusername is omitted, A will be the "current user"
            if (count($birkmanCommandArgs) == 0) {
                $slackUser = $request->query->get('user_name');
            } elseif (count($birkmanCommandArgs) == 1) {
                $slackUser = trim($birkmanCommandArgs[0], '@');
            } else {
                throw new \Exception("Expected 0 or 1 arguments, got " . count($birkmanCommandArgs));
            }
            $birkmanData = $app['birkman_repository']->fetchBySlackUsername($slackUser);
            $grid = new \BirkmanGrid($birkmanData['birkman_data']);
            ob_start();
            $grid->asPNG();
            $imageData = ob_get_contents();
            ob_end_clean();

            postAsJSONToSlack($slack_response_url, [
                'attachments' => [
                    [
                        "title"     => "Birkman Grid for {$birkmanData['birkman_data']['name']}",
                        "fallback"  => "Birkman Grid image",
                        "image_url" => getAppBaseUrl($request) . "/grid?birkman_id={$birkmanData['birkman_id']}"
                    ]
                ]
            ]);


            // respond to slack
            return new Response(
                '',
                Response::HTTP_OK
            );
            break;
        case 'compare':
            // `compare [@slackusernameA] @slackusernameB`
            // if slackusernameA is omitted, A will be the "current user"
            if (count($birkmanCommandArgs) == 1) {
                $slackUserA = $request->query->get('user_name');
                $slackUserB = trim($birkmanCommandArgs[0], '@');
            } elseif (count($birkmanCommandArgs) == 2) {
                $slackUserA = trim($birkmanCommandArgs[0], '@');
                $slackUserB = trim($birkmanCommandArgs[1], '@');
            } else {
                throw new \Exception("Expected 1 or 2 arguments, got " . count($birkmanCommandArgs));
            }

            // Translate slack username to birkman user ids
            $userABirkman = $app['birkman_repository']->fetchBySlackUsername($slackUserA);
            $userBBirkman = $app['birkman_repository']->fetchBySlackUsername($slackUserB);

            // run report
            $alastairsReportData = $birkman->getAlastairsComparativeReport($userABirkman['birkman_data'], $userBBirkman['birkman_data']);
            $graphUrl = getAppBaseUrl($request) . "/alastairs-comparative-graph?birkman_id_a={$userABirkman['birkman_data']}&birkman_id_b={$userBBirkman['birkman_data']}";

            $slackMessage = [];
            $slackMessage['attachments'][] = [
                        "title"     => "Your Usual Their Need: Components of Interest when {$userABirkman['birkman_data']['name']} is talking to {$userBBirkman['birkman_data']['name']}",
                        "fallback"  => "Graph of your usual vs their needs",
                        "image_url" => $graphUrl
                    ];
            foreach ($alastairsReportData['criticalComponents'] as $component) {
                $slackMessage['attachments'][] = [
                    "pretext"   => $componentLabels[$component['component']],
                    "fields"    => [
                        [
                            "title" => "Difference",
                            "value" => $component['diff'],
                            "short" => true
                        ],
                        [
                            "title" => "Your Usual ({$component['yourUsual']})",
                            "value" => $component['yourUsualExplanation'],
                            "short" => false
                        ],
                        [
                            "title" => "Their Need ({$component['theirNeed']})",
                            "value" => $component['theirNeedExplanation'],
                            "short" => false
                        ],
                    ]
                ];
            }

            postAsJSONToSlack($slack_response_url, $slackMessage);

            // respond to slack
            return new Response(
                '',
                Response::HTTP_OK
            );
            break;
        default:
            throw new \RecordNotFoundException("{$birkmanCommand} does not exist.");
        }
    } catch (\Exception $e) {
        $app['monolog']->addDebug($e->getMessage());
        die($e->getMessage);
        // Return a response instead of blowing up.
        return new Response(
            $e->getMessage(),
            Response::HTTP_NOT_FOUND
        );
    }
});

$app->match('/admin/users', function(Silex\Application $app, \Symfony\Component\HttpFoundation\Request $request) {
    /** @var BirkmanRepository $birkmanRepository */
    $birkmanRepository = $app['birkman_repository'];
    $birkmanAPI = new BirkmanAPI(getenv('BIRKMAN_API_KEY'));

    $users = $birkmanRepository->fetchAll();

    if ($request->getMethod() === 'POST') {
        if ($request->request->has('insert')) {
            $birkmanId = $request->request->get('birkman_id');
            $birkmanRepository->createUser(
                $birkmanId,
                $request->request->get('slack_username')
            );
            $birkmanData = $birkmanAPI->getUserCoreData($birkmanId);
            $birkmanRepository->updateBirkmanData($birkmanId, json_encode($birkmanData));
        } elseif ($request->request->has('delete')) {
            $birkmanRepository->delete($request->request->get('birkman_id'));
        }

        return new \Symfony\Component\HttpFoundation\RedirectResponse('/admin/users');
    }

    return $app['twig']->render('admin_users.html.twig', ['users' => $users]);
})->method('GET|POST');

$app->run();

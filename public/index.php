<?php

use Bandwidth\Api\RecordingsApi;
use Bandwidth\Configuration;
use Bandwidth\Model\Bxml\PlayAudio;
use Bandwidth\Model\Bxml\Record;
use Bandwidth\Model\Bxml\Response as BxmlResponse;
use Bandwidth\Model\Bxml\SpeakSentence;
use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

require __DIR__ . '/../vendor/autoload.php';

$BW_ACCOUNT_ID = getenv('BW_ACCOUNT_ID');
$BW_CLIENT_ID = getenv('BW_CLIENT_ID');
$BW_CLIENT_SECRET = getenv('BW_CLIENT_SECRET');

$config = Configuration::getDefaultConfiguration()
    ->setClientId($BW_CLIENT_ID)
    ->setClientSecret($BW_CLIENT_SECRET);
$recordingsApi = new RecordingsApi(new Client(), $config);

$app = AppFactory::create();
$app->addErrorMiddleware(true, true, true);

function parseCallback(Request $request, string $expectedEventType): ?array
{
    $data = json_decode($request->getBody()->getContents(), true);
    if (!is_array($data) || ($data['eventType'] ?? null) !== $expectedEventType) {
        return null;
    }
    return $data;
}

$app->post('/callbacks/inbound/voice', function (Request $request, Response $response) {
    if (parseCallback($request, 'initiate') === null) {
        return $response->withStatus(400);
    }

    $bxml = new BxmlResponse([
        new SpeakSentence('You have reached Vandelay Industries, Kal Varnsen is unavailable at this time.'),
        new SpeakSentence('At the tone, please record your message, when you have finished recording, you may hang up.'),
        new PlayAudio('/tone.mp3'),
        new Record(recordingAvailableUrl: '/callbacks/inbound/voice/recordingAvailable', maxDuration: 30),
    ]);

    $response->getBody()->write($bxml->toBxml());
    return $response->withHeader('Content-Type', 'application/xml');
});

$app->get('/tone.mp3', function (Request $request, Response $response) {
    $response->getBody()->write(file_get_contents(__DIR__ . '/../tone.mp3'));
    return $response->withHeader('Content-Type', 'audio/mpeg');
});

$app->post('/callbacks/inbound/voice/recordingAvailable', function (Request $request, Response $response) use ($recordingsApi, $BW_ACCOUNT_ID) {
    $data = parseCallback($request, 'recordingAvailable');
    if ($data === null) {
        return $response->withStatus(400);
    }

    try {
        $recording = $recordingsApi->downloadCallRecording($BW_ACCOUNT_ID, $data['callId'], $data['recordingId']);
        copy($recording->getPathname(), "{$data['recordingId']}.{$data['fileFormat']}");
    } catch (Throwable $e) {
        error_log('Failed to download recording: ' . $e->getMessage());
        return $response->withStatus(500);
    }

    return $response->withStatus(204);
});

$app->run();
